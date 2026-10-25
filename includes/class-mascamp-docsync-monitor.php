<?php
namespace MasCamp\GoogleDocsSync;
if (!defined('ABSPATH')) exit;

/**
 * Sync Monitor — finds posts whose WordPress version and synced copies (Google Docs, Git) have drifted apart.
 *
 * WordPress side: post_modified_gmt vs. the baseline stored at the last sync (instant, no API calls).
 * Remote side:    each destination's scan_remote() — one Drive listing / one Git tree listing (hourly + "Scan now").
 * Shows an admin notice ("DocSync has detected N changes") and a Changes tab listing what changed, where, by whom,
 * with the right fix button for each case.
 */
final class Monitor {
    const OPTION  = 'mascamp_docsync_monitor';
    const DISMISS = 'mascamp_docsync_monitor_dismissed';
    const HOOK    = 'mascamp_docsync_monitor_scan';
    const STATUSES = ['publish', 'future', 'draft', 'pending', 'private'];

    /** @var Module */
    private $core;

    public function __construct(Module $core) {
        $this->core = $core;
        add_action('init', [$this, 'schedule']);
        add_action(self::HOOK, [$this, 'scan']);
        add_action('admin_notices', [$this, 'notice'], 5);
        add_action('admin_post_mascamp_docsync_scan', [$this, 'scan_handler']);
        add_action('admin_post_mascamp_docsync_monitor_dismiss', [$this, 'dismiss_handler']);
        add_action('admin_post_mascamp_docsync_monitor_sync_wp', [$this, 'sync_wp_handler']);
        add_action('mascamp_docsync_after_run', [$this, 'after_run'], 10, 3);
        add_action('save_post', [$this, 'after_save'], 30, 2);
    }

    public function schedule() {
        if (!wp_next_scheduled(self::HOOK)) wp_schedule_event(time() + 300, 'hourly', self::HOOK);
    }

    private function data() {
        return wp_parse_args(get_option(self::OPTION, []), ['scanned' => 0, 'items' => [], 'notes' => [], 'checked' => []]);
    }
    private function save(array $data) { update_option(self::OPTION, $data, false); }

    public function count() { return count($this->data()['items']); }

    /* ---------------------------------------------------------------- Detection */

    /** Linked posts for a destination (has a synced copy). */
    private function linked_ids(Destination $d) {
        return array_map('intval', get_posts([
            'post_type' => Module::TYPES, 'post_status' => self::STATUSES, 'posts_per_page' => -1, 'fields' => 'ids',
            'no_found_rows' => true, 'meta_key' => $d->keys()['link'], 'meta_compare' => 'EXISTS',
        ]));
    }

    /** Posts whose last sync to $d failed. */
    private function error_ids(Destination $d) {
        return array_map('intval', get_posts([
            'post_type' => Module::TYPES, 'post_status' => self::STATUSES, 'posts_per_page' => -1, 'fields' => 'ids',
            'no_found_rows' => true, 'meta_key' => $d->keys()['status'], 'meta_value' => 'Error',
        ]));
    }

    /** WordPress-side change info for one post/destination, or null. */
    private function wp_side(Destination $d, $post_id) {
        if (get_post_status($post_id) !== 'publish') return null; // only published posts can be pushed
        if (!$d->meta($post_id, 'link')) return null;
        if (!$d->wp_changed_since_sync($post_id)) return null;
        $uid = (int) get_post_meta($post_id, '_edit_last', true);
        $user = $uid ? get_userdata($uid) : null;
        return ['when' => (int) strtotime(get_post_field('post_modified_gmt', $post_id) . ' UTC'), 'by' => $user ? $user->display_name : ''];
    }

    /** Builds the item for one post/destination from its parts; null when everything is in sync. */
    private function build_item(Destination $d, $post_id, $remote, $prev = null) {
        $wp = $this->wp_side($d, $post_id);
        $error = $d->meta($post_id, 'status') === 'Error' ? (string) $d->meta($post_id, 'error') : '';
        if ($error === '' && !$wp && !$remote) return null;
        if ($error !== '') $state = 'error';
        elseif ($remote && $remote['state'] === 'missing') $state = 'missing';
        elseif ($wp && $remote) $state = 'both';
        elseif ($wp) $state = 'wp';
        else $state = 'remote';
        return ['post' => (int) $post_id, 'dest' => $d->id(), 'state' => $state, 'wp' => $wp, 'remote' => $remote, 'error' => $error,
            'found' => $prev['found'] ?? time()];
    }

