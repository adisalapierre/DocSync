<?php
namespace MasCamp\GoogleDocsSync;
if (!defined('ABSPATH')) exit;

/**
 * MasCamp DocSync core — one-way WordPress → Google Docs / Git.
 *
 * Nothing syncs unless you choose it, per destination:
 *  - one-off: editor box button, Posts/Pages row link, bulk action, or Sync all;
 *  - "Keep synced" per post: updated automatically whenever that post is updated.
 */
final class Module {
    const TYPES        = ['post', 'page'];
    const PAGE         = 'mascamp-docsync';
    const ITEM_HOOK    = 'mascamp_docsync_item';   // args: [destination, post_id]
    const LEGACY_HOOK  = 'mascamp_docsync_post';   // v0.1.x Google Docs events still in the queue
    const BATCH_HOOK   = 'mascamp_docsync_batch';
    const BATCH_OPTION = 'mascamp_docsync_batch';
    const BATCH_LOCK   = 'mascamp_docsync_batch_lock';

    private static $instance;
    /** @var Destination[] */
    private $dests = [];

    public static function instance() { return self::$instance ?: (self::$instance = new self()); }

    private function __construct() {
        $this->dests = ['gdocs' => new GDocsDestination($this), 'git' => new GitDestination($this)];
        foreach ($this->dests as $d) $d->hooks();

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_mascamp_docsync_now', [$this, 'sync_now']);
        add_action('add_meta_boxes', [$this, 'add_metabox']);
        add_action('save_post', [$this, 'save_metabox'], 10, 2);
        add_action('save_post', [$this, 'queue_on_save'], 20, 2);
        add_action(self::ITEM_HOOK, [$this, 'cron_item'], 10, 2);
        add_action(self::LEGACY_HOOK, [$this, 'cron_legacy'], 10, 1);
        add_action('admin_notices', [$this, 'notices']);
        add_action('admin_post_mascamp_docsync_batch_start', [$this, 'batch_start']);
        add_action('admin_post_mascamp_docsync_batch_cancel', [$this, 'batch_cancel']);
        add_action('wp_ajax_mascamp_docsync_batch_step', [$this, 'batch_ajax_step']);
        add_action(self::BATCH_HOOK, [$this, 'batch_cron']);
        foreach (self::TYPES as $type) {
            add_filter("manage_{$type}_posts_columns", [$this, 'add_column']);
            add_action("manage_{$type}_posts_custom_column", [$this, 'render_column'], 10, 2);
            add_filter("bulk_actions-edit-{$type}", [$this, 'bulk_actions']);
            add_filter("handle_bulk_actions-edit-{$type}", [$this, 'handle_bulk'], 10, 3);
        }
        add_filter('post_row_actions', [$this, 'row_actions'], 10, 2);
        add_filter('page_row_actions', [$this, 'row_actions'], 10, 2);
    }

    /** @return Destination|null */
    public function dest($id) { return $this->dests[$id] ?? null; }
    /** @return Destination[] configured destinations */
    private function ready_dests() { return array_filter($this->dests, static function ($d) { return $d->is_ready(); }); }

    public function settings_url($tab = '') {
        return admin_url('options-general.php?page=' . self::PAGE . ($tab ? '&tab=' . $tab : ''));
    }

    /* ---------------------------------------------------------------- Settings page */

    public function admin_menu() {
        add_submenu_page('options-general.php', 'MasCamp DocSync', 'MasCamp DocSync', 'manage_options', self::PAGE, [$this, 'settings_page']);
    }

    public function register_settings() { foreach ($this->dests as $d) $d->register_settings(); }

    public function settings_page() {
        if (!current_user_can('manage_options')) return;
        $tabs = ['gdocs' => 'Google Docs', 'git' => 'Git', 'all' => 'Sync all', 'help' => 'How it works'];
        $tab = sanitize_key($_GET['tab'] ?? 'gdocs');
        if (!isset($tabs[$tab])) $tab = 'gdocs';
        echo '<div class="wrap"><h1>MasCamp DocSync</h1>';
        echo '<p>Sync chosen WordPress posts and pages to Google Docs and/or a Git repository. One-way: WordPress is the source of truth.</p>';
        echo '<nav class="nav-tab-wrapper" style="margin-bottom:16px">';
        foreach ($tabs as $k => $label) {
            $dot = '';
            if (isset($this->dests[$k])) $dot = $this->dests[$k]->is_ready() ? ' <span style="color:#008a20">●</span>' : ' <span style="color:#a7aaad">○</span>';
            echo '<a class="nav-tab' . ($k === $tab ? ' nav-tab-active' : '') . '" href="' . esc_url($this->settings_url($k)) . '">' . esc_html($label) . $dot . '</a>';
        }
        echo '</nav>';
        if (isset($this->dests[$tab])) $this->dests[$tab]->render_settings();
        elseif ($tab === 'all') $this->render_batch_section();
        else $this->render_help();
        echo '</div>';
    }

