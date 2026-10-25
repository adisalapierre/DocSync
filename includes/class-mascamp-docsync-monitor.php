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
        add_action('admin_post_mascamp_docsync_monitor_sync_new', [$this, 'sync_new_handler']);
        add_action('admin_post_mascamp_docsync_monitor_ignore', [$this, 'ignore_handler']);
        add_action('admin_post_mascamp_docsync_monitor_bulk', [$this, 'bulk_handler']);
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

    /** Published posts/pages with no copy in $d yet (not ignored). */
    private function unsynced_ids(Destination $d) {
        return array_map('intval', get_posts([
            'post_type' => Module::TYPES, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
            'meta_query' => [
                'relation' => 'AND',
                ['key' => $d->keys()['link'], 'compare' => 'NOT EXISTS'],
                ['key' => $d->ignore_key(), 'compare' => 'NOT EXISTS'],
            ],
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
        $linked = (string) $d->meta($post_id, 'link') !== ''; // also clears a duplicate's copied link
        $error = $d->meta($post_id, 'status') === 'Error' ? (string) $d->meta($post_id, 'error') : '';
        $new = null;
        if (!$linked && get_post_status($post_id) === 'publish' && get_post_meta($post_id, $d->ignore_key(), true) !== '1') {
            $from = (int) get_post_meta($post_id, $d->copied_key(), true);
            $new = ['from' => $from, 'detail' => $from
                ? 'Duplicated from “' . Module::plain_title($from) . '”. Needs its own ' . ($d->id() === 'git' ? 'Git file' : 'Google Doc') . '.'
                : 'Published, but not synced to ' . $d->label() . ' yet.'];
        }
        $wp = $linked ? $this->wp_side($d, $post_id) : null;
        if ($error === '' && !$wp && !$remote && !$new) return null;
        if ($error !== '') $state = 'error';
        elseif ($remote && $remote['state'] === 'wrong') $state = 'wrong';
        elseif ($new) $state = 'new';
        elseif ($remote && $remote['state'] === 'missing') $state = 'missing';
        elseif ($wp && $remote) $state = 'both';
        elseif ($wp) $state = 'wp';
        else $state = 'remote';
        return ['post' => (int) $post_id, 'dest' => $d->id(), 'state' => $state, 'wp' => $wp, 'remote' => $remote, 'error' => $error,
            'new' => $new, 'found' => $prev['found'] ?? time()];
    }

    /** Full scan: WordPress side + every destination's remote side. */
    /** Full scan of every destination (hourly cron). */
    public function scan() {
        $n = 0;
        foreach (['gdocs', 'git'] as $id) {
            $r = $this->scan_dest($id);
            if (!is_wp_error($r)) $n += $r;
        }
        return $n;
    }

    /**
     * Scan one destination: WordPress side + its remote copies. Replaces that destination's items.
     * @return int|\WP_Error number of items out of sync for this destination
     */
    public function scan_dest($id) {
        if (function_exists('set_time_limit')) @set_time_limit(600);
        $d = $this->core->dest($id);
        if (!$d) return new \WP_Error('invalid_destination', 'Unknown destination.');
        $data = $this->data();
        $prev = $data['items'];
        foreach (array_keys($data['items']) as $key) if (strpos($key, $id . ':') === 0) unset($data['items'][$key]);
        unset($data['notes'][$id], $data['checked'][$id]);
        if (!$d->is_ready()) { $data['scanned'] = time(); $this->save($data); return 0; }

        $linked = $this->linked_ids($d);
        $label = $id === 'git' ? 'Post to Git file scan' : 'Post to Google Doc scan';
        Log::add('Sync Update', $label . ' started', 0, $id, 'info', sprintf('%d linked items', count($linked)));
        $remote = $d->scan_remote($linked);
        if ($remote['note'] !== '') $data['notes'][$id] = $remote['note'];
        $data['checked'][$id] = count($linked);
        $found = 0;
        foreach (array_unique(array_merge($linked, $this->error_ids($d), $this->unsynced_ids($d))) as $pid) {
            $key = $id . ':' . $pid;
            $item = $this->build_item($d, $pid, $remote['items'][$pid] ?? null, $prev[$key] ?? null);
            if (!$item) continue;
            $data['items'][$key] = $item; $found++;
            $this->log_new($item, $prev[$key] ?? null);
        }
        $data['scanned'] = time();
        $this->save($data);
        Log::add('Sync Update', $label . ' complete: ' . ($found ? sprintf(_n('%d item out of sync', '%d items out of sync', $found), $found) : 'everything in sync'), 0, $id, $found ? 'warning' : 'success', $remote['note']);
        return $found;
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
        if ($item['state'] === 'wrong' && ($prev['state'] ?? '') !== 'wrong') {
            Log::add('Change detected', $target . ' holds another post’s content', $item['post'], $item['dest'], 'error', $item['remote']['detail'] ?? '');
            return;
        }
        if ($item['state'] === 'new' && ($prev['state'] ?? '') !== 'new') {
            Log::add('Change detected', 'Post not synced to ' . $target . ' yet', $item['post'], $item['dest'], 'info', $item['new']['detail'] ?? '');
            return;
        }
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
        $c = ['wp' => 0, 'gdocs' => 0, 'git' => 0, 'error' => 0, 'new' => 0, 'wrong' => 0];
        foreach ($items as $it) {
            if ($it['state'] === 'error') { $c['error']++; continue; }
            if ($it['state'] === 'new') { $c['new']++; continue; }
            if ($it['state'] === 'wrong') { $c['wrong']++; continue; }
            if (in_array($it['state'], ['wp', 'both'], true)) $c['wp']++;
            if (in_array($it['state'], ['remote', 'both', 'missing'], true)) $c[$it['dest']]++;
        }
        $parts = [];
        if ($c['wp']) $parts[] = sprintf(_n('%d edited in WordPress', '%d edited in WordPress', $c['wp']), $c['wp']);
        if ($c['gdocs']) $parts[] = sprintf(_n('%d changed in Google Docs', '%d changed in Google Docs', $c['gdocs']), $c['gdocs']);
        if ($c['git']) $parts[] = sprintf(_n('%d changed in Git', '%d changed in Git', $c['git']), $c['git']);
        if ($c['wrong']) $parts[] = sprintf(_n('%d holds another post’s content', '%d hold another post’s content', $c['wrong']), $c['wrong']);
        if ($c['new']) $parts[] = sprintf('%d not synced yet', $c['new']);
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

    /** Scan now: runs in the background with a progress bar (one step per destination). */
    public function scan_handler() {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_docsync_scan');
        $steps = [];
        foreach (['gdocs', 'git'] as $id) if (($d = $this->core->dest($id)) && $d->is_ready()) $steps[] = ['kind' => 'scan', 'dest' => $id, 'id' => 0];
        if (!$steps) $this->core->notice('error', 'Set up Google Docs or Git first.');
        elseif (!$this->core->start_job('Checking Google Drive and Git for changes', $steps)) $this->core->notice('error', 'Another DocSync job is running. The scan can start when it finishes.');
        wp_safe_redirect($this->core->settings_url('changes')); exit;
    }

    /** Push every item where only WordPress changed (never overwrites remote edits), as a background job with progress. */
    public function sync_wp_handler() {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_docsync_monitor_sync_wp');
        $steps = [];
        foreach ($this->data()['items'] as $it) {
            if ($it['state'] === 'wp' && get_post_status($it['post']) === 'publish') $steps[] = ['kind' => 'sync', 'dest' => $it['dest'], 'id' => (int) $it['post']];
        }
        if (!$steps) $this->core->notice('success', 'Nothing to sync.');
        elseif (!$this->core->start_job(sprintf('Syncing %d WordPress %s', count($steps), _n('change', 'changes', count($steps))), $steps)) $this->core->notice('error', 'Another DocSync job is running. Try again when it finishes.');
        wp_safe_redirect($this->core->settings_url('changes')); exit;
    }

    /** Create copies for every post not synced yet, as a background job. */
    public function sync_new_handler() {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_docsync_monitor_sync_new');
        $steps = [];
        foreach ($this->data()['items'] as $it) {
            if ($it['state'] === 'new' && get_post_status($it['post']) === 'publish') $steps[] = ['kind' => 'sync', 'dest' => $it['dest'], 'id' => (int) $it['post']];
        }
        if (!$steps) $this->core->notice('success', 'Nothing to sync.');
        elseif (!$this->core->start_job(sprintf('Syncing %d not-yet-synced %s', count($steps), _n('item', 'items', count($steps))), $steps)) $this->core->notice('error', 'Another DocSync job is running. Try again when it finishes.');
        wp_safe_redirect($this->core->settings_url('changes')); exit;
    }

    /** Bulk actions on ticked rows of the Changes list → one background job with progress. */
    public function bulk_handler() {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_docsync_monitor_bulk');
        $op = sanitize_key($_POST['bulk_op'] ?? '');
        $sel = array_map('sanitize_text_field', (array) wp_unslash($_POST['sel'] ?? []));
        $items = $this->data()['items'];
        $steps = []; $skipped = []; $ignored = 0;
        foreach ($sel as $key) {
            if (!preg_match('/^(gdocs|git):(\d+)$/', $key, $m) || !isset($items[$key])) continue;
            $it = $items[$key]; $dest = $m[1]; $pid = (int) $m[2];
            $d = $this->core->dest($dest);
            $published = get_post_status($pid) === 'publish';
            switch ($op) {
                case 'fix':
                    if (in_array($it['state'], ['remote', 'both'], true)) { $skipped[] = Module::plain_title($pid) . ' (needs your decision: pull or overwrite)'; break; }
                    if (!$published) { $skipped[] = Module::plain_title($pid) . ' (not published)'; break; }
                    $steps[] = ['kind' => 'sync', 'dest' => $dest, 'id' => $pid, 'force' => in_array($it['state'], ['wrong', 'missing'], true)];
                    break;
                case 'overwrite':
                    if (!$published) { $skipped[] = Module::plain_title($pid) . ' (not published)'; break; }
                    $steps[] = ['kind' => 'sync', 'dest' => $dest, 'id' => $pid, 'force' => true];
                    break;
                case 'pull':
                    if ($dest !== 'gdocs' || !$d->meta($pid, 'link') || Module::is_elementor($pid)) { $skipped[] = Module::plain_title($pid) . ($dest !== 'gdocs' ? ' (Git can’t be pulled)' : (Module::is_elementor($pid) ? ' (Elementor page: one-way)' : ' (no linked doc)')); break; }
                    $steps[] = ['kind' => 'pull', 'dest' => 'gdocs', 'id' => $pid];
                    break;
                case 'ignore':
                    if ($it['state'] !== 'new') { $skipped[] = Module::plain_title($pid) . ' (only “not synced yet” rows can be ignored)'; break; }
                    update_post_meta($pid, $d->ignore_key(), '1');
                    $this->refresh_post($pid);
                    Log::add('Change detected', 'Post set to not sync to ' . Log::target($dest) . ' (ignored)', $pid, $dest, 'info');
                    $ignored++;
                    break;
            }
        }
        $msg = [];
        if ($op === 'ignore') $msg[] = sprintf('%d item(s) ignored.', $ignored);
        if ($steps) {
            $labels = ['fix' => 'Fixing %d selected item(s)', 'overwrite' => 'Overwriting %d copy(ies) with the WordPress version', 'pull' => 'Pulling %d Google Doc(s) into WordPress'];
            if ($this->core->start_job(sprintf($labels[$op], count($steps)), $steps)) $msg[] = sprintf('Started: %d item(s). Progress is shown above.', count($steps));
            else $msg[] = 'Another DocSync job is running. Try again when it finishes.';
        } elseif ($op !== 'ignore' && !$skipped) {
            $msg[] = 'Nothing selected.';
        }
        if ($skipped) $msg[] = count($skipped) . ' skipped: ' . implode('; ', array_slice($skipped, 0, 6)) . (count($skipped) > 6 ? '…' : '');
        $this->core->notice($skipped && !$steps ? 'warning' : 'success', implode(' ', $msg));
        wp_safe_redirect($this->core->settings_url('changes')); exit;
    }

    /** Hide a post from "Not synced yet" for one destination (undo: sync it, which clears the flag). */
    public function ignore_handler() {
        $dest = sanitize_key($_GET['dest'] ?? '');
        $post_id = absint($_GET['post'] ?? 0);
        $d = $this->core->dest($dest);
        if (!$d || !$post_id || !current_user_can('edit_post', $post_id)) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_docsync_ignore_' . $dest . '_' . $post_id);
        update_post_meta($post_id, $d->ignore_key(), '1');
        $this->refresh_post($post_id);
        Log::add('Change detected', 'Post set to not sync to ' . Log::target($dest) . ' (ignored)', $post_id, $dest, 'info');
        $this->core->notice('success', '“' . Module::plain_title($post_id) . '” won’t be listed as not synced to ' . $d->label() . '. Syncing it later turns tracking back on.');
        wp_safe_redirect(wp_get_referer() ?: $this->core->settings_url('changes')); exit;
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
        $running = $this->core->batch_running();
        if ($running) $this->core->render_progress();
        else $this->core->render_last_job();
        echo '<h2>Out of sync</h2>';
        echo '<p>Shows posts and pages whose WordPress version and synced copies no longer match: edited in WordPress since the last sync, edited directly in Google Docs or Git, deleted there, or failed to sync. ';
        echo 'WordPress edits are detected instantly; Google Drive and Git are checked hourly.</p>';
        echo '<p>' . ($running ? '<button class="button" disabled title="Wait for the current job to finish">Scan now</button>' : '<a class="button mcds-run" data-mcds-title="Starting scan…" data-mcds-text="Google Drive and Git are checked in the background with a progress bar. You can leave this page while it runs." href="' . esc_url($scan) . '">Scan now</a>') . ' <span style="color:#646970;margin-left:8px">' . ($data['scanned'] ? 'Last full check: ' . esc_html(self::ago($data['scanned'])) : 'Not scanned yet') . '</span></p>';
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
        $cards = ['wp' => ['WordPress', 'edited here since last sync'], 'gdocs' => ['Google Docs', 'edited, deleted or trashed in Drive'], 'git' => ['Git', 'changed or missing in the repository'], 'new' => ['Not synced yet', 'new or duplicated posts'], 'error' => ['Problems', 'failed syncs or wrong content']];
        $count = ['wp' => 0, 'gdocs' => 0, 'git' => 0, 'new' => 0, 'error' => 0];
        foreach ($items as $it) {
            if ($it['state'] === 'error' || $it['state'] === 'wrong') { $count['error']++; continue; }
            if ($it['state'] === 'new') { $count['new']++; continue; }
            if (in_array($it['state'], ['wp', 'both'], true)) $count['wp']++;
            if (in_array($it['state'], ['remote', 'both', 'missing'], true)) $count[$it['dest']]++;
        }
        echo '<div style="display:flex;flex-wrap:wrap;gap:12px;margin:12px 0 16px">';
        foreach ($cards as $k => $c) {
            $n = $count[$k];
            echo '<div style="flex:1 1 160px;max-width:240px;background:#fff;border:1px solid #c3c4c7;border-top:3px solid ' . ($n ? ($k === 'error' ? '#d63638' : ($k === 'new' ? '#8c8f94' : '#dba617')) : '#00a32a') . ';padding:10px 14px">';
            echo '<div style="font-size:24px;font-weight:600;line-height:1.2">' . (int) $n . '</div><div style="font-weight:600">' . esc_html($c[0]) . '</div><div style="color:#646970;font-size:12px">' . esc_html($n ? $c[1] : 'in sync') . '</div></div>';
        }
        echo '</div>';

        $new_n = count(array_filter($items, static function ($it) { return $it['state'] === 'new'; }));
        if ($new_n && !$running) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=mascamp_docsync_monitor_sync_new'), 'mascamp_docsync_monitor_sync_new');
            echo '<p><a class="button mcds-confirm mcds-run" data-mcds-title="Starting…" data-confirm="' . esc_attr('Create Google Docs / Git files for ' . $new_n . ' post(s) not synced yet?') . '" href="' . esc_url($url) . '">Sync all not-synced-yet (' . (int) $new_n . ')</a> <span style="color:#646970">Or use <em>Ignore</em> on a row for posts you never want synced there.</span></p>';
        }
        $wp_only = count(array_filter($items, static function ($it) { return $it['state'] === 'wp'; }));
        if ($wp_only && !$running) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=mascamp_docsync_monitor_sync_wp'), 'mascamp_docsync_monitor_sync_wp');
            echo '<p><a class="button button-primary mcds-confirm" data-confirm="' . esc_attr('Push ' . $wp_only . ' WordPress change(s) to their Google Docs / Git copies? Items edited on both sides are left for you to decide.') . '" href="' . esc_url($url) . '">Sync all WordPress changes (' . (int) $wp_only . ')</a> <span style="color:#646970">Only items changed in WordPress alone. Anything edited remotely is never overwritten by this button.</span></p>';
        }

        // Filter + table
        echo '<p><label>Show: <select id="mcds-filter"><option value="">All (' . count($items) . ')</option><option value="wp">Edited in WordPress</option><option value="gdocs">Google Docs</option><option value="git">Git</option><option value="new">Not synced yet</option><option value="wrong">Holds another post’s content</option><option value="error">Problems</option></select></label></p>';
        // Bulk bar: acts on the ticked rows (select-all ticks only the rows currently shown by the filter).
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="mcds-bulk-form">';
        echo '<input type="hidden" name="action" value="mascamp_docsync_monitor_bulk">';
        wp_nonce_field('mascamp_docsync_monitor_bulk');
        echo '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 8px">';
        echo '<select name="bulk_op" id="mcds-bulk-op"><option value="">Bulk actions</option>';
        echo '<option value="fix">Fix selected (recommended action for each)</option>';
        echo '<option value="overwrite">Overwrite with WordPress version</option>';
        echo '<option value="pull">Pull Google Doc into WordPress</option>';
        echo '<option value="ignore">Ignore (not synced yet only)</option></select>';
        echo '<button type="submit" class="button"' . ($running ? ' disabled title="Wait for the current job to finish"' : '') . '>Apply</button>';
        echo '<span id="mcds-sel-count" style="color:#646970">0 selected</span>';
        echo '</div>';
        uasort($items, static function ($a, $b) {
            $order = ['wrong' => 0, 'error' => 1, 'both' => 2, 'missing' => 3, 'remote' => 4, 'wp' => 5, 'new' => 6];
            return [$order[$a['state']] ?? 9, -max($a['wp']['when'] ?? 0, $a['remote']['when'] ?? 0)] <=> [$order[$b['state']] ?? 9, -max($b['wp']['when'] ?? 0, $b['remote']['when'] ?? 0)];
        });
        echo '<table class="widefat striped" id="mcds-changes" style="max-width:1200px"><thead><tr><td class="check-column" style="width:28px;padding:8px 0 0 8px"><input type="checkbox" id="mcds-sel-all" title="Select all shown"></td><th style="width:24%">Post</th><th style="width:16%">Where</th><th>What changed</th><th style="width:24%;text-align:right">Action</th></tr></thead><tbody>';
        foreach ($items as $it) {
            $d = $this->core->dest($it['dest']);
            if (!$d) continue;
            $pid = $it['post'];
            $is_docs = $it['dest'] === 'gdocs';
            $remote_name = $is_docs ? 'Google Doc' : 'Git file';
            $confirmed = (int) ($it['remote']['other'] ?? 0) > 0;
            $badge = [
                'wp'      => ['WordPress is newer', '#dba617'],
                'remote'  => [$remote_name . ' is newer', '#2271b1'],
                'both'    => ['Changed on both sides', '#d63638'],
                'missing' => [$remote_name . ' missing', '#d63638'],
                'wrong'   => $confirmed ? ['Holds another post’s content', '#d63638'] : ['Needs checking', '#dba617'],
                'new'     => ['Not synced yet', '#8c8f94'],
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
            if (!empty($it['new'])) $what[] = esc_html($it['new']['detail']) . (!empty($it['new']['from']) ? ' · <a href="' . esc_url(get_edit_post_link($it['new']['from'], 'url')) . '">original</a>' : '');
            if ($it['error'] !== '') $what[] = '<span style="color:#b32d2e">' . esc_html($it['error']) . '</span>';
            if ($it['state'] === 'both') $what[] = '<em style="color:#b32d2e">Pick one version: the other side’s edits will be replaced' . ($is_docs ? ' (WordPress keeps the old version in Revisions; the doc keeps it in Version history)' : '') . '.</em>';

            $actions = [];
            $run = ' data-mcds-title="' . esc_attr('Working on “' . Module::plain_title($pid) . '”…') . '"';
            $sync = $this->core->sync_url($it['dest'], $pid);
            $published = get_post_status($pid) === 'publish';
            switch ($it['state']) {
                case 'wp':
                    $actions[] = '<a class="button button-primary mcds-run"' . $run . ' href="' . esc_url($sync) . '">Sync to ' . esc_html($d->label()) . '</a>';
                    break;
                case 'remote':
                    if ($is_docs && method_exists($d, 'pull_url') && !Module::is_elementor($pid)) {
                        $actions[] = '<a class="button button-primary mcds-confirm mcds-run"' . $run . ' data-confirm="' . esc_attr('Bring the Google Doc’s changes into “' . Module::plain_title($pid) . '”? The current WordPress version is kept in Revisions.') . '" href="' . esc_url($d->pull_url($pid)) . '">Pull into WordPress</a>';
                    } elseif ($published) {
                        $actions[] = '<a class="button mcds-confirm mcds-run"' . $run . ' data-confirm="' . esc_attr('Replace the ' . $remote_name . ' with the WordPress version? The change made there will be overwritten' . ($is_docs ? '' : ' (it stays in the repository history)') . '.') . '" href="' . esc_url($this->core->sync_url($it['dest'], $pid, true)) . '">Overwrite with WordPress</a>';
                    }
                    break;
                case 'both':
                    if ($is_docs && method_exists($d, 'pull_url') && !Module::is_elementor($pid)) {
                        $actions[] = '<a class="button mcds-confirm mcds-run"' . $run . ' data-confirm="' . esc_attr('Keep the Google Doc version? The WordPress edits will be replaced (restorable from Revisions).') . '" href="' . esc_url($d->pull_url($pid)) . '">Keep doc version</a>';
                    }
                    $actions[] = '<a class="button mcds-confirm mcds-run"' . $run . ' data-confirm="' . esc_attr('Keep the WordPress version? The ' . $remote_name . '’s edits will be overwritten.') . '" href="' . esc_url($this->core->sync_url($it['dest'], $pid, true)) . '">Keep WordPress version</a>';
                    break;
                case 'wrong':
                    if ($published) $actions[] = '<a class="button button-primary mcds-confirm mcds-run"' . $run . ' data-confirm="' . esc_attr('Replace the ' . $remote_name . ' with “' . Module::plain_title($pid) . '” from WordPress? The other post’s content in it is removed (it stays in the ' . ($is_docs ? 'doc’s Version history' : 'repository history') . ').') . '" href="' . esc_url($this->core->sync_url($it['dest'], $pid, true)) . '">Restore from WordPress</a>';
                    break;
                case 'new':
                    $actions[] = '<a class="button button-primary mcds-run"' . $run . ' href="' . esc_url($sync) . '">Sync to ' . esc_html($d->label()) . '</a>';
                    $actions[] = '<a class="button-link" style="margin-left:6px" title="Don’t list this post as not synced to ' . esc_attr($d->label()) . '" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=mascamp_docsync_monitor_ignore&dest=' . $it['dest'] . '&post=' . $pid), 'mascamp_docsync_ignore_' . $it['dest'] . '_' . $pid)) . '">Ignore</a>';
                    break;
                case 'missing':
                case 'error':
                    if ($published) $actions[] = '<a class="button button-primary mcds-run"' . $run . ' href="' . esc_url($this->core->sync_url($it['dest'], $pid, $it['state'] === 'missing')) . '">' . ($it['state'] === 'missing' ? 'Recreate from WordPress' : 'Retry sync') . '</a>';
                    break;
            }
            $filter = in_array($it['state'], ['error', 'wrong'], true) ? 'error ' . $it['dest'] : ($it['state'] === 'new' ? 'new ' . $it['dest'] : trim((in_array($it['state'], ['wp', 'both'], true) ? 'wp ' : '') . (in_array($it['state'], ['remote', 'both', 'missing'], true) ? $it['dest'] : '')));
            if ($it['state'] === 'wrong') $filter .= ' wrong';
            echo '<tr data-filter="' . esc_attr($filter) . '">';
            echo '<th scope="row" class="check-column" style="padding:10px 0 0 8px"><input type="checkbox" class="mcds-sel" name="sel[]" value="' . esc_attr($it['dest'] . ':' . $pid) . '"></th>';
            echo '<td><a href=""' . esc_url(get_edit_post_link($pid, 'url')) . '"><strong>' . esc_html(Module::plain_title($pid)) . '</strong></a><br><small style="color:#646970">' . esc_html(ucfirst(get_post_type($pid))) . (get_post_status($pid) !== 'publish' ? ' · ' . esc_html(get_post_status($pid)) : '') . ' · <a href="' . esc_url(add_query_arg(['post_id' => $pid], $this->core->settings_url('changes'))) . '#mcds-log">activity history</a></small></td>';
            echo '<td><strong>' . esc_html($d->label()) . '</strong><br><span style="display:inline-block;margin-top:3px;padding:1px 8px;border-radius:10px;font-size:11px;color:#fff;background:' . esc_attr($badge[1]) . '">' . esc_html($badge[0]) . '</span></td>';
            echo '<td>' . implode('<br>', $what) . '</td>';
            echo '<td style="text-align:right">' . implode(' ', $actions) . '</td></tr>';
        }
        echo '</tbody></table></form>';
        echo "<script>(function(){
  var s=document.getElementById('mcds-filter'),all=document.getElementById('mcds-sel-all'),cnt=document.getElementById('mcds-sel-count'),form=document.getElementById('mcds-bulk-form');
  function rows(){return Array.prototype.slice.call(document.querySelectorAll('#mcds-changes tbody tr'));}
  function count(){var n=document.querySelectorAll('#mcds-changes .mcds-sel:checked').length;if(cnt)cnt.textContent=n+' selected';return n;}
  if(s)s.addEventListener('change',function(){var v=s.value;rows().forEach(function(tr){var show=!v||(' '+tr.getAttribute('data-filter')+' ').indexOf(' '+v+' ')>-1;tr.style.display=show?'':'none';if(!show){var c=tr.querySelector('.mcds-sel');if(c)c.checked=false;}});if(all)all.checked=false;count();});
  if(all)all.addEventListener('change',function(){rows().forEach(function(tr){if(tr.style.display==='none')return;var c=tr.querySelector('.mcds-sel');if(c)c.checked=all.checked;});count();});
  document.addEventListener('change',function(e){if(e.target.classList&&e.target.classList.contains('mcds-sel'))count();});
  if(form)form.addEventListener('submit',function(e){var n=count(),op=document.getElementById('mcds-bulk-op').value;
    if(!op){e.preventDefault();alert('Choose a bulk action first.');return;}
    if(!n){e.preventDefault();alert('Tick at least one row (or use the box in the header to select all shown).');return;}
    var msg={fix:'Run the recommended action on '+n+' item(s)? Rows changed on both sides or edited remotely are skipped, since they need your decision.',overwrite:'Overwrite the Google Doc / Git copy of '+n+' item(s) with the WordPress version? Remote edits in those copies will be replaced (still in their version history).',pull:'Replace the WordPress content of the selected Google Docs item(s) with the doc version? The current versions are kept in Revisions.',ignore:'Stop listing the selected not-synced-yet item(s)?'}[op];
    if(!confirm(msg)){e.preventDefault();}});
})();</script>";
    }
}