    /** Full scan: WordPress side + every destination's remote side. */
    public function scan() {
        if (function_exists('set_time_limit')) @set_time_limit(600);
        $prev = $this->data()['items'];
        $items = []; $notes = []; $checked = [];
        foreach (['gdocs', 'git'] as $id) {
            $d = $this->core->dest($id);
            if (!$d || !$d->is_ready()) continue;
            $linked = $this->linked_ids($d);
            $label = $id === 'git' ? 'Post to Git file scan' : 'Post to Google Doc scan';
            Log::add('Sync Update', $label . ' started', 0, $id, 'info', sprintf('%d linked items', count($linked)));
            $remote = $d->scan_remote($linked);
            if ($remote['note'] !== '') $notes[$id] = $remote['note'];
            $checked[$id] = count($linked);
            $ids = array_unique(array_merge($linked, $this->error_ids($d)));
            $found = 0;
            foreach ($ids as $pid) {
                $key = $id . ':' . $pid;
                $item = $this->build_item($d, $pid, $remote['items'][$pid] ?? null, $prev[$key] ?? null);
                if (!$item) continue;
                $items[$key] = $item; $found++;
                $this->log_new($item, $prev[$key] ?? null);
            }
            Log::add('Sync Update', $label . ' complete: ' . ($found ? sprintf(_n('%d item out of sync', '%d items out of sync', $found), $found) : 'everything in sync'), 0, $id, $found ? 'warning' : 'success', $remote['note']);
        }
        $this->save(['scanned' => time(), 'items' => $items, 'notes' => $notes, 'checked' => $checked]);
        return count($items);
    }

    /** Re-evaluate one post without remote calls (after a save or a sync). */
    private function refresh_post($post_id, $synced_dest = null) {
        $data = $this->data();
        $changed = false;
        foreach (['gdocs', 'git'] as $id) {
            $d = $this->core->dest($id);
            if (!$d || !$d->is_ready()) continue;
            $key = $id . ':' . $post_id;
            $prev = $data['items'][$key] ?? null;
            $remote = $id === $synced_dest ? null : ($prev['remote'] ?? null); // a successful sync/pull resolves the remote side
            $item = $this->build_item($d, $post_id, $remote, $prev);
            if ($item) { if ($synced_dest === null) $this->log_new($item, $prev); $data['items'][$key] = $item; $changed = true; }
            elseif ($prev) { unset($data['items'][$key]); $changed = true; }
        }
        if ($changed) $this->save($data);
    }

    /** Log a change the first time it's seen (not on every scan). */
    private function log_new(array $item, $prev) {
        $target = Log::target($item['dest']);
        if ($item['remote'] && empty($prev['remote'])) {
            $r = $item['remote'];
            if ($item['remote']['state'] === 'missing') Log::add('Change detected', $target . ' missing: ' . ($item['dest'] === 'git' ? 'file deleted or moved in the repository' : 'doc deleted or trashed in Drive'), $item['post'], $item['dest'], 'warning', $r['detail'] ?? '');
            else Log::add('Change detected', ($item['dest'] === 'git' ? 'Git file changed outside WordPress' : 'Google Doc edited outside WordPress') . (!empty($r['by']) ? ' by ' . $r['by'] : ''), $item['post'], $item['dest'], 'warning', $r['detail'] ?? '');
        }
        if ($item['wp'] && empty($prev['wp'])) {
            Log::add('Change detected', 'Post edited in WordPress, not yet synced to ' . $target, $item['post'], $item['dest'], 'info');
        }
    }

    public function after_run($dest, $post_id, $result) {
        $this->refresh_post((int) $post_id, $result === true ? $dest : null);
    }

    public function after_save($post_id, $post) {
        if (wp_is_post_revision($post_id) || !($post instanceof \WP_Post) || !in_array($post->post_type, Module::TYPES, true)) return;
        $this->refresh_post((int) $post_id);
    }

    /* ---------------------------------------------------------------- Notice */