    private function render_help() {
        echo '<h2>Choosing what syncs</h2><p>Nothing syncs unless you choose it. Google Docs and Git are separate: you choose each one per post.</p><ul style="list-style:disc;padding-left:20px">';
        echo '<li><strong>One post:</strong> open it and use the buttons in the <em>DocSync</em> box, or hover it in Posts/Pages and click <em>Sync to Google Docs</em> or <em>Sync to Git</em>.</li>';
        echo '<li><strong>Everything:</strong> use the <a href="' . esc_url($this->settings_url('all')) . '">Sync all</a> tab.</li>';
        echo '<li><strong>Several posts:</strong> tick them in Posts/Pages, pick a sync from Bulk actions, and click Apply.</li>';
        echo '<li><strong>Keep a post updated:</strong> tick <em>Keep synced</em> under Google Docs or Git in its DocSync box, or use the matching <em>keep synced</em> bulk action.</li>';
        echo '<li>Only published posts and pages sync. The sync copies the last <em>saved</em> version.</li></ul>';
        echo '<h2>Google Docs</h2><p>Each post gets one Google Doc in your Drive folder. Syncing again replaces that doc\'s contents, so edits made in the doc are overwritten (they remain in the doc\'s Version history).</p>';
        echo '<h2>Git</h2><p>Each post is saved as a Markdown file with its details (title, date, link, categories, tags) at the top. Each change becomes one commit; if nothing changed, no commit is made. Images are referenced by their website URL and are never copied into the repository.</p>';
    }

    /* ---------------------------------------------------------------- Editor box */

    public function add_metabox() {
        foreach (self::TYPES as $type) add_meta_box('mascamp-docsync', 'DocSync', [$this, 'metabox'], $type, 'side', 'high');
    }

    public function sync_url($dest, $post_id) {
        return wp_nonce_url(admin_url('admin-post.php?action=mascamp_docsync_now&dest=' . rawurlencode($dest) . '&post=' . absint($post_id)), 'mascamp_docsync_now_' . $dest . '_' . absint($post_id));
    }

    public function metabox($post) {
        if (!current_user_can('edit_post', $post->ID)) return;
        wp_nonce_field('mascamp_docsync_metabox', 'mascamp_docsync_metabox_nonce');
        $published = $post->post_status === 'publish';
        foreach ($this->dests as $id => $d) {
            echo '<div style="border-top:1px solid #dcdcde;margin-top:10px;padding-top:6px"><p style="margin:4px 0"><strong>' . esc_html($d->label()) . '</strong></p>';
            if (!$d->is_ready()) {
                echo '<p class="description">Not set up yet. <a href="' . esc_url($this->settings_url($id)) . '">Set up ' . esc_html($d->label()) . '</a></p></div>';
                continue;
            }
            $status = $d->meta($post->ID, 'status');
            $synced = $d->meta($post->ID, 'synced');
            $error  = $d->meta($post->ID, 'error');
            $warns  = array_filter((array) $d->meta($post->ID, 'warn'));
            $view   = $d->view_url($post->ID);
            echo '<p style="margin:4px 0">Status: <strong>' . esc_html($status ?: 'Not synced') . '</strong>';
            if ($view) echo ' · <a target="_blank" rel="noopener" href="' . esc_url($view) . '">View ↗</a>';
            echo '</p>';
            if ($synced) echo '<p style="margin:4px 0"><small>Last synced: ' . esc_html($synced) . '</small></p>';
            if ($error) echo '<p style="margin:4px 0;color:#b32d2e">' . esc_html($error) . '</p>';
            if ($warns) {
                echo '<details><summary style="color:#996800;cursor:pointer">' . count($warns) . ' warning(s)</summary><ul style="font-size:12px">';
                foreach ($warns as $w) echo '<li>' . esc_html($w) . '</li>';
                echo '</ul></details>';
            }
            if ($published) echo '<p style="margin:6px 0"><a class="button" href="' . esc_url($this->sync_url($id, $post->ID)) . '">Sync to ' . esc_html($d->label()) . ' now</a></p>';
            echo '<input type="hidden" name="mascamp_docsync_auto_present[' . esc_attr($id) . ']" value="1">';
            echo '<p style="margin:4px 0"><label><input type="checkbox" name="mascamp_docsync_auto[' . esc_attr($id) . ']" value="1" ' . checked($d->meta($post->ID, 'auto'), '1', false) . '> Keep synced</label>';
            if (!$d->auto_enabled()) echo '<br><small>Automatic sync is paused in settings.</small>';
            echo '</p></div>';
        }
        echo '<p class="description" style="margin-top:10px">' . ($published ? 'Sync copies the last <em>saved</em> version. Update first if you have unsaved changes.' : 'Publish this first. Only published posts and pages can be synced.') . '</p>';
    }

