<?php
namespace MasCamp\GoogleDocsSync;
if (!defined('ABSPATH')) exit;

/** Google Docs destination: one Google Doc per post in a chosen Drive folder. */
final class GDocsDestination extends Destination {
    private $option = 'mascamp_docsync_settings';
    private $token_option = 'mascamp_docsync_tokens';

    public function id() { return 'gdocs'; }
    public function label() { return 'Google Docs'; }
    public function short_label() { return 'Docs'; }

    public function keys() {
        // Names kept from v0.1.x so existing sync history carries over.
        return [
            'status' => '_mascamp_docsync_status',
            'synced' => '_mascamp_gdocs_last_synced',
            'error'  => '_mascamp_docsync_error',
            'warn'   => '_mascamp_docsync_warnings',
            'auto'   => '_mascamp_docsync_auto',
            'link'   => '_mascamp_gdocs_document_id',
        ];
    }

    public function hooks() {
        add_action('admin_post_mascamp_gdocs_connect', [$this, 'connect']);
        add_action('admin_post_mascamp_gdocs_callback', [$this, 'oauth_callback']);
        add_action('admin_post_mascamp_gdocs_disconnect', [$this, 'disconnect']);
    }

    private function settings() {
        return wp_parse_args(get_option($this->option, []), [
            'client_id' => '', 'client_secret' => '', 'folder_id' => '', 'auto_sync' => '1', 'images' => 'embed',
        ]);
    }
    private function tokens() { return get_option($this->token_option, []); }

    public function ready() {
        $s = $this->settings(); $t = $this->tokens();
        if (!$s['client_id'] || !$this->client_secret()) return new \WP_Error('gdocs_setup', 'Google Docs is not set up: add the OAuth Client ID and Secret in Settings → MasCamp DocSync.');
        if (!$s['folder_id']) return new \WP_Error('gdocs_folder', 'Set the Google Drive folder in Settings → MasCamp DocSync.');
        if (empty($t['refresh_token']) && empty($t['access_token'])) return new \WP_Error('gdocs_connect', 'Connect a Google account in Settings → MasCamp DocSync.');
        return true;
    }

    public function auto_enabled() { return $this->settings()['auto_sync'] === '1'; }

    public function view_url($post_id) {
        $id = $this->meta($post_id, 'link');
        return $id ? 'https://docs.google.com/document/d/' . rawurlencode($id) . '/edit' : '';
    }

    /** Accepts a bare folder ID or a full Google Drive folder URL and returns just the ID. */
    private static function extract_folder_id($value) {
        $value = trim(sanitize_text_field($value));
        if (preg_match('~/folders/([A-Za-z0-9_-]+)~', $value, $m)) return $m[1];
        if (preg_match('~[?&]id=([A-Za-z0-9_-]+)~', $value, $m)) return $m[1];
        return preg_replace('~[^A-Za-z0-9_-]~', '', $value);
    }

    /* ---------------------------------------------------------------- Settings */

    public function register_settings() {
        register_setting('mascamp_docsync', $this->option, [
            'type' => 'array',
            'sanitize_callback' => function ($v) {
                $old = $this->settings();
                $v = is_array($v) ? $v : [];
                return [
                    'client_id'     => sanitize_text_field($v['client_id'] ?? ''),
                    'client_secret' => self::keep_secret($v['client_secret'] ?? null, $old['client_secret']),
                    'folder_id'     => self::extract_folder_id($v['folder_id'] ?? ''),
                    'auto_sync'     => !empty($v['auto_sync']) ? '1' : '0',
                    'images'        => ($v['images'] ?? 'embed') === 'link' ? 'link' : 'embed',
                ];
            },
        ]);
    }