    private function summary(array $items) {
        $c = ['wp' => 0, 'gdocs' => 0, 'git' => 0, 'error' => 0];
        foreach ($items as $it) {
            if ($it['state'] === 'error') { $c['error']++; continue; }
            if (in_array($it['state'], ['wp', 'both'], true)) $c['wp']++;
            if (in_array($it['state'], ['remote', 'both', 'missing'], true)) $c[$it['dest']]++;
        }
        $parts = [];
        if ($c['wp']) $parts[] = sprintf(_n('%d edited in WordPress', '%d edited in WordPress', $c['wp']), $c['wp']);
        if ($c['gdocs']) $parts[] = sprintf(_n('%d changed in Google Docs', '%d changed in Google Docs', $c['gdocs']), $c['gdocs']);
        if ($c['git']) $parts[] = sprintf(_n('%d changed in Git', '%d changed in Git', $c['git']), $c['git']);
        if ($c['error']) $parts[] = sprintf(_n('%d failed sync', '%d failed syncs', $c['error']), $c['error']);
        return $parts;
    }

    private function signature(array $items) {
        $sig = [];
        foreach ($items as $k => $it) $sig[] = $k . '|' . $it['state'] . '|' . ($it['wp']['when'] ?? 0) . '|' . ($it['remote']['when'] ?? 0);
        sort($sig);
        return md5(implode(',', $sig));
    }

    public function notice() {
        if (!current_user_can('manage_options')) return;
        if (($_GET['page'] ?? '') === Module::PAGE && ($_GET['tab'] ?? '') === 'changes') return;
        $data = $this->data();
        $n = count($data['items']);
        if (!$n || get_option(self::DISMISS) === $this->signature($data['items'])) return;
        $dismiss = wp_nonce_url(admin_url('admin-post.php?action=mascamp_docsync_monitor_dismiss'), 'mascamp_docsync_monitor_dismiss');
        echo '<div class="notice notice-success" style="position:relative;padding:14px 40px 14px 16px;border-left-color:#00a32a;background:#edfaef">';
        echo '<p style="margin:0 0 10px;font-size:14px"><strong>MasCamp DocSync has detected ' . (int) $n . ' ' . _n('change', 'changes', $n) . '</strong> that ' . ($n === 1 ? 'isn’t' : 'aren’t') . ' in sync: ' . esc_html(implode(', ', $this->summary($data['items']))) . '.</p>';
        echo '<p style="margin:0"><a class="button button-primary" href="' . esc_url($this->core->settings_url('changes')) . '">View changes</a></p>';
        echo '<a href="' . esc_url($dismiss) . '" class="notice-dismiss" style="text-decoration:none" title="Hide until something else changes"><span class="screen-reader-text">Dismiss</span></a>';
        echo '</div>';
    }

    public function dismiss_handler() {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_docsync_monitor_dismiss');
        update_option(self::DISMISS, $this->signature($this->data()['items']), false);
        wp_safe_redirect(wp_get_referer() ?: admin_url()); exit;
    }

    public function scan_handler() {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_docsync_scan');
        $n = $this->scan();
        $this->core->notice($n ? 'warning' : 'success', $n ? sprintf('Scan complete: %d %s out of sync.', $n, _n('item is', 'items are', $n)) : 'Scan complete: everything is in sync.');
        wp_safe_redirect($this->core->settings_url('changes')); exit;
    }

    /** Queue a push for every item where only WordPress changed (never overwrites remote edits). */
    public function sync_wp_handler() {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_docsync_monitor_sync_wp');
        $i = 0;
        foreach ($this->data()['items'] as $it) {
            if ($it['state'] !== 'wp') continue;
            $this->core->queue($it['dest'], $it['post'], 5 + ($i++ * 8));
        }
        $this->core->notice('success', $i ? sprintf('%d %s queued. They’ll sync over the next few minutes; this list updates as each one finishes.', $i, _n('item', 'items', $i)) : 'Nothing to sync.');
        wp_safe_redirect($this->core->settings_url('changes')); exit;
    }

    /* ---------------------------------------------------------------- Changes tab */

    private static function ago($ts) {
        return $ts ? sprintf('%s ago', human_time_diff($ts, time())) : '';
    }

