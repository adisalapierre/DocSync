<?php
namespace MasCamp\GoogleDocsSync;
if (!defined('ABSPATH')) exit;

/**
 * MasCamp DocSync — one-way WordPress → Google Docs sync.
 *
 * Nothing syncs unless you choose it:
 *  - "Sync to Google Docs" (one-off) from the post editor box, the Posts/Pages row link, or the bulk action.
 *  - "Keep synced" per post: the linked Google Doc is updated automatically whenever that post is updated.
 */
final class Module {
    const TYPES        = ['post', 'page'];
    const META_DOC     = '_mascamp_gdocs_document_id';
    const META_STATUS  = '_mascamp_docsync_status';
    const META_SYNCED  = '_mascamp_gdocs_last_synced';
    const META_ERROR   = '_mascamp_docsync_error';
    const META_WARN    = '_mascamp_docsync_warnings';
    const META_AUTO    = '_mascamp_docsync_auto';
    const META_SOURCE  = '_mascamp_gdocs_source_url';
    const CRON_HOOK    = 'mascamp_docsync_post';

    private static $instance;
    private $option = 'mascamp_docsync_settings';
    private $token_option = 'mascamp_docsync_tokens';

    public static function instance() { return self::$instance ?: (self::$instance = new self()); }

    private function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_mascamp_gdocs_connect', [$this, 'connect']);
        add_action('admin_post_mascamp_gdocs_callback', [$this, 'oauth_callback']);
        add_action('admin_post_mascamp_gdocs_disconnect', [$this, 'disconnect']);
        add_action('admin_post_mascamp_docsync_now', [$this, 'sync_now']);
        add_action('add_meta_boxes', [$this, 'add_metabox']);
        add_action('save_post', [$this, 'save_metabox'], 10, 2);
        add_action('save_post', [$this, 'queue_on_save'], 20, 2);
        add_action(self::CRON_HOOK, [$this, 'sync_post'], 10, 1);
        add_action('admin_notices', [$this, 'notices']);
        foreach (self::TYPES as $type) {
            add_filter("manage_{$type}_posts_columns", [$this, 'add_column']);
            add_action("manage_{$type}_posts_custom_column", [$this, 'render_column'], 10, 2);
            add_filter("bulk_actions-edit-{$type}", [$this, 'bulk_actions']);
            add_filter("handle_bulk_actions-edit-{$type}", [$this, 'handle_bulk'], 10, 3);
        }
        add_filter('post_row_actions', [$this, 'row_actions'], 10, 2);
        add_filter('page_row_actions', [$this, 'row_actions'], 10, 2);
    }

    /* ---------------------------------------------------------------- Settings */

    private function settings() {
        return wp_parse_args(get_option($this->option, []), [
            'client_id' => '', 'client_secret' => '', 'folder_id' => '', 'auto_sync' => '1',
        ]);
    }
    private function tokens() { return get_option($this->token_option, []); }

    /** Accepts a bare folder ID or a full Google Drive folder URL and returns just the ID. */
    private static function extract_folder_id($value) {
        $value = trim(sanitize_text_field($value));
        if (preg_match('~/folders/([A-Za-z0-9_-]+)~', $value, $m)) return $m[1];
        if (preg_match('~[?&]id=([A-Za-z0-9_-]+)~', $value, $m)) return $m[1];
        return preg_replace('~[^A-Za-z0-9_-]~', '', $value);
    }

    public function admin_menu() {
        add_submenu_page('options-general.php', 'MasCamp DocSync', 'MasCamp DocSync', 'manage_options', 'mascamp-docsync', [$this, 'settings_page']);
    }

    public function register_settings() {
        register_setting('mascamp_docsync', $this->option, [
            'type' => 'array',
            'sanitize_callback' => function ($v) {
                $old = $this->settings();
                $v = is_array($v) ? $v : [];
                $secret = isset($v['client_secret']) ? trim(sanitize_text_field($v['client_secret'])) : '';
                return [
                    'client_id'     => sanitize_text_field($v['client_id'] ?? ''),
                    // A blank secret field keeps the saved secret.
                    'client_secret' => $secret !== '' ? $secret : $old['client_secret'],
                    'folder_id'     => self::extract_folder_id($v['folder_id'] ?? ''),
                    'auto_sync'     => !empty($v['auto_sync']) ? '1' : '0',
                ];
            },
        ]);
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) return;
        $s = $this->settings(); $t = $this->tokens();
        $connected = !empty($t['refresh_token']) || !empty($t['access_token']);
        $name = esc_attr($this->option);
        echo '<div class="wrap"><h1>MasCamp DocSync</h1>';
        echo '<p>Sync chosen WordPress posts and pages to one Google Drive folder as Google Docs. Configure OAuth in Google Cloud Console first.</p>';
        echo '<p><strong>Authorized redirect URI</strong> (paste into your Google OAuth client):<br><input type="text" class="large-text code" readonly onclick="this.select()" value="' . esc_attr($this->redirect_uri()) . '"></p>';
        echo '<form method="post" action="options.php">';
        settings_fields('mascamp_docsync');
        echo '<table class="form-table">';
        echo '<tr><th>OAuth Client ID</th><td><input class="regular-text" name="' . $name . '[client_id]" value="' . esc_attr($s['client_id']) . '"></td></tr>';
        echo '<tr><th>OAuth Client Secret</th><td><input type="password" autocomplete="new-password" class="regular-text" name="' . $name . '[client_secret]" value="" placeholder="' . ($this->client_secret() ? 'Saved — leave blank to keep' : 'Paste client secret') . '"><p class="description">Stored in the WordPress options table. For hardened deployments, define MASCAMP_GDOCS_CLIENT_SECRET in wp-config.php instead.</p></td></tr>';
        echo '<tr><th>Google Drive folder ID</th><td><input class="regular-text" name="' . $name . '[folder_id]" value="' . esc_attr($s['folder_id']) . '"><p class="description">Paste the folder ID or the full Google Drive folder URL; the ID is extracted on save. The connected Google account must be able to edit this folder.</p></td></tr>';
        echo '<tr><th>Automatic sync</th><td><label><input type="checkbox" name="' . $name . '[auto_sync]" value="1" ' . checked($s['auto_sync'], '1', false) . '> Update the Google Doc automatically when a post marked <em>Keep synced</em> is updated</label><p class="description">Posts without <em>Keep synced</em> are never synced automatically. Untick this to pause all automatic syncing.</p></td></tr>';
        echo '</table>';
        submit_button('Save Settings');
        echo '</form>';

        echo '<hr><h2>Google authorization</h2>';
        if ($connected) {
            echo '<p><strong>Connected</strong> — token saved.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mascamp_gdocs_disconnect">';
            wp_nonce_field('mascamp_gdocs_disconnect');
            submit_button('Disconnect Google account', 'secondary');
            echo '</form>';
        } else {
            echo '<p>Authorize the Google account that owns or can edit the destination folder.</p><a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=mascamp_gdocs_connect'), 'mascamp_gdocs_connect')) . '">Connect Google account</a>';
        }

        echo '<hr><h2>Choosing what syncs</h2><ul style="list-style:disc;padding-left:20px">';
        echo '<li><strong>One post:</strong> open it and click <em>Sync to Google Docs now</em> in the Google Docs Sync box, or hover it in Posts/Pages and click <em>Sync to Google Docs</em>.</li>';
        echo '<li><strong>Several posts:</strong> tick them in Posts/Pages, choose <em>Sync to Google Docs</em> from Bulk actions, and click Apply.</li>';
        echo '<li><strong>Keep a post updated:</strong> tick <em>Keep synced</em> in its Google Docs Sync box, or use the <em>Google Docs: keep synced</em> bulk action.</li>';
        echo '<li>Only published posts and pages can be synced. Each post has one linked Google Doc; syncing again replaces that doc\'s contents rather than creating a duplicate.</li>';
        echo '</ul>';
        echo '<h2>How images work</h2><p>Google Docs can embed images only when Google can fetch the image URL. Public JPG, PNG and GIF images from the Media Library work. Images Google cannot fetch, including WebP and protected or localhost URLs, are replaced by a link and recorded as a warning on the post.</p>';
        echo '</div>';
    }

    /* ---------------------------------------------------------------- OAuth */

    private function redirect_uri() { return admin_url('admin-post.php?action=mascamp_gdocs_callback'); }

    public function connect() {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_gdocs_connect');
        $s = $this->settings();
        if (!$s['client_id'] || !$this->client_secret()) wp_die('Add the Google OAuth Client ID and Client Secret in settings first.');
        $state = wp_generate_password(32, false, false);
        set_transient('mascamp_gdocs_oauth_state_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS);
        $url = add_query_arg(array_map('rawurlencode', [
            'client_id' => $s['client_id'], 'redirect_uri' => $this->redirect_uri(), 'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/documents https://www.googleapis.com/auth/drive',
            'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state,
        ]), 'https://accounts.google.com/o/oauth2/v2/auth');
        wp_redirect($url); exit;
    }

    public function oauth_callback() {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        $expected = get_transient('mascamp_gdocs_oauth_state_' . get_current_user_id());
        delete_transient('mascamp_gdocs_oauth_state_' . get_current_user_id());
        if (!$expected || !isset($_GET['state']) || !hash_equals($expected, sanitize_text_field(wp_unslash($_GET['state'])))) wp_die('OAuth state validation failed.', 403);
        if (!empty($_GET['error'])) $this->finish('error', 'Google authorization was cancelled or denied.');
        $code = sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
        if (!$code) wp_die('Missing OAuth authorization code.');
        $s = $this->settings();
        $res = wp_remote_post('https://oauth2.googleapis.com/token', ['timeout' => 20, 'body' => [
            'code' => $code, 'client_id' => $s['client_id'], 'client_secret' => $this->client_secret(),
            'redirect_uri' => $this->redirect_uri(), 'grant_type' => 'authorization_code',
        ]]);
        if (is_wp_error($res)) $this->finish('error', $res->get_error_message());
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (wp_remote_retrieve_response_code($res) >= 300 || empty($body['access_token'])) $this->finish('error', 'Google token exchange failed. Check the OAuth redirect URI and credentials.');
        $old = $this->tokens();
        $save = ['access_token' => $body['access_token'], 'expires_at' => time() + absint($body['expires_in'] ?? 3600) - 60,
            'scope' => sanitize_text_field($body['scope'] ?? '')];
        if (!empty($body['refresh_token'])) $save['refresh_token'] = $body['refresh_token'];
        elseif (!empty($old['refresh_token'])) $save['refresh_token'] = $old['refresh_token'];
        update_option($this->token_option, $save, false);
        $this->finish('success', 'Google account connected.');
    }

    public function disconnect() {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_gdocs_disconnect');
        delete_option($this->token_option);
        $this->finish('success', 'Google account disconnected.');
    }

    private function finish($type, $message) {
        $this->notice($type, $message);
        wp_safe_redirect(admin_url('options-general.php?page=mascamp-docsync')); exit;
    }

    private function client_secret() {
        if (defined('MASCAMP_GDOCS_CLIENT_SECRET') && MASCAMP_GDOCS_CLIENT_SECRET) return MASCAMP_GDOCS_CLIENT_SECRET;
        return $this->settings()['client_secret'];
    }

    private function access_token() {
        $t = $this->tokens();
        if (empty($t['access_token'])) return new \WP_Error('not_connected', 'Connect a Google account first (Settings → MasCamp DocSync).');
        if (!empty($t['expires_at']) && $t['expires_at'] > time() + 30) return $t['access_token'];
        if (empty($t['refresh_token'])) return new \WP_Error('refresh_missing', 'Reconnect the Google account to renew authorization.');
        $s = $this->settings();
        $r = wp_remote_post('https://oauth2.googleapis.com/token', ['timeout' => 20, 'body' => [
            'client_id' => $s['client_id'], 'client_secret' => $this->client_secret(),
            'refresh_token' => $t['refresh_token'], 'grant_type' => 'refresh_token',
        ]]);
        if (is_wp_error($r)) return $r;
        $b = json_decode(wp_remote_retrieve_body($r), true);
        if (wp_remote_retrieve_response_code($r) >= 300 || empty($b['access_token'])) return new \WP_Error('refresh_failed', 'Google token refresh failed. Reconnect the Google account.');
        $t['access_token'] = $b['access_token'];
        $t['expires_at'] = time() + absint($b['expires_in'] ?? 3600) - 60;
        update_option($this->token_option, $t, false);
        return $t['access_token'];
    }

    /* ---------------------------------------------------------------- Editor box */

    public function add_metabox() {
        foreach (self::TYPES as $type) add_meta_box('mascamp-docsync', 'Google Docs Sync', [$this, 'metabox'], $type, 'side', 'high');
    }

    private function sync_url($post_id) {
        return wp_nonce_url(admin_url('admin-post.php?action=mascamp_docsync_now&post=' . absint($post_id)), 'mascamp_docsync_now_' . absint($post_id));
    }
    private static function doc_url($doc_id) { return 'https://docs.google.com/document/d/' . rawurlencode($doc_id) . '/edit'; }

    public function metabox($post) {
        if (!current_user_can('edit_post', $post->ID)) return;
        $id     = get_post_meta($post->ID, self::META_DOC, true);
        $status = get_post_meta($post->ID, self::META_STATUS, true);
        $synced = get_post_meta($post->ID, self::META_SYNCED, true);
        $error  = get_post_meta($post->ID, self::META_ERROR, true);
        $warns  = (array) get_post_meta($post->ID, self::META_WARN, true);
        $auto   = get_post_meta($post->ID, self::META_AUTO, true) === '1';
        wp_nonce_field('mascamp_gdocs_metabox', 'mascamp_gdocs_metabox_nonce');

        echo '<p>Status: <strong>' . esc_html($status ?: 'Not synced') . '</strong></p>';
        if ($synced) echo '<p>Last synced: ' . esc_html($synced) . '</p>';
        if ($id) echo '<p><a target="_blank" rel="noopener" href="' . esc_url(self::doc_url($id)) . '">Open Google Doc ↗</a></p>';
        if ($error) echo '<p style="color:#b32d2e">' . esc_html($error) . '</p>';
        $warns = array_filter($warns);
        if ($warns) {
            echo '<details><summary style="color:#996800;cursor:pointer">' . count($warns) . ' warning(s)</summary><ul style="font-size:12px">';
            foreach ($warns as $w) echo '<li>' . esc_html($w) . '</li>';
            echo '</ul></details>';
        }

        if ($post->post_status === 'publish') {
            echo '<p><a class="button button-primary" href="' . esc_url($this->sync_url($post->ID)) . '">Sync to Google Docs now</a></p>';
            echo '<p class="description">Copies the last <em>saved</em> version. Update the post first if you have unsaved changes.</p>';
        } else {
            echo '<p class="description">Publish this first. Only published posts and pages can be synced.</p>';
        }

        echo '<p><label><input type="checkbox" name="mascamp_docsync_auto" value="1" ' . checked($auto, true, false) . '> <strong>Keep synced</strong>: update the Google Doc automatically whenever this is updated</label></p>';
        if ($this->settings()['auto_sync'] !== '1') echo '<p class="description">Automatic sync is currently paused in Settings → MasCamp DocSync.</p>';
    }

    public function save_metabox($post_id, $post) {
        if (!isset($_POST['mascamp_gdocs_metabox_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mascamp_gdocs_metabox_nonce'])), 'mascamp_gdocs_metabox')) return;
        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) return;
        if (!($post instanceof \WP_Post) || !in_array($post->post_type, self::TYPES, true) || !current_user_can('edit_post', $post_id)) return;
        if (!empty($_POST['mascamp_docsync_auto'])) update_post_meta($post_id, self::META_AUTO, '1');
        else delete_post_meta($post_id, self::META_AUTO);
    }

    /* ---------------------------------------------------------------- Posts list: column, row link, bulk actions */

    public function add_column($cols) { $cols['mascamp_docsync'] = 'Google Doc'; return $cols; }

    public function render_column($col, $post_id) {
        if ($col !== 'mascamp_docsync') return;
        $status = get_post_meta($post_id, self::META_STATUS, true);
        $doc    = get_post_meta($post_id, self::META_DOC, true);
        $auto   = get_post_meta($post_id, self::META_AUTO, true) === '1';
        $color  = ['Synced' => '#008a20', 'Synced with warnings' => '#996800', 'Error' => '#b32d2e'][$status] ?? '#50575e';
        echo '<span style="color:' . esc_attr($color) . '">' . esc_html($status ?: '—') . '</span>';
        if ($doc) echo '<br><a target="_blank" rel="noopener" href="' . esc_url(self::doc_url($doc)) . '">Open ↗</a>';
        if ($auto) echo '<br><small>Keep synced</small>';
        if ($status === 'Error' && ($e = get_post_meta($post_id, self::META_ERROR, true))) echo '<br><small style="color:#b32d2e">' . esc_html(wp_trim_words($e, 12)) . '</small>';
    }

    public function row_actions($actions, $post) {
        if (!in_array($post->post_type, self::TYPES, true) || !current_user_can('edit_post', $post->ID)) return $actions;
        if ($post->post_status === 'publish') $actions['mascamp_docsync'] = '<a href="' . esc_url($this->sync_url($post->ID)) . '">Sync to Google Docs</a>';
        if ($doc = get_post_meta($post->ID, self::META_DOC, true)) $actions['mascamp_docsync_open'] = '<a target="_blank" rel="noopener" href="' . esc_url(self::doc_url($doc)) . '">Open Google Doc</a>';
        return $actions;
    }

    public function bulk_actions($actions) {
        $actions['mascamp_docsync_sync']     = 'Sync to Google Docs';
        $actions['mascamp_docsync_auto_on']  = 'Google Docs: keep synced';
        $actions['mascamp_docsync_auto_off'] = 'Google Docs: stop keeping synced';
        return $actions;
    }

    public function handle_bulk($redirect, $action, $ids) {
        if (strpos($action, 'mascamp_docsync_') !== 0) return $redirect;
        $count = 0; $skipped = 0; $i = 0;
        foreach ((array) $ids as $id) {
            $id = absint($id);
            if (!current_user_can('edit_post', $id)) { $skipped++; continue; }
            if ($action === 'mascamp_docsync_sync') {
                if (get_post_status($id) !== 'publish') { $skipped++; continue; }
                $this->queue($id, 5 + ($i++ * 8)); // stagger to stay under Google's per-minute write quota
                $count++;
            } elseif ($action === 'mascamp_docsync_auto_on') {
                update_post_meta($id, self::META_AUTO, '1'); $count++;
            } elseif ($action === 'mascamp_docsync_auto_off') {
                delete_post_meta($id, self::META_AUTO); $count++;
            }
        }
        $redirect = remove_query_arg(['mascamp_docsync_bulk', 'mascamp_docsync_n', 'mascamp_docsync_skip'], $redirect);
        return add_query_arg(['mascamp_docsync_bulk' => $action, 'mascamp_docsync_n' => $count, 'mascamp_docsync_skip' => $skipped], $redirect);
    }

    /* ---------------------------------------------------------------- Triggers */

    public function sync_now() {
        $post_id = absint($_GET['post'] ?? 0);
        if (!$post_id || !current_user_can('edit_post', $post_id)) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_docsync_now_' . $post_id);
        $result = $this->perform_sync($post_id);
        if ($result === true) {
            $warns = array_filter((array) get_post_meta($post_id, self::META_WARN, true));
            $doc = get_post_meta($post_id, self::META_DOC, true);
            $link = $doc ? ' <a target="_blank" rel="noopener" href="' . esc_url(self::doc_url($doc)) . '">Open Google Doc ↗</a>' : '';
            $this->notice($warns ? 'warning' : 'success', '“' . esc_html($this->plain_title($post_id)) . '” synced to Google Docs' . ($warns ? ' with ' . count($warns) . ' warning(s) — see the Google Docs Sync box on the post.' : '.') . $link, true);
        } else {
            $this->notice('error', 'Google Docs sync failed: ' . esc_html(is_wp_error($result) ? $result->get_error_message() : 'unknown error'), true);
        }
        $back = wp_get_referer() ?: get_edit_post_link($post_id, 'url');
        wp_safe_redirect($back); exit;
    }

    public function queue_on_save($post_id, $post) {
        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !($post instanceof \WP_Post)) return;
        if (!in_array($post->post_type, self::TYPES, true) || $post->post_status !== 'publish') return;
        if ($this->settings()['auto_sync'] !== '1' || get_post_meta($post_id, self::META_AUTO, true) !== '1') return;
        if (!current_user_can('edit_post', $post_id)) return;
        $this->queue($post_id);
    }

    private function queue($post_id, $delay = 15) {
        if (!wp_next_scheduled(self::CRON_HOOK, [$post_id])) wp_schedule_single_event(time() + $delay, self::CRON_HOOK, [$post_id]);
        update_post_meta($post_id, self::META_STATUS, 'Queued');
        delete_post_meta($post_id, self::META_ERROR);
    }

    public function sync_post($post_id) { $this->perform_sync(absint($post_id)); }

    /* ---------------------------------------------------------------- Google API */

    private function api($method, $url, $body = null) {
        $token = $this->access_token();
        if (is_wp_error($token)) return $token;
        $args = ['method' => $method, 'timeout' => 60, 'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json']];
        if ($body !== null) $args['body'] = wp_json_encode($body);
        $r = wp_remote_request($url, $args);
        if (is_wp_error($r)) return $r;
        $code = (int) wp_remote_retrieve_response_code($r);
        $data = json_decode(wp_remote_retrieve_body($r), true);
        if ($code < 200 || $code >= 300) return new \WP_Error('google_api_error', $data['error']['message'] ?? ('Google API returned HTTP ' . $code), ['status' => $code]);
        return $data ?: [];
    }

    private static function http_status($error) {
        $d = is_wp_error($error) ? $error->get_error_data() : null;
        return is_array($d) ? (int) ($d['status'] ?? 0) : 0;
    }

    private function plain_title($post_id) {
        $t = html_entity_decode(wp_strip_all_tags(get_the_title($post_id)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($t) !== '' ? trim($t) : 'WordPress post ' . $post_id;
    }

    /* ---------------------------------------------------------------- Sync */

    private function perform_sync($post_id) {
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, self::TYPES, true)) return new \WP_Error('invalid_post', 'Only posts and pages can be synced.');
        if ($post->post_status !== 'publish') return $this->mark_error($post_id, new \WP_Error('not_published', 'Publish the post or page before syncing.'));
        if (!wp_doing_cron() && !current_user_can('edit_post', $post_id)) return new \WP_Error('forbidden', 'You cannot edit this post.');
        $s = $this->settings();
        if (!$s['folder_id']) return $this->mark_error($post_id, new \WP_Error('folder_missing', 'Set the Google Drive folder ID in Settings → MasCamp DocSync.'));
        if (function_exists('set_time_limit')) @set_time_limit(300);

        update_post_meta($post_id, self::META_STATUS, 'Syncing');
        delete_post_meta($post_id, self::META_ERROR);
        $title = $this->plain_title($post_id);
        $source = get_permalink($post_id);

        // Reuse the linked doc unless it was deleted or trashed in Drive.
        $doc_id = get_post_meta($post_id, self::META_DOC, true);
        if ($doc_id) {
            $file = $this->api('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($doc_id) . '?fields=id,name,trashed&supportsAllDrives=true');
            if (is_wp_error($file)) {
                if (self::http_status($file) !== 404) return $this->mark_error($post_id, $file);
                $doc_id = '';
            } elseif (!empty($file['trashed'])) {
                $doc_id = '';
            } elseif (($file['name'] ?? '') !== $title) {
                $this->api('PATCH', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($doc_id) . '?supportsAllDrives=true', ['name' => $title]);
            }
            if (!$doc_id) delete_post_meta($post_id, self::META_DOC);
        }
        if (!$doc_id) {
            $created = $this->api('POST', 'https://www.googleapis.com/drive/v3/files?fields=id&supportsAllDrives=true', [
                'name' => $title, 'mimeType' => 'application/vnd.google-apps.document', 'parents' => [$s['folder_id']],
                'description' => 'Synced from WordPress ' . $post->post_type . ' ID ' . $post_id . ' — ' . $source,
            ]);
            if (is_wp_error($created)) {
                if (self::http_status($created) === 404) $created = new \WP_Error('folder_not_found', 'Google Drive folder not found. Check the folder ID and that the connected Google account can edit it.');
                return $this->mark_error($post_id, $created);
            }
            if (empty($created['id'])) return $this->mark_error($post_id, new \WP_Error('create_failed', 'Google Drive did not return a document ID.'));
            $doc_id = sanitize_text_field($created['id']);
            update_post_meta($post_id, self::META_DOC, $doc_id);
        }

        $doc = $this->api('GET', 'https://docs.googleapis.com/v1/documents/' . rawurlencode($doc_id) . '?fields=body.content.endIndex');
        if (is_wp_error($doc)) return $this->mark_error($post_id, $doc);
        $content = $doc['body']['content'] ?? [];
        $last = $content ? end($content) : null;
        $doc_end = (int) ($last['endIndex'] ?? 2);

        $c = Converter::convert($title, $this->content_html($post_id), $source, get_the_post_thumbnail_url($post_id, 'full') ?: '');
        $end = $c['end']; // index just after the inserted text

        // 1) Replace text and formatting in one atomic batch.
        $req = [];
        if ($doc_end - 1 > 1) $req[] = ['deleteContent' => ['range' => ['startIndex' => 1, 'endIndex' => $doc_end - 1]]];
        $req[] = ['insertText' => ['location' => ['index' => 1], 'text' => $c['text']]];
        $req[] = ['updateParagraphStyle' => ['range' => ['startIndex' => 1, 'endIndex' => $end], 'paragraphStyle' => ['namedStyleType' => 'NORMAL_TEXT'], 'fields' => 'namedStyleType']];
        $req[] = ['deleteParagraphBullets' => ['range' => ['startIndex' => 1, 'endIndex' => $end]]];
        $req[] = ['updateTextStyle' => ['range' => ['startIndex' => 1, 'endIndex' => $end], 'textStyle' => new \stdClass(), 'fields' => 'bold,italic,underline,link']];
        foreach ($c['paragraphs'] as $p) {
            if ($p['type'] !== 'NORMAL_TEXT') $req[] = ['updateParagraphStyle' => ['range' => ['startIndex' => $p['start'], 'endIndex' => $p['end']], 'paragraphStyle' => ['namedStyleType' => $p['type']], 'fields' => 'namedStyleType']];
        }
        foreach ($c['styles'] as $st) {
            $req[] = ['updateTextStyle' => ['range' => ['startIndex' => $st['start'], 'endIndex' => $st['end']], 'textStyle' => $st['style'], 'fields' => implode(',', array_keys($st['style']))]];
        }
        foreach ($c['lists'] as $l) {
            $req[] = ['createParagraphBullets' => ['range' => ['startIndex' => $l['start'], 'endIndex' => $l['end']], 'bulletPreset' => $l['preset']]];
        }
        $updated = $this->api('POST', 'https://docs.googleapis.com/v1/documents/' . rawurlencode($doc_id) . ':batchUpdate', ['requests' => $req]);
        if (is_wp_error($updated)) return $this->mark_error($post_id, $updated);

        // 2) Images, last-to-first so earlier positions stay valid. One bad image must not fail the whole sync.
        $warnings = [];
        $images = $c['images'];
        usort($images, static function ($a, $b) { return $b['index'] <=> $a['index']; });
        if ($images) {
            $all = array_map(static function ($img) {
                return ['insertInlineImage' => ['location' => ['index' => $img['index']], 'uri' => $img['url'], 'objectSize' => ['width' => ['magnitude' => $img['width'], 'unit' => 'PT']]]];
            }, $images);
            $ok = $this->api('POST', 'https://docs.googleapis.com/v1/documents/' . rawurlencode($doc_id) . ':batchUpdate', ['requests' => $all]);
            if (is_wp_error($ok)) {
                foreach ($images as $k => $img) {
                    $one = $this->api('POST', 'https://docs.googleapis.com/v1/documents/' . rawurlencode($doc_id) . ':batchUpdate', ['requests' => [$all[$k]]]);
                    if (is_wp_error($one)) {
                        $warnings[] = 'Image skipped: ' . $img['url'] . ' (' . $one->get_error_message() . ')';
                        $label = '[Image: ' . $img['url'] . ']';
                        $this->api('POST', 'https://docs.googleapis.com/v1/documents/' . rawurlencode($doc_id) . ':batchUpdate', ['requests' => [
                            ['insertText' => ['location' => ['index' => $img['index']], 'text' => $label]],
                            ['updateTextStyle' => ['range' => ['startIndex' => $img['index'], 'endIndex' => $img['index'] + Converter::u16len($label)], 'textStyle' => ['link' => ['url' => $img['url']]], 'fields' => 'link']],
                        ]]);
                    }
                }
            }
        }

        update_post_meta($post_id, self::META_STATUS, $warnings ? 'Synced with warnings' : 'Synced');
        update_post_meta($post_id, self::META_SYNCED, current_time('mysql'));
        if ($warnings) update_post_meta($post_id, self::META_WARN, $warnings); else delete_post_meta($post_id, self::META_WARN);
        delete_post_meta($post_id, self::META_ERROR);
        update_post_meta($post_id, self::META_SOURCE, esc_url_raw($source));
        return true;
    }

    private function content_html($post_id) {
        $post = get_post($post_id);
        $GLOBALS['post'] = $post; // some content filters/shortcodes expect the global post
        setup_postdata($post);
        $html = '';
        // Elementor pages keep their real layout in _elementor_data; post_content is only a simplified fallback.
        if ($this->is_elementor($post_id)) {
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

    private function is_elementor($post_id) {
        return class_exists('\Elementor\Plugin')
            && get_post_meta($post_id, '_elementor_edit_mode', true) === 'builder'
            && isset(\Elementor\Plugin::instance()->frontend);
    }

    private function mark_error($post_id, $error) {
        $message = is_wp_error($error) ? $error->get_error_message() : 'Unknown sync error';
        update_post_meta($post_id, self::META_STATUS, 'Error');
        update_post_meta($post_id, self::META_ERROR, sanitize_text_field($message));
        return is_wp_error($error) ? $error : new \WP_Error('sync_error', $message);
    }

    /* ---------------------------------------------------------------- Notices */

    private function notice($type, $message, $is_html = false) {
        set_transient('mascamp_gdocs_notice_' . get_current_user_id(), [$type, $message, $is_html], 120);
    }

    public function notices() {
        $key = 'mascamp_gdocs_notice_' . get_current_user_id();
        $n = get_transient($key);
        if ($n) {
            delete_transient($key);
            $type = in_array($n[0], ['success', 'warning', 'error'], true) ? $n[0] : 'info';
            $msg = !empty($n[2]) ? wp_kses($n[1], ['a' => ['href' => [], 'target' => [], 'rel' => []], 'strong' => [], 'em' => []]) : esc_html($n[1]);
            echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . $msg . '</p></div>';
        }
        if (!empty($_GET['mascamp_docsync_bulk'])) {
            $action = sanitize_key($_GET['mascamp_docsync_bulk']);
            $count = absint($_GET['mascamp_docsync_n'] ?? 0);
            $skip = absint($_GET['mascamp_docsync_skip'] ?? 0);
            $text = [
                'mascamp_docsync_sync'     => '%d item(s) queued for Google Docs sync. Refresh in a minute to see the results in the Google Doc column.',
                'mascamp_docsync_auto_on'  => '%d item(s) set to keep synced with Google Docs.',
                'mascamp_docsync_auto_off' => '%d item(s) will no longer sync automatically.',
            ][$action] ?? null;
            if ($text) {
                $msg = sprintf($text, $count) . ($skip ? ' ' . sprintf('%d skipped (not published or not editable).', $skip) : '');
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
            }
        }
    }
}