    public function save_metabox($post_id, $post) {
        if (!isset($_POST['mascamp_docsync_metabox_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mascamp_docsync_metabox_nonce'])), 'mascamp_docsync_metabox')) return;
        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) return;
        if (!($post instanceof \WP_Post) || !in_array($post->post_type, self::TYPES, true) || !current_user_can('edit_post', $post_id)) return;
        $present = (array) ($_POST['mascamp_docsync_auto_present'] ?? []);
        $auto = (array) ($_POST['mascamp_docsync_auto'] ?? []);
        foreach ($this->dests as $id => $d) {
            if (empty($present[$id])) continue; // checkbox wasn't shown (destination not set up)
            if (!empty($auto[$id])) $d->set_meta($post_id, 'auto', '1'); else $d->del_meta($post_id, 'auto');
        }
    }

    /* ---------------------------------------------------------------- Posts list */

    public function add_column($cols) { if ($this->ready_dests()) $cols['mascamp_docsync'] = 'DocSync'; return $cols; }

    public function render_column($col, $post_id) {
        if ($col !== 'mascamp_docsync') return;
        $lines = [];
        foreach ($this->ready_dests() as $d) {
            $status = $d->meta($post_id, 'status');
            $color = ['Synced' => '#008a20', 'Up to date' => '#008a20', 'Synced with warnings' => '#996800', 'Error' => '#b32d2e'][$status] ?? '#50575e';
            $line = '<strong>' . esc_html($d->short_label()) . ':</strong> <span style="color:' . esc_attr($color) . '">' . esc_html($status ?: '—') . '</span>';
            if ($view = $d->view_url($post_id)) $line .= ' <a target="_blank" rel="noopener" href="' . esc_url($view) . '" title="Open">↗</a>';
            if ($d->meta($post_id, 'auto') === '1') $line .= ' <small title="Keep synced">· auto</small>';
            if ($status === 'Error' && ($e = $d->meta($post_id, 'error'))) $line .= '<br><small style="color:#b32d2e">' . esc_html(wp_trim_words($e, 12)) . '</small>';
            $lines[] = $line;
        }
        echo implode('<br>', $lines);
    }

    public function row_actions($actions, $post) {
        if (!in_array($post->post_type, self::TYPES, true) || $post->post_status !== 'publish' || !current_user_can('edit_post', $post->ID)) return $actions;
        foreach ($this->ready_dests() as $id => $d) {
            $actions['mascamp_docsync_' . $id] = '<a href="' . esc_url($this->sync_url($id, $post->ID)) . '">Sync to ' . esc_html($d->label()) . '</a>';
        }
        return $actions;
    }

    public function bulk_actions($actions) {
        foreach ($this->ready_dests() as $id => $d) {
            $actions['mcds_' . $id . '_sync'] = 'Sync to ' . $d->label();
            $actions['mcds_' . $id . '_auto_on'] = $d->label() . ': keep synced';
            $actions['mcds_' . $id . '_auto_off'] = $d->label() . ': stop keeping synced';
        }
        return $actions;
    }

    public function handle_bulk($redirect, $action, $ids) {
        if (!preg_match('/^mcds_([a-z]+)_(sync|auto_on|auto_off)$/', $action, $m) || !($d = $this->dest($m[1]))) return $redirect;
        $op = $m[2]; $count = 0; $skipped = 0; $i = 0;
        foreach ((array) $ids as $id) {
            $id = absint($id);
            if (!current_user_can('edit_post', $id)) { $skipped++; continue; }
            if ($op === 'sync') {
                if (get_post_status($id) !== 'publish') { $skipped++; continue; }
                $this->queue($m[1], $id, 5 + ($i++ * 8)); // staggered to stay under API rate limits
            } elseif ($op === 'auto_on') {
                $d->set_meta($id, 'auto', '1');
            } else {
                $d->del_meta($id, 'auto');
            }
            $count++;
        }
        $redirect = remove_query_arg(['mcds_bulk', 'mcds_n', 'mcds_skip'], $redirect);
        return add_query_arg(['mcds_bulk' => $m[1] . ':' . $op, 'mcds_n' => $count, 'mcds_skip' => $skipped], $redirect);
    }

    /* ---------------------------------------------------------------- Triggers */

    public function sync_now() {
        $dest = sanitize_key($_GET['dest'] ?? '');
        $post_id = absint($_GET['post'] ?? 0);
        $d = $this->dest($dest);
        if (!$d || !$post_id || !current_user_can('edit_post', $post_id)) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_docsync_now_' . $dest . '_' . $post_id);
        $result = $this->run($dest, $post_id);
        $title = esc_html(self::plain_title($post_id));
        if ($result === true) {
            $status = $d->meta($post_id, 'status');
            $warns = array_filter((array) $d->meta($post_id, 'warn'));
            $view = $d->view_url($post_id);
            $link = $view ? ' <a target="_blank" rel="noopener" href="' . esc_url($view) . '">View in ' . esc_html($d->label()) . ' ↗</a>' : '';
            if ($status === 'Up to date') $msg = '“' . $title . '” is already up to date in ' . esc_html($d->label()) . ' (no changes to commit).';
            else $msg = '“' . $title . '” synced to ' . esc_html($d->label()) . ($warns ? ' with ' . count($warns) . ' warning(s). See the DocSync box on the post.' : '.');
            $this->notice($warns ? 'warning' : 'success', $msg . $link, true);
        } else {
            $this->notice('error', esc_html($d->label()) . ' sync failed for “' . $title . '”: ' . esc_html(is_wp_error($result) ? $result->get_error_message() : 'unknown error'), true);
        }
        wp_safe_redirect(wp_get_referer() ?: get_edit_post_link($post_id, 'url')); exit;
    }

    public function queue_on_save($post_id, $post) {
        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !($post instanceof \WP_Post)) return;
        if (!in_array($post->post_type, self::TYPES, true) || $post->post_status !== 'publish' || !current_user_can('edit_post', $post_id)) return;
        foreach ($this->dests as $id => $d) {
            if ($d->auto_enabled() && $d->meta($post_id, 'auto') === '1' && $d->is_ready()) $this->queue($id, $post_id);
        }
    }

    private function queue($dest, $post_id, $delay = 15) {
        $d = $this->dest($dest);
        if (!wp_next_scheduled(self::ITEM_HOOK, [$dest, $post_id])) wp_schedule_single_event(time() + $delay, self::ITEM_HOOK, [$dest, $post_id]);
        $d->set_meta($post_id, 'status', 'Queued');
        $d->del_meta($post_id, 'error');
    }

    public function cron_item($dest, $post_id) { $this->run(sanitize_key($dest), absint($post_id)); }
    public function cron_legacy($post_id) { $this->run('gdocs', absint($post_id)); }

    /**
     * Sync one post to one destination and record the result on the post. Returns true or WP_Error.
     */
    public function run($dest_id, $post_id) {
        $d = $this->dest($dest_id);
        if (!$d) return new \WP_Error('invalid_destination', 'Unknown sync destination.');
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, self::TYPES, true)) return new \WP_Error('invalid_post', 'Only posts and pages can be synced.');
        if (!wp_doing_cron() && !current_user_can('edit_post', $post_id)) return new \WP_Error('forbidden', 'You cannot edit this post.');
        if ($post->post_status !== 'publish') return $this->mark_error($d, $post_id, new \WP_Error('not_published', 'Publish the post or page before syncing.'));
        $ready = $d->ready();
        if (is_wp_error($ready)) return $this->mark_error($d, $post_id, $ready);
        if (function_exists('set_time_limit')) @set_time_limit(300);

        $d->set_meta($post_id, 'status', 'Syncing');
        $d->del_meta($post_id, 'error');
        try {
            $r = $d->sync($post);
        } catch (\Throwable $e) {
            $r = new \WP_Error('sync_exception', 'Unexpected error: ' . $e->getMessage());
        }
        if (is_wp_error($r)) return $this->mark_error($d, $post_id, $r);

        $warnings = array_values(array_filter((array) ($r['warnings'] ?? [])));
        $status = $r['status'] ?? ($warnings ? 'Synced with warnings' : 'Synced');
        $d->set_meta($post_id, 'status', $status);
        $d->set_meta($post_id, 'synced', current_time('mysql'));
        if ($warnings) $d->set_meta($post_id, 'warn', $warnings); else $d->del_meta($post_id, 'warn');
        $d->del_meta($post_id, 'error');
        return true;
    }

    private function mark_error(Destination $d, $post_id, $error) {
        $message = is_wp_error($error) ? $error->get_error_message() : 'Unknown sync error';
        $d->set_meta($post_id, 'status', 'Error');
        $d->set_meta($post_id, 'error', sanitize_text_field($message));
        return is_wp_error($error) ? $error : new \WP_Error('sync_error', $message);
    }

    /* ---------------------------------------------------------------- Shared content helpers */

    public static function plain_title($post_id) {
        $t = html_entity_decode(wp_strip_all_tags(get_the_title($post_id)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($t) !== '' ? trim($t) : 'WordPress post ' . $post_id;
    }

    /** Rendered post HTML — Elementor's builder output for Elementor pages, the_content otherwise. */
    public static function content_html($post_id) {
        $post = get_post($post_id);
        $GLOBALS['post'] = $post; // some content filters/shortcodes expect the global post
        setup_postdata($post);
        $html = '';
        if (class_exists('\Elementor\Plugin') && get_post_meta($post_id, '_elementor_edit_mode', true) === 'builder' && isset(\Elementor\Plugin::instance()->frontend)) {
            try {
                $html = (string) \Elementor\Plugin::instance()->frontend->get_builder_content_for_display($post_id, false);
            } catch (\Throwable $e) {
                $html = '';
            }
        }
        if (trim(wp_strip_all_tags($html)) === '' && strpos($html, '<img') === false) {
            $html = apply_filters('the_content', $post->post_content);
        }
        wp_reset_postdata();
        return $html;
    }

    /* ---------------------------------------------------------------- Sync all (background batch) */

    private function batch() { return get_option(self::BATCH_OPTION, []); }
    private function batch_running() { $b = $this->batch(); return !empty($b['total']) && empty($b['finished']); }
    private static function type_label($type, $plural = true) {
        $o = get_post_type_object($type);
        return $o ? strtolower($plural ? $o->labels->name : $o->labels->singular_name) : $type;
    }

    private function render_batch_section() {
        $b = $this->batch();
        if ($this->batch_running()) {
            $d = $this->dest($b['dest'] ?? 'gdocs');
            $total = (int) $b['total']; $done = (int) $b['done'];
            $pct = $total ? (int) floor($done * 100 / $total) : 0;
            echo '<div style="max-width:640px">';
            echo '<p><strong>Syncing all ' . esc_html(self::type_label($b['type'])) . ' to ' . esc_html($d ? $d->label() : '') . ':</strong> <span id="mcds-count">' . $done . ' of ' . $total . '</span> · <span id="mcds-errors">' . (int) $b['errors'] . '</span> error(s)</p>';
            echo '<div style="background:#dcdcde;border-radius:4px;height:14px;overflow:hidden"><div id="mcds-bar" style="background:#2271b1;height:14px;width:' . $pct . '%;transition:width .4s"></div></div>';
            echo '<p class="description" id="mcds-note">Keep this page open to finish faster. If you close it, syncing continues in the background, more slowly.</p>';
            echo '<p id="mcds-last-error" style="color:#b32d2e' . (empty($b['failures']) ? ';display:none' : '') . '">Latest error: <span>' . esc_html(empty($b['failures']) ? '' : end($b['failures'])['error']) . '</span></p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mascamp_docsync_batch_cancel">';
            wp_nonce_field('mascamp_docsync_batch_cancel');
            submit_button('Cancel', 'secondary', 'submit', false);
            echo '</form></div>';
            $this->render_failures($b);
            $ajax = esc_url_raw(admin_url('admin-ajax.php'));
            $nonce = wp_create_nonce('mascamp_docsync_batch_step');
            echo "<script>(function(){var busy=false,fails=0;function step(){if(busy)return;busy=true;var f=new FormData();f.append('action','mascamp_docsync_batch_step');f.append('_ajax_nonce'," . wp_json_encode($nonce) . ");fetch(" . wp_json_encode($ajax) . ",{method:'POST',body:f,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(r){busy=false;fails=0;if(!r||!r.success){return setTimeout(step,5000);}var d=r.data;document.getElementById('mcds-count').textContent=d.done+' of '+d.total;document.getElementById('mcds-errors').textContent=d.errors;document.getElementById('mcds-bar').style.width=(d.total?Math.floor(d.done*100/d.total):100)+'%';if(d.wait){document.getElementById('mcds-note').textContent='Rate limit reached. Pausing for a minute, then continuing automatically.';}if(d.last_error){var le=document.getElementById('mcds-last-error');le.style.display='';le.querySelector('span').textContent=d.last_error;}if(d.finished){location.reload();return;}setTimeout(step,d.wait?d.wait*1000:1200);}).catch(function(){busy=false;if(++fails<20)setTimeout(step,5000);});}setTimeout(step,500);})();</script>";
            return;
        }
        if (!empty($b['finished'])) {
            $d = $this->dest($b['dest'] ?? 'gdocs');
            $msg = sprintf('Last run (%s): %d of %d %s synced', $d ? $d->label() : '', (int) $b['done'] - (int) $b['errors'], (int) $b['total'], self::type_label($b['type']));
            $msg .= (int) $b['errors'] ? sprintf(', %d error(s). The DocSync column in %s shows which ones and why.', (int) $b['errors'], ucfirst(self::type_label($b['type']))) : '.';
            if (!empty($b['cancelled'])) $msg .= ' (Cancelled.)';
            if (!empty($b['stopped'])) {
                echo '<div class="notice notice-error inline"><p><strong>Sync all stopped:</strong> the first ' . (int) $b['done'] . ' items all failed with the same error, so the rest were not attempted. Fix this, then run it again:</p><p><code>' . esc_html($b['stopped']) . '</code></p>';
                if (($b['dest'] ?? '') === 'git') echo '<p>Use <a href="' . esc_url($this->settings_url('git')) . '">Git → Test connection</a> to check the fix before re-running.</p>';
                echo '</div>';
            } else {
                echo '<div class="notice ' . ((int) $b['errors'] ? 'notice-warning' : 'notice-info') . ' inline"><p>' . esc_html($msg) . ' <small>' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $b['finished'])) . '</small></p></div>';
            }
            $this->render_failures($b);
        }
        $ready = $this->ready_dests();
        if (!$ready) {
            echo '<p>Set up <a href="' . esc_url($this->settings_url('gdocs')) . '">Google Docs</a> or <a href="' . esc_url($this->settings_url('git')) . '">Git</a> first.</p>';
            return;
        }
        echo '<p>Sync every published post or page in one go. It runs in the background, one item at a time, paced to stay within rate limits.</p>';
        foreach ($ready as $id => $d) {
            echo '<h2>' . esc_html($d->label()) . '</h2><table class="widefat striped" style="max-width:760px"><tbody>';
            foreach (self::TYPES as $type) {
                $published = (int) wp_count_posts($type)->publish;
                $synced = count(get_posts(['post_type' => $type, 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true, 'meta_key' => $d->keys()['link'], 'meta_compare' => 'EXISTS']));
                $warn = $id === 'gdocs' ? 'Existing linked docs will be overwritten with the WordPress version.' : 'Each changed item becomes one commit; unchanged items are skipped.';
                echo '<tr><td><strong>' . esc_html(ucfirst(self::type_label($type))) . '</strong><br><small>' . $published . ' published · ' . $synced . ' already synced</small></td><td style="text-align:right">';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
                echo '<input type="hidden" name="action" value="mascamp_docsync_batch_start"><input type="hidden" name="post_type" value="' . esc_attr($type) . '"><input type="hidden" name="dest" value="' . esc_attr($id) . '">';
                wp_nonce_field('mascamp_docsync_batch_start');
                echo '<label style="margin-right:10px"><input type="checkbox" name="only_new" value="1"> Only ones not synced yet</label> ';
                echo '<button type="submit" class="button button-primary"' . ($published ? '' : ' disabled') . ' onclick="return confirm(' . esc_attr(wp_json_encode('Sync all published ' . self::type_label($type) . ' to ' . $d->label() . '? ' . $warn)) . ')">Sync all ' . esc_html(self::type_label($type)) . '</button>';
                echo '</form></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<p class="description">Google Docs: existing linked docs are overwritten with the current WordPress version (earlier versions stay in each doc\'s Version history). Git: one commit per changed post; unchanged posts are skipped.</p>';
    }

    /** Table of failed items from the current/last Sync all, grouped by error message. */
    private function render_failures($b) {
        $fails = (array) ($b['failures'] ?? []);
        if (!$fails) return;
        $groups = [];
        foreach ($fails as $f) $groups[$f['error']][] = $f;
        echo '<details open style="max-width:900px;margin-top:12px"><summary style="cursor:pointer;font-weight:600">Errors (' . count($fails) . ')</summary>';
        foreach ($groups as $error => $items) {
            echo '<div style="background:#fff;border-left:4px solid #d63638;padding:8px 12px;margin:8px 0"><p style="margin:0 0 6px"><strong>' . esc_html($error) . '</strong> <small>(' . count($items) . ' item' . (count($items) === 1 ? '' : 's') . ')</small></p><p style="margin:0;font-size:12px">';
            $links = [];
            foreach (array_slice($items, 0, 25) as $f) $links[] = '<a href="' . esc_url(get_edit_post_link($f['id'], 'url')) . '">' . esc_html($f['title']) . '</a>';
            echo implode(' · ', $links) . (count($items) > 25 ? ' · and ' . (count($items) - 25) . ' more' : '');
            echo '</p></div>';
        }
        echo '</details>';
    }

    public function batch_start() {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_docsync_batch_start');
        $type = sanitize_key($_POST['post_type'] ?? '');
        $dest = sanitize_key($_POST['dest'] ?? '');
        $d = $this->dest($dest);
        if (!in_array($type, self::TYPES, true) || !$d) wp_die('Invalid request.');
        $back = $this->settings_url('all');
        if ($this->batch_running()) { $this->notice('error', 'A Sync all is already running. Wait for it to finish or cancel it first.'); wp_safe_redirect($back); exit; }
        $args = ['post_type' => $type, 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true, 'orderby' => 'date', 'order' => 'DESC'];
        if (!empty($_POST['only_new'])) { $args['meta_key'] = $d->keys()['link']; $args['meta_compare'] = 'NOT EXISTS'; }
        $ids = array_map('absint', get_posts($args));
        if (!$ids) { $this->notice('success', 'Nothing to sync: no matching published ' . self::type_label($type) . '.'); wp_safe_redirect($back); exit; }
        foreach ($ids as $id) { $d->set_meta($id, 'status', 'Queued'); $d->del_meta($id, 'error'); }
        update_option(self::BATCH_OPTION, ['dest' => $dest, 'type' => $type, 'ids' => $ids, 'total' => count($ids), 'done' => 0, 'errors' => 0, 'started' => time(), 'pause_until' => 0], false);
        delete_option(self::BATCH_LOCK);
        wp_clear_scheduled_hook(self::BATCH_HOOK);
        wp_schedule_single_event(time() + 30, self::BATCH_HOOK);
        wp_safe_redirect($back); exit;
    }

    public function batch_cancel() {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_docsync_batch_cancel');
        $b = $this->batch();
        $d = $this->dest($b['dest'] ?? 'gdocs');
        if ($d) foreach ((array) ($b['ids'] ?? []) as $id) {
            if ($d->meta($id, 'status') === 'Queued') $d->del_meta($id, 'status');
        }
        if ($b) { $b['ids'] = []; $b['finished'] = time(); $b['cancelled'] = true; update_option(self::BATCH_OPTION, $b, false); }
        wp_clear_scheduled_hook(self::BATCH_HOOK);
        delete_option(self::BATCH_LOCK);
        $this->notice('success', 'Sync all cancelled.');
        wp_safe_redirect($this->settings_url('all')); exit;
    }

    /** Browser-driven step: one item per request while the Sync all page is open. */
    public function batch_ajax_step() {
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
        check_ajax_referer('mascamp_docsync_batch_step');
        $wait = $this->batch_process(1, 50);
        $b = $this->batch();
        $last = empty($b['failures']) ? '' : end($b['failures'])['error'];
        wp_send_json_success(['done' => (int) ($b['done'] ?? 0), 'total' => (int) ($b['total'] ?? 0), 'errors' => (int) ($b['errors'] ?? 0), 'finished' => !$this->batch_running(), 'wait' => $wait, 'last_error' => $last]);
    }

    /** Background step via WP-Cron: a few items, then reschedule itself. */
    public function batch_cron() {
        $wait = $this->batch_process(5, 40);
        if ($this->batch_running() && !wp_next_scheduled(self::BATCH_HOOK)) wp_schedule_single_event(time() + max(20, $wait), self::BATCH_HOOK);
    }

    /**
     * Processes up to $max items within ~$seconds. Returns seconds to wait (rate-limit pause), or 0.
     * A lock prevents the browser loop and WP-Cron from syncing at the same time.
     */
    private function batch_process($max, $seconds) {
        if (!$this->batch_running()) return 0;
        $b = $this->batch();
        if (!empty($b['pause_until']) && $b['pause_until'] > time()) return (int) $b['pause_until'] - time();
        $lock = get_option(self::BATCH_LOCK);
        if ($lock && (int) $lock > time() - 300) return 0; // another runner is busy; try again shortly
        delete_option(self::BATCH_LOCK);
        if (!add_option(self::BATCH_LOCK, time(), '', false)) return 0;

        // Queue emptied but never marked finished (e.g. the last sync crashed): close it out.
        $b = $this->batch();
        if (empty($b['ids'])) {
            $b['finished'] = time();
            update_option(self::BATCH_OPTION, $b, false);
            delete_option(self::BATCH_LOCK);
            return 0;
        }

        $dest = $b['dest'] ?? 'gdocs';
        $d = $this->dest($dest);
        if (function_exists('set_time_limit')) @set_time_limit(300);
        $start = time(); $n = 0; $wait = 0;
        while ($d && $n < $max && (time() - $start) < $seconds) {
            $b = $this->batch();
            if (empty($b['ids']) || !empty($b['cancelled'])) break;
            // Remove the item from the queue before syncing, so a crash can't retry it forever.
            $id = (int) array_shift($b['ids']);
            update_option(self::BATCH_OPTION, $b, false);
            $result = $this->run($dest, $id);
            $b = $this->batch(); // re-read: picks up a cancel made meanwhile
            if (!empty($b['cancelled'])) break;
            if (is_wp_error($result) && Destination::http_status($result) === 429) {
                // Rate limit: put it back and pause a minute.
                array_push($b['ids'], $id);
                $d->set_meta($id, 'status', 'Queued');
                $d->del_meta($id, 'error');
                $b['pause_until'] = time() + 65;
                update_option(self::BATCH_OPTION, $b, false);
                $wait = 65;
                break;
            }
            $b['done'] = (int) $b['done'] + 1;
            if ($result !== true) {
                $b['errors'] = (int) $b['errors'] + 1;
                $b['failures'] = array_slice(array_merge((array) ($b['failures'] ?? []), [[
                    'id' => $id, 'title' => self::plain_title($id),
                    'error' => is_wp_error($result) ? $result->get_error_message() : 'Unknown error',
                ]]), -100);
                // The first few all failed the same way: it's a setup problem, so stop instead of failing every post.
                $msgs = array_unique(array_column($b['failures'], 'error'));
                if ((int) $b['done'] >= 3 && (int) $b['errors'] === (int) $b['done'] && count($msgs) === 1) {
                    $b['stopped'] = reset($msgs);
                    foreach ((array) $b['ids'] as $rest) if ($d->meta($rest, 'status') === 'Queued') $d->del_meta($rest, 'status');
                    $b['ids'] = [];
                }
            }
            if (empty($b['ids'])) $b['finished'] = time();
            update_option(self::BATCH_OPTION, $b, false);
            $n++;
        }
        delete_option(self::BATCH_LOCK);
        return $wait;
    }

    /* ---------------------------------------------------------------- Notices */

    public function notice($type, $message, $is_html = false) {
        set_transient('mascamp_gdocs_notice_' . get_current_user_id(), [$type, $message, $is_html], 120);
    }

    public function notices() {
        $key = 'mascamp_gdocs_notice_' . get_current_user_id();
        $n = get_transient($key);
        if ($n) {
            delete_transient($key);
            $type = in_array($n[0], ['success', 'warning', 'error'], true) ? $n[0] : 'info';
            $msg = !empty($n[2]) ? wp_kses($n[1], ['a' => ['href' => [], 'target' => [], 'rel' => []], 'strong' => [], 'em' => [], 'br' => []]) : esc_html($n[1]);
            echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . $msg . '</p></div>';
        }
        if (!empty($_GET['mcds_bulk']) && preg_match('/^([a-z]+):(sync|auto_on|auto_off)$/', sanitize_text_field(wp_unslash($_GET['mcds_bulk'])), $m) && ($d = $this->dest($m[1]))) {
            $count = absint($_GET['mcds_n'] ?? 0);
            $skip = absint($_GET['mcds_skip'] ?? 0);
            $text = [
                'sync'     => '%d item(s) queued for ' . $d->label() . ' sync. Refresh in a minute to see the results in the DocSync column.',
                'auto_on'  => '%d item(s) set to keep synced with ' . $d->label() . '.',
                'auto_off' => '%d item(s) will no longer sync to ' . $d->label() . ' automatically.',
            ][$m[2]];
            $msg = sprintf($text, $count) . ($skip ? ' ' . sprintf('%d skipped (not published or not editable).', $skip) : '');
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }
    }
}