    public function render_settings() {
        $s = $this->settings(); $t = $this->tokens();
        $connected = !empty($t['refresh_token']) || !empty($t['access_token']);
        $name = esc_attr($this->option);
        echo '<p><strong>Authorized redirect URI</strong> (paste into your Google OAuth client):<br><input type="text" class="large-text code" readonly onclick="this.select()" value="' . esc_attr($this->redirect_uri()) . '"></p>';
        echo '<form method="post" action="options.php">';
        settings_fields('mascamp_docsync');
        echo '<table class="form-table">';
        echo '<tr><th>OAuth Client ID</th><td><input class="regular-text" name="' . $name . '[client_id]" value="' . esc_attr($s['client_id']) . '"></td></tr>';
        echo '<tr><th>OAuth Client Secret</th><td><input type="password" autocomplete="new-password" class="regular-text" name="' . $name . '[client_secret]" value="" placeholder="' . ($this->client_secret() ? 'Saved — leave blank to keep' : 'Paste client secret') . '"><p class="description">Stored in the WordPress options table. For hardened deployments, define MASCAMP_GDOCS_CLIENT_SECRET in wp-config.php instead.</p></td></tr>';
        echo '<tr><th>Google Drive folder</th><td><input class="regular-text" name="' . $name . '[folder_id]" value="' . esc_attr($s['folder_id']) . '"><p class="description">Paste the folder ID or the full Google Drive folder URL; the ID is extracted on save. The connected Google account must be able to edit this folder.</p></td></tr>';
        echo '<tr><th>Images</th><td><fieldset>';
        echo '<label><input type="radio" name="' . $name . '[images]" value="embed" ' . checked($s['images'], 'embed', false) . '> Show images in the doc</label><br>';
        echo '<label><input type="radio" name="' . $name . '[images]" value="link" ' . checked($s['images'], 'link', false) . '> Link to images only (adds an “Image: …” link to the website URL)</label>';
        echo '<p class="description">Either way, no image files are added to your Drive folder. Shown images are stored inside the doc itself.</p></fieldset></td></tr>';
        echo '<tr><th>Automatic sync</th><td><label><input type="checkbox" name="' . $name . '[auto_sync]" value="1" ' . checked($s['auto_sync'], '1', false) . '> Update the Google Doc automatically when a post marked <em>Keep synced</em> is updated</label><p class="description">Untick to pause all automatic Google Docs syncing.</p></td></tr>';
        echo '</table>';
        submit_button('Save Google Docs settings');
        echo '</form>';

        echo '<hr><h2>Google authorization</h2>';
        if ($connected) {
            echo '<p><strong style="color:#008a20">Connected</strong>: token saved.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mascamp_gdocs_disconnect">';
            wp_nonce_field('mascamp_gdocs_disconnect');
            submit_button('Disconnect Google account', 'secondary');
            echo '</form>';
        } else {
            echo '<p>Authorize the Google account that owns or can edit the destination folder.</p><a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=mascamp_gdocs_connect'), 'mascamp_gdocs_connect')) . '">Connect Google account</a>';
        }
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
        $this->core->notice($type, $message);
        wp_safe_redirect($this->core->settings_url('gdocs')); exit;
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

    private function rate_limited($error) {
        return new \WP_Error('rate_limited', 'Google rate limit reached. Wait a minute and sync again.', ['status' => 429, 'google' => $error->get_error_message()]);
    }

    /* ---------------------------------------------------------------- Sync */

    public function sync(\WP_Post $post) {
        $post_id = $post->ID;
        $s = $this->settings();
        $title = Module::plain_title($post_id);
        $source = get_permalink($post_id);
        $docs = 'https://docs.googleapis.com/v1/documents/';

        // Reuse the linked doc unless it was deleted or trashed in Drive.
        $doc_id = $this->meta($post_id, 'link');
        if ($doc_id) {
            $file = $this->api('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($doc_id) . '?fields=id,name,trashed&supportsAllDrives=true');
            if (is_wp_error($file)) {
                if (self::http_status($file) !== 404) return $file;
                $doc_id = '';
            } elseif (!empty($file['trashed'])) {
                $doc_id = '';
            } elseif (($file['name'] ?? '') !== $title) {
                $this->api('PATCH', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($doc_id) . '?supportsAllDrives=true', ['name' => $title]);
            }
            if (!$doc_id) $this->del_meta($post_id, 'link');
        }
        if (!$doc_id) {
            $created = $this->api('POST', 'https://www.googleapis.com/drive/v3/files?fields=id&supportsAllDrives=true', [
                'name' => $title, 'mimeType' => 'application/vnd.google-apps.document', 'parents' => [$s['folder_id']],
                'description' => 'Synced from WordPress ' . $post->post_type . ' ID ' . $post_id . ' — ' . $source,
            ]);
            if (is_wp_error($created)) {
                if (self::http_status($created) === 404) return new \WP_Error('folder_not_found', 'Google Drive folder not found. Check the folder ID and that the connected Google account can edit it.');
                return $created;
            }
            if (empty($created['id'])) return new \WP_Error('create_failed', 'Google Drive did not return a document ID.');
            $doc_id = sanitize_text_field($created['id']);
            $this->set_meta($post_id, 'link', $doc_id);
        }

        $doc = $this->api('GET', $docs . rawurlencode($doc_id) . '?fields=body.content.endIndex');
        if (is_wp_error($doc)) return $doc;
        $content = $doc['body']['content'] ?? [];
        $last = $content ? end($content) : null;
        $doc_end = (int) ($last['endIndex'] ?? 2);

        $c = Converter::convert($title, Module::content_html($post_id), $source, get_the_post_thumbnail_url($post_id, 'full') ?: '', ['images' => $s['images']]);
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
        $updated = $this->api('POST', $docs . rawurlencode($doc_id) . ':batchUpdate', ['requests' => $req]);
        if (is_wp_error($updated)) return self::http_status($updated) === 429 ? $this->rate_limited($updated) : $updated;

        // 2) Images, last-to-first so earlier positions stay valid. One bad image must not fail the whole sync.
        $warnings = [];
        $images = $c['images'];
        usort($images, static function ($a, $b) { return $b['index'] <=> $a['index']; });
        if ($images) {
            $all = array_map(static function ($img) {
                return ['insertInlineImage' => ['location' => ['index' => $img['index']], 'uri' => $img['url'], 'objectSize' => ['width' => ['magnitude' => $img['width'], 'unit' => 'PT']]]];
            }, $images);
            $ok = $this->api('POST', $docs . rawurlencode($doc_id) . ':batchUpdate', ['requests' => $all]);
            if (is_wp_error($ok) && self::http_status($ok) === 429) return $this->rate_limited($ok);
            if (is_wp_error($ok)) {
                foreach ($images as $k => $img) {
                    $one = $this->api('POST', $docs . rawurlencode($doc_id) . ':batchUpdate', ['requests' => [$all[$k]]]);
                    // Rate limit isn't the image's fault: stop and let the whole post be retried later.
                    if (is_wp_error($one) && self::http_status($one) === 429) return $this->rate_limited($one);
                    if (is_wp_error($one)) {
                        $warnings[] = 'Image skipped: ' . $img['url'] . ' (' . $one->get_error_message() . ')';
                        $label = '[Image: ' . $img['url'] . ']';
                        $this->api('POST', $docs . rawurlencode($doc_id) . ':batchUpdate', ['requests' => [
                            ['insertText' => ['location' => ['index' => $img['index']], 'text' => $label]],
                            ['updateTextStyle' => ['range' => ['startIndex' => $img['index'], 'endIndex' => $img['index'] + Converter::u16len($label)], 'textStyle' => ['link' => ['url' => $img['url']]], 'fields' => 'link']],
                        ]]);
                    }
                }
            }
        }
        update_post_meta($post_id, '_mascamp_gdocs_source_url', esc_url_raw($source));
        return ['status' => $warnings ? 'Synced with warnings' : 'Synced', 'warnings' => $warnings];
    }
}