    private static function line($label, $info) {
        $out = esc_html($label);
        if (!empty($info['by'])) $out .= ' by <strong>' . esc_html($info['by']) . '</strong>';
        if (!empty($info['when'])) $out .= ' <span style="color:#646970" title="' . esc_attr(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $info['when'])) . '">' . esc_html(self::ago($info['when'])) . '</span>';
        return $out;
    }

    private function revisions_link($post_id) {
        $revs = wp_get_post_revisions($post_id, ['numberposts' => 1, 'fields' => 'ids']);
        $rid = $revs ? (is_object(reset($revs)) ? reset($revs)->ID : (int) reset($revs)) : 0;
        return $rid ? '<a href="' . esc_url(admin_url('revision.php?revision=' . $rid)) . '">Compare revisions</a>' : '';
    }

    public function render_tab() {
        $this->render_out_of_sync();
        $this->render_log();
    }

    /** Activity log: every scan, push, pull, update, import and Sync all — filterable. */
    private function render_log() {
        $type   = sanitize_text_field(wp_unslash($_GET['log'] ?? ''));
        $dest   = sanitize_key($_GET['where'] ?? '');
        $issues = !empty($_GET['issues']);
        $post   = absint($_GET['post_id'] ?? 0);
        $page   = max(1, absint($_GET['lp'] ?? 1));
        $per    = 50;

        $issue_ids = array_values(array_unique(array_column($this->data()['items'], 'post')));
        $post_ids = null;
        if ($post) $post_ids = [$post];
        elseif ($issues) $post_ids = $issue_ids;
        $res = Log::query(['type' => $type, 'dest' => $dest, 'post_ids' => $post_ids, 'page' => $page, 'per_page' => $per]);

        echo '<h2 id="mcds-log" style="margin-top:32px">Activity log</h2>';
        echo '<form method="get" style="margin:0 0 10px;display:flex;flex-wrap:wrap;gap:10px;align-items:center"><input type="hidden" name="page" value="' . esc_attr(Module::PAGE) . '"><input type="hidden" name="tab" value="changes">';
        echo '<label>Activity type <select name="log">';
        $types = ['' => 'All activities', 'Push' => 'Push', 'Pull' => 'Pull', 'Update' => 'Update', 'Sync Update' => 'Sync Update (scans)', 'Change detected' => 'Change detected', 'Import' => 'Import', 'Sync all' => 'Sync all', 'error' => 'Errors'];
        foreach ($types as $v => $l) echo '<option value="' . esc_attr($v) . '" ' . selected($type, $v, false) . '>' . esc_html($l) . '</option>';
        echo '</select></label>';
        echo '<label>Location <select name="where">';
        foreach (['' => 'All locations', 'gdocs' => 'Google Docs', 'git' => 'Git'] as $v => $l) echo '<option value="' . esc_attr($v) . '" ' . selected($dest, $v, false) . '>' . esc_html($l) . '</option>';
        echo '</select></label>';
        echo '<label><input type="checkbox" name="issues" value="1" ' . checked($issues, true, false) . '> Only posts with sync issues (' . count($issue_ids) . ')</label>';
        if ($post) echo '<input type="hidden" name="post_id" value="' . (int) $post . '">';
        echo '<button class="button">Filter</button>';
        if ($type !== '' || $dest !== '' || $issues || $post) echo '<a href="' . esc_url($this->core->settings_url('changes') . '#mcds-log') . '">Clear filters</a>';
        echo '<span style="color:#646970">' . (int) $res['total'] . ' ' . _n('event', 'events', $res['total']) . ' · kept for ' . Log::KEEP_DAYS . ' days</span></form>';
        if ($post) {
            echo '<p style="margin:0 0 10px">Showing history for <strong>' . esc_html(Module::plain_title($post)) . '</strong> · <a href="' . esc_url(remove_query_arg(['post_id', 'lp'])) . '#mcds-log">show all posts</a></p>';
        }

        if (!$res['rows']) { echo '<p><em>' . ($type !== '' || $dest !== '' || $issues || $post ? 'No activity matches these filters.' : 'No activity yet. Events appear here as posts are scanned, pushed and pulled.') . '</em></p>'; return; }

        $colors = ['success' => '#00a32a', 'warning' => '#dba617', 'error' => '#d63638', 'info' => '#2271b1'];
        $users = [];
        echo '<table class="widefat" style="max-width:1200px;border-collapse:separate;border-spacing:0 6px;background:transparent;border:0;box-shadow:none"><tbody>';
        foreach ($res['rows'] as $r) {
            $ts = strtotime($r->created_gmt . ' UTC');
            $color = $colors[$r->status] ?? '#2271b1';
            if ($r->user_id && !isset($users[$r->user_id])) { $u = get_userdata((int) $r->user_id); $users[$r->user_id] = $u ? $u->display_name : 'User #' . $r->user_id; }
            $who = $r->user_id ? $users[$r->user_id] : 'Automatic';
            echo '<tr style="background:#fff">';
            echo '<td style="border-left:4px solid ' . esc_attr($color) . ';padding:10px 14px;width:46%">';
            echo '<div style="font-weight:600;font-size:14px;margin-bottom:2px">' . esc_html($r->title) . '</div>';
            echo '<div>Event Type: ' . esc_html($r->event_type) . '</div>';
            echo '<div>Time: ' . esc_html(wp_date(Log::TIME_FORMAT, $ts)) . ' <span style="color:#646970">(' . esc_html(human_time_diff($ts, time())) . ' ago)</span></div>';
            echo '</td><td style="padding:10px 14px">';
            if ($r->post_id && get_post((int) $r->post_id)) {
                echo '<div><a href="' . esc_url(get_edit_post_link((int) $r->post_id, 'url')) . '"><strong>' . esc_html(Module::plain_title((int) $r->post_id)) . '</strong></a> <small style="color:#646970">' . esc_html(get_post_type((int) $r->post_id)) . ' #' . (int) $r->post_id . '</small>';
                if (in_array((int) $r->post_id, $issue_ids, true)) echo ' <span style="padding:0 6px;border-radius:9px;background:#d63638;color:#fff;font-size:11px">sync issue</span>';
                if (!$post) echo ' · <a href="' . esc_url(add_query_arg(['post_id' => (int) $r->post_id, 'lp' => false])) . '#mcds-log">history</a>';
                echo '</div>';
            } elseif ($r->post_id) {
                echo '<div style="color:#646970">Post #' . (int) $r->post_id . ' (deleted)</div>';
            }
            if ($r->dest) echo '<div style="color:#646970">' . esc_html($r->dest === 'git' ? 'Git' : 'Google Docs') . '</div>';
            echo '<div style="color:#646970">By: ' . esc_html($who) . '</div>';
            if ($r->detail !== null && $r->detail !== '') echo '<div style="margin-top:4px;' . ($r->status === 'error' ? 'color:#b32d2e' : 'color:#50575e') . '">' . esc_html(wp_trim_words($r->detail, 40)) . '</div>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        $pages = (int) ceil($res['total'] / $per);
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links([
                'base' => add_query_arg(array_filter(['lp' => '%#%', 'log' => $type, 'where' => $dest, 'issues' => $issues ? '1' : '', 'post_id' => $post ?: '']), $this->core->settings_url('changes')) . '#mcds-log',
                'format' => '', 'current' => $page, 'total' => $pages, 'prev_text' => '‹', 'next_text' => '›',
            ]) . '</div></div>';
        }
    }

    private function render_out_of_sync() {
        $data = $this->data();
        $items = $data['items'];
        $scan = wp_nonce_url(admin_url('admin-post.php?action=mascamp_docsync_scan'), 'mascamp_docsync_scan');
        echo '<h2>Out of sync</h2>';
        echo '<p>Shows posts and pages whose WordPress version and synced copies no longer match: edited in WordPress since the last sync, edited directly in Google Docs or Git, deleted there, or failed to sync. ';
        echo 'WordPress edits are detected instantly; Google Drive and Git are checked hourly.</p>';
        echo '<p><a class="button" href="' . esc_url($scan) . '">Scan now</a> <span style="color:#646970;margin-left:8px">' . ($data['scanned'] ? 'Last full check: ' . esc_html(self::ago($data['scanned'])) : 'Not scanned yet') . '</span></p>';
        foreach ($data['notes'] as $id => $note) {
            $d = $this->core->dest($id);
            echo '<div class="notice notice-info inline"><p><strong>' . esc_html($d ? $d->label() : $id) . ':</strong> ' . esc_html($note) . '</p></div>';
        }

        if (!$items) {
            $parts = [];
            foreach ($data['checked'] as $id => $n) if (($d = $this->core->dest($id))) $parts[] = $n . ' in ' . $d->label();
            echo '<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #00a32a;padding:16px;max-width:760px"><p style="font-size:15px;margin:0"><strong>Everything is in sync.</strong></p>';
            if ($parts) echo '<p style="margin:6px 0 0;color:#646970">Checked ' . esc_html(implode(' and ', $parts)) . '.</p>';
            echo '</div>';
            return;
        }

        // Summary cards
        $cards = ['wp' => ['WordPress', 'edited here since last sync'], 'gdocs' => ['Google Docs', 'edited, deleted or trashed in Drive'], 'git' => ['Git', 'changed or missing in the repository'], 'error' => ['Failed syncs', 'need attention']];
        $count = ['wp' => 0, 'gdocs' => 0, 'git' => 0, 'error' => 0];
        foreach ($items as $it) {
            if ($it['state'] === 'error') { $count['error']++; continue; }
            if (in_array($it['state'], ['wp', 'both'], true)) $count['wp']++;
            if (in_array($it['state'], ['remote', 'both', 'missing'], true)) $count[$it['dest']]++;
        }
        echo '<div style="display:flex;flex-wrap:wrap;gap:12px;margin:12px 0 16px">';
        foreach ($cards as $k => $c) {
            $n = $count[$k];
            echo '<div style="flex:1 1 160px;max-width:240px;background:#fff;border:1px solid #c3c4c7;border-top:3px solid ' . ($n ? ($k === 'error' ? '#d63638' : '#dba617') : '#00a32a') . ';padding:10px 14px">';
            echo '<div style="font-size:24px;font-weight:600;line-height:1.2">' . (int) $n . '</div><div style="font-weight:600">' . esc_html($c[0]) . '</div><div style="color:#646970;font-size:12px">' . esc_html($n ? $c[1] : 'in sync') . '</div></div>';
        }
        echo '</div>';

        $wp_only = count(array_filter($items, static function ($it) { return $it['state'] === 'wp'; }));
        if ($wp_only) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=mascamp_docsync_monitor_sync_wp'), 'mascamp_docsync_monitor_sync_wp');
            echo '<p><a class="button button-primary mcds-confirm" data-confirm="' . esc_attr('Push ' . $wp_only . ' WordPress change(s) to their Google Docs / Git copies? Items edited on both sides are left for you to decide.') . '" href="' . esc_url($url) . '">Sync all WordPress changes (' . (int) $wp_only . ')</a> <span style="color:#646970">Only items changed in WordPress alone. Anything edited remotely is never overwritten by this button.</span></p>';
        }

        // Filter + table
        echo '<p><label>Show: <select id="mcds-filter"><option value="">All (' . count($items) . ')</option><option value="wp">Edited in WordPress</option><option value="gdocs">Google Docs</option><option value="git">Git</option><option value="error">Failed syncs</option></select></label></p>';
        uasort($items, static function ($a, $b) {
            $order = ['error' => 0, 'both' => 1, 'missing' => 2, 'remote' => 3, 'wp' => 4];
            return [$order[$a['state']] ?? 9, -max($a['wp']['when'] ?? 0, $a['remote']['when'] ?? 0)] <=> [$order[$b['state']] ?? 9, -max($b['wp']['when'] ?? 0, $b['remote']['when'] ?? 0)];
        });
        echo '<table class="widefat striped" id="mcds-changes" style="max-width:1200px"><thead><tr><th style="width:24%">Post</th><th style="width:16%">Where</th><th>What changed</th><th style="width:24%;text-align:right">Action</th></tr></thead><tbody>';
        foreach ($items as $it) {
            $d = $this->core->dest($it['dest']);
            if (!$d) continue;
            $pid = $it['post'];
            $is_docs = $it['dest'] === 'gdocs';
            $remote_name = $is_docs ? 'Google Doc' : 'Git file';
            $badge = [
                'wp'      => ['WordPress is newer', '#dba617'],
                'remote'  => [$remote_name . ' is newer', '#2271b1'],
                'both'    => ['Changed on both sides', '#d63638'],
                'missing' => [$remote_name . ' missing', '#d63638'],
                'error'   => ['Sync failed', '#d63638'],
            ][$it['state']];
            $what = [];
            if ($it['wp']) {
                $rl = $this->revisions_link($pid);
                $what[] = self::line('Edited in WordPress', $it['wp']) . ($rl !== '' ? ' · ' . $rl : '');
            }
            if ($it['remote']) {
                $r = $it['remote'];
                $what[] = self::line($r['detail'] ?: ($is_docs ? 'Edited in Google Docs' : 'Changed in the repository'), $r) . (!empty($r['url']) ? ' · <a target="_blank" rel="noopener" href="' . esc_url($r['url']) . '">' . ($is_docs ? 'Open doc' : 'View change') . ' ↗</a>' : '');
            }
            if ($it['error'] !== '') $what[] = '<span style="color:#b32d2e">' . esc_html($it['error']) . '</span>';
            if ($it['state'] === 'both') $what[] = '<em style="color:#b32d2e">Pick one version: the other side’s edits will be replaced' . ($is_docs ? ' (WordPress keeps the old version in Revisions; the doc keeps it in Version history)' : '') . '.</em>';

            $actions = [];
            $sync = $this->core->sync_url($it['dest'], $pid);
            $published = get_post_status($pid) === 'publish';
            switch ($it['state']) {
                case 'wp':
                    $actions[] = '<a class="button button-primary" href="' . esc_url($sync) . '">Sync to ' . esc_html($d->label()) . '</a>';
                    break;
                case 'remote':
                    if ($is_docs && method_exists($d, 'pull_url') && !Module::is_elementor($pid)) {
                        $actions[] = '<a class="button button-primary mcds-confirm" data-confirm="' . esc_attr('Bring the Google Doc’s changes into “' . Module::plain_title($pid) . '”? The current WordPress version is kept in Revisions.') . '" href="' . esc_url($d->pull_url($pid)) . '">Pull into WordPress</a>';
                    } elseif ($published) {
                        $actions[] = '<a class="button mcds-confirm" data-confirm="' . esc_attr('Replace the ' . $remote_name . ' with the WordPress version? The change made there will be overwritten' . ($is_docs ? '' : ' (it stays in the repository history)') . '.') . '" href="' . esc_url($this->core->sync_url($it['dest'], $pid, true)) . '">Overwrite with WordPress</a>';
                    }
                    break;
                case 'both':
                    if ($is_docs && method_exists($d, 'pull_url') && !Module::is_elementor($pid)) {
                        $actions[] = '<a class="button mcds-confirm" data-confirm="' . esc_attr('Keep the Google Doc version? The WordPress edits will be replaced (restorable from Revisions).') . '" href="' . esc_url($d->pull_url($pid)) . '">Keep doc version</a>';
                    }
                    $actions[] = '<a class="button mcds-confirm" data-confirm="' . esc_attr('Keep the WordPress version? The ' . $remote_name . '’s edits will be overwritten.') . '" href="' . esc_url($this->core->sync_url($it['dest'], $pid, true)) . '">Keep WordPress version</a>';
                    break;
                case 'missing':
                case 'error':
                    if ($published) $actions[] = '<a class="button button-primary" href="' . esc_url($this->core->sync_url($it['dest'], $pid, $it['state'] === 'missing')) . '">' . ($it['state'] === 'missing' ? 'Recreate from WordPress' : 'Retry sync') . '</a>';
                    break;
            }
            $filter = $it['state'] === 'error' ? 'error' : trim((in_array($it['state'], ['wp', 'both'], true) ? 'wp ' : '') . (in_array($it['state'], ['remote', 'both', 'missing'], true) ? $it['dest'] : ''));
            echo '<tr data-filter="' . esc_attr($filter) . '">';
            echo '<td><a href="' . esc_url(get_edit_post_link($pid, 'url')) . '"><strong>' . esc_html(Module::plain_title($pid)) . '</strong></a><br><small style="color:#646970">' . esc_html(ucfirst(get_post_type($pid))) . (get_post_status($pid) !== 'publish' ? ' · ' . esc_html(get_post_status($pid)) : '') . ' · <a href="' . esc_url(add_query_arg(['post_id' => $pid], $this->core->settings_url('changes'))) . '#mcds-log">activity history</a></small></td>';
            echo '<td><strong>' . esc_html($d->label()) . '</strong><br><span style="display:inline-block;margin-top:3px;padding:1px 8px;border-radius:10px;font-size:11px;color:#fff;background:' . esc_attr($badge[1]) . '">' . esc_html($badge[0]) . '</span></td>';
            echo '<td>' . implode('<br>', $what) . '</td>';
            echo '<td style="text-align:right">' . implode(' ', $actions) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo "<script>(function(){var s=document.getElementById('mcds-filter');if(!s)return;s.addEventListener('change',function(){var v=s.value;document.querySelectorAll('#mcds-changes tbody tr').forEach(function(tr){tr.style.display=!v||(' '+tr.getAttribute('data-filter')+' ').indexOf(' '+v+' ')>-1?'':'none';});});})();</script>";
    }
}
