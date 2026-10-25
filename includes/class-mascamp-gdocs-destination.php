<?php
namespace MasCamp\GoogleDocsSync;
if (!defined('ABSPATH')) exit;

/** Google Docs destination: one Google Doc per post in a chosen Drive folder. */
final class GDocsDestination extends Destination {
    private $option = 'mascamp_docsync_settings';
    private $token_option = 'mascamp_docsync_tokens';
    private $force = false;
    const META_REV      = '_mascamp_gdocs_rev';           // doc revisionId at the last sync (push or pull)
    const META_WPMOD    = '_mascamp_gdocs_wp_modified';   // post_modified_gmt at the last sync
    const META_FEATURED = '_mascamp_gdocs_pushed_featured';
    const META_IMPORTED = '_mascamp_gdocs_imported';
    const META_DRIVEMOD = '_mascamp_gdocs_drive_modified'; // Drive modifiedTime at the last sync (Sync Monitor baseline)

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

    public function extra_keys() {
        return [self::META_REV, self::META_WPMOD, self::META_FEATURED, self::META_IMPORTED, self::META_DRIVEMOD, '_mascamp_gdocs_source_url'];
    }

    public function hooks() {
        add_action('admin_post_mascamp_gdocs_connect', [$this, 'connect']);
        add_action('admin_post_mascamp_gdocs_callback', [$this, 'oauth_callback']);
        add_action('admin_post_mascamp_gdocs_disconnect', [$this, 'disconnect']);
        add_action('admin_post_mascamp_gdocs_pull', [$this, 'pull_handler']);
        add_action('admin_post_mascamp_gdocs_import', [$this, 'import_handler']);
    }

    public function set_force($force) { $this->force = (bool) $force; }

    public function wpmod_key() { return self::META_WPMOD; }

    private function settings() {
        return wp_parse_args(get_option($this->option, []), [
            'client_id' => '', 'client_secret' => '', 'folder_id' => '', 'auto_sync' => '1', 'images' => 'embed', 'import_folder' => '',
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
                    'import_folder' => self::extract_folder_id($v['import_folder'] ?? ''),
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
        echo '<tr><th>Import folder</th><td><input class="regular-text" name="' . $name . '[import_folder]" value="' . esc_attr($s['import_folder']) . '" placeholder="(optional)"><p class="description">Optional Drive folder (ID or URL) where you write <em>new</em> posts as Google Docs. They appear on the <a href="' . esc_url($this->core->settings_url('import')) . '">Import from Docs</a> tab, ready to import as drafts. Can be the same folder as above.</p></td></tr>';
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
            }
            $rename = $doc_id && ($file['name'] ?? '') !== $title;
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
            delete_post_meta($post_id, self::META_REV);
            $rename = false;
        }

        $doc = $this->api('GET', $docs . rawurlencode($doc_id) . '?fields=revisionId,body.content.endIndex');
        if (is_wp_error($doc)) return $doc;
        // Two-way safety: don't silently overwrite edits made in the doc since the last sync.
        $known = get_post_meta($post_id, self::META_REV, true);
        if ($known && !empty($doc['revisionId']) && $doc['revisionId'] !== $known && !$this->force) {
            return new \WP_Error('doc_edited', '“' . $title . '”: the Google Doc was edited since the last sync. Pull it into WordPress first, or choose to overwrite the doc.', ['status' => 409]);
        }
        $content = $doc['body']['content'] ?? [];
        $last = $content ? end($content) : null;
        $doc_end = (int) ($last['endIndex'] ?? 2);

        $c = Converter::convert($title, Module::content_html($post_id), $source, get_the_post_thumbnail_url($post_id, 'full') ?: '', ['images' => $s['images']]);
        $end = $c['end']; // index just after the inserted text

        // 1) Replace text and formatting in one atomic batch.
        $req = [];
        if ($doc_end - 1 > 1) $req[] = ['deleteContentRange' => ['range' => ['startIndex' => 1, 'endIndex' => $doc_end - 1]]];
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
        if ($rename) $this->api('PATCH', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($doc_id) . '?supportsAllDrives=true', ['name' => $title]);
        update_post_meta($post_id, '_mascamp_gdocs_source_url', esc_url_raw($source));
        update_post_meta($post_id, self::META_FEATURED, get_the_post_thumbnail_url($post_id, 'full') ? '1' : '0');
        $this->remember_sync($post_id, $doc_id);
        return ['status' => $warnings ? 'Synced with warnings' : 'Synced', 'warnings' => $warnings];
    }

    /* ---------------------------------------------------------------- Two-way: change tracking */

    /** Records the doc revision and post modified time after a successful push or pull. */
    private function remember_sync($post_id, $doc_id) {
        $d = $this->api('GET', 'https://docs.googleapis.com/v1/documents/' . rawurlencode($doc_id) . '?fields=revisionId');
        if (!is_wp_error($d) && !empty($d['revisionId'])) update_post_meta($post_id, self::META_REV, $d['revisionId']);
        $this->record_wp_baseline($post_id);
        $this->remember_drive_time($post_id, $doc_id);
        delete_transient('mcds_docrev_' . $post_id);
    }

    /** Drive's modifiedTime right after a sync: the cheap first check the Sync Monitor uses. */
    private function remember_drive_time($post_id, $doc_id) {
        $f = $this->api('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($doc_id) . '?fields=modifiedTime&supportsAllDrives=true');
        if (!is_wp_error($f) && !empty($f['modifiedTime'])) update_post_meta($post_id, self::META_DRIVEMOD, $f['modifiedTime']);
    }

    /** true = doc edited since last sync, false = unchanged, null = unknown. Cached briefly to keep the editor fast. */
    public function doc_changed($post_id) {
        $doc_id = $this->meta($post_id, 'link');
        $known = get_post_meta($post_id, self::META_REV, true);
        if (!$doc_id || !$known || !$this->is_ready()) return null;
        $rev = get_transient('mcds_docrev_' . $post_id);
        if ($rev === false) {
            $d = $this->api('GET', 'https://docs.googleapis.com/v1/documents/' . rawurlencode($doc_id) . '?fields=revisionId');
            if (is_wp_error($d) || empty($d['revisionId'])) return null;
            $rev = $d['revisionId'];
            set_transient('mcds_docrev_' . $post_id, $rev, 2 * MINUTE_IN_SECONDS);
        }
        return $rev !== $known;
    }

    /** WordPress edited since the last sync? */
    private function wp_changed($post_id) {
        $known = get_post_meta($post_id, self::META_WPMOD, true);
        return $known && get_post_field('post_modified_gmt', $post_id) > $known;
    }

    public function pull_url($post_id) {
        return wp_nonce_url(admin_url('admin-post.php?action=mascamp_gdocs_pull&post=' . absint($post_id)), 'mascamp_gdocs_pull_' . absint($post_id));
    }

    private function pull_confirm_text($post_id) {
        $t = 'Replace this post’s title and content with the Google Doc? The current version is saved in WordPress Revisions.';
        if ($this->wp_changed($post_id)) $t .= "\n\nNote: this post was also edited in WordPress since the last sync. Those edits will be replaced (still restorable from Revisions).";
        return $t;
    }

    /* ---------------------------------------------------------------- Two-way: UI */

    public function metabox_extra(\WP_Post $post) {
        if (!$this->meta($post->ID, 'link')) return;
        if (Module::is_elementor($post->ID)) {
            echo '<p style="margin:4px 0"><small>Elementor page: sync is one-way (WordPress → Doc) so the layout is never replaced.</small></p>';
            return;
        }
        $changed = $this->doc_changed($post->ID);
        if ($changed) echo '<p style="margin:4px 0;color:#996800"><strong>The doc was edited in Google Docs</strong> since the last sync.</p>';
        echo '<p style="margin:6px 0"><a class="button' . ($changed ? ' button-primary' : '') . ' mcds-confirm mcds-run" data-mcds-title="Pulling from Google Docs…" data-mcds-text="Bringing in the doc’s title, content and images. Usually a few seconds; longer with new images. If you leave, it still finishes on the server." data-confirm="' . esc_attr($this->pull_confirm_text($post->ID)) . '" href="' . esc_url($this->pull_url($post->ID)) . '">Pull from Google Doc</a></p>';
    }

    public function row_actions_extra(\WP_Post $post) {
        if (!$this->meta($post->ID, 'link') || Module::is_elementor($post->ID)) return [];
        return ['mascamp_gdocs_pull' => '<a class="mcds-confirm mcds-run" data-mcds-title="Pulling from Google Docs…" data-confirm=""' . esc_attr('Replace “' . Module::plain_title($post->ID) . '” with its Google Doc? The current version is saved in Revisions.') . '" href="' . esc_url($this->pull_url($post->ID)) . '">Pull from Google Doc</a>'];
    }

    public function pull_handler() {
        $post_id = absint($_GET['post'] ?? 0);
        if (!$post_id || !current_user_can('edit_post', $post_id)) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_gdocs_pull_' . $post_id);
        if (function_exists('ignore_user_abort')) ignore_user_abort(true);
        $r = $this->pull($post_id);
        $title = esc_html(Module::plain_title($post_id));
        if (is_wp_error($r)) {
            $this->core->notice('error', 'Pull from Google Docs failed for “' . $title . '”: ' . esc_html($r->get_error_message()), true);
        } else {
            $msg = 'Pulled “' . $title . '” from Google Docs. The previous version is saved in <strong>Revisions</strong>.';
            if ($r['warnings']) $msg .= '<br>' . count($r['warnings']) . ' warning(s): ' . esc_html(implode(' | ', array_slice($r['warnings'], 0, 3)));
            $this->core->notice($r['warnings'] ? 'warning' : 'success', $msg, true);
        }
        wp_safe_redirect(wp_get_referer() ?: get_edit_post_link($post_id, 'url')); exit;
    }

    /* ---------------------------------------------------------------- Two-way: pull */

    /**
     * Replaces a post's title and content with its linked Google Doc.
     * @return array{warnings:string[]}|\WP_Error
     */
    public function pull($post_id, $is_import = false) {
        Log::add('Pull', $is_import ? 'Google Doc to new draft import started' : 'Google Doc to Post update started', $post_id, 'gdocs');
        $r = $this->pull_inner($post_id, $is_import);
        if (is_wp_error($r)) Log::add('Error', 'Google Doc to Post update failed', $post_id, 'gdocs', 'error', $r->get_error_message());
        else Log::add($is_import ? 'Import' : 'Update', $is_import ? 'Google Doc imported as a new draft ' . get_post_type($post_id) : 'Google Doc to Post update complete', $post_id, 'gdocs', $r['warnings'] ? 'warning' : 'success', implode(' | ', array_slice($r['warnings'], 0, 3)));
        return $r;
    }

    private function pull_inner($post_id, $is_import = false) {
        $ready = $this->ready();
        if (is_wp_error($ready)) return $ready;
        $post = get_post($post_id);
        if (!$post) return new \WP_Error('invalid_post', 'Post not found.');
        if (Module::is_elementor($post_id)) return new \WP_Error('elementor', 'This is an Elementor page. Pulling would replace its layout, so Elementor pages only sync one way (WordPress → Doc).');
        $doc_id = $this->meta($post_id, 'link');
        if (!$doc_id) return new \WP_Error('no_doc', 'This post isn’t linked to a Google Doc yet. Sync it to Google Docs first.');
        if (function_exists('set_time_limit')) @set_time_limit(300);

        $doc = $this->api('GET', 'https://docs.googleapis.com/v1/documents/' . rawurlencode($doc_id));
        if (is_wp_error($doc)) {
            if (self::http_status($doc) === 404) return new \WP_Error('doc_missing', 'The linked Google Doc no longer exists (or the connected account can’t open it).');
            return $doc;
        }
        $warnings = [];
        $converted = DocsImporter::convert($doc, function ($obj, $oid) use ($post_id, &$warnings) {
            return $this->resolve_image($obj, $post_id, $warnings);
        }, [
            // Docs written by a push start with our header (Title, "Source: …", featured image): leave that out of the post.
            'strip_header' => !$is_import,
            'pushed_featured' => get_post_meta($post_id, self::META_FEATURED, true) === '1',
        ]);
        $title = $converted['title'];
        if ($title === null || $title === '') $title = $is_import ? trim((string) ($doc['title'] ?? '')) : '';
        if (trim(wp_strip_all_tags($converted['content'])) === '' && strpos($converted['content'], 'wp:image') === false) {
            return new \WP_Error('doc_empty', 'The Google Doc has no content to pull, so nothing was changed.');
        }

        // Keep the current version restorable, then update without triggering an automatic push back to the doc.
        if (!$is_import && function_exists('wp_save_post_revision')) wp_save_post_revision($post_id);
        $update = ['ID' => $post_id, 'post_content' => $converted['content']];
        if ($title !== '') $update['post_title'] = $title;
        Module::$suppress['gdocs'] = true;
        $res = wp_update_post(wp_slash($update), true);
        Module::$suppress['gdocs'] = false;
        if (is_wp_error($res)) return $res;

        $this->set_meta($post_id, 'status', $warnings ? 'Synced with warnings' : 'Synced');
        $this->set_meta($post_id, 'synced', current_time('mysql'));
        $this->del_meta($post_id, 'error');
        if ($warnings) $this->set_meta($post_id, 'warn', $warnings); else $this->del_meta($post_id, 'warn');
        if (!empty($doc['revisionId'])) update_post_meta($post_id, self::META_REV, $doc['revisionId']);
        $this->record_wp_baseline($post_id);
        $this->remember_drive_time($post_id, $doc_id);
        delete_transient('mcds_docrev_' . $post_id);
        do_action('mascamp_docsync_after_run', 'gdocs', $post_id, true);
        return ['warnings' => $warnings];
    }

    /**
     * Turns a doc image into a Media Library attachment.
     * Images that came from this site (pushed earlier) are matched to their existing attachment; others are
     * downloaded once and de-duplicated by checksum, so pulling repeatedly doesn't fill the Media Library.
     */
    private function resolve_image(array $obj, $post_id, array &$warnings) {
        $emb = $obj['inlineObjectProperties']['embeddedObject'] ?? [];
        $props = $emb['imageProperties'] ?? [];
        $alt = trim((string) ($emb['description'] ?? ($emb['title'] ?? '')));
        $source = (string) ($props['sourceUri'] ?? '');

        if ($source !== '') {
            $candidates = array_unique([$source, preg_replace('/-\d+x\d+(?=\.\w+$)/', '', $source), preg_replace('/(-\d+x\d+)?(\.\w+)$/', '-scaled$2', $source)]);
            foreach ($candidates as $u) {
                $aid = attachment_url_to_postid($u);
                if ($aid) return ['id' => $aid, 'url' => wp_get_attachment_image_url($aid, 'large') ?: wp_get_attachment_url($aid), 'alt' => $alt ?: (string) get_post_meta($aid, '_wp_attachment_image_alt', true)];
            }
        }
        $uri = (string) ($props['contentUri'] ?? '');
        if ($uri === '') { $warnings[] = 'An image in the doc has no downloadable source and was skipped.'; return null; }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = download_url($uri, 60);
        if (is_wp_error($tmp)) { $warnings[] = 'Image download failed: ' . $tmp->get_error_message(); return null; }

        $md5 = md5_file($tmp);
        $existing = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => '_mascamp_gdocs_md5', 'meta_value' => $md5]);
        if ($existing) {
            @unlink($tmp);
            $aid = (int) $existing[0];
            return ['id' => $aid, 'url' => wp_get_attachment_image_url($aid, 'large') ?: wp_get_attachment_url($aid), 'alt' => $alt];
        }
        $mime = function_exists('wp_get_image_mime') ? wp_get_image_mime($tmp) : '';
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime] ?? '';
        if ($ext === '') { @unlink($tmp); $warnings[] = 'An image in the doc isn’t a supported type (JPG, PNG, GIF, WebP) and was skipped.'; return null; }
        $base = sanitize_title($alt !== '' ? wp_trim_words($alt, 8, '') : get_the_title($post_id) . '-image');
        $aid = media_handle_sideload(['name' => ($base ?: 'google-doc-image') . '.' . $ext, 'tmp_name' => $tmp], $post_id, $alt !== '' ? $alt : null);
        if (is_wp_error($aid)) { @unlink($tmp); $warnings[] = 'Could not add an image to the Media Library: ' . $aid->get_error_message(); return null; }
        update_post_meta($aid, '_mascamp_gdocs_md5', $md5);
        if ($alt !== '') update_post_meta($aid, '_wp_attachment_image_alt', $alt);
        return ['id' => $aid, 'url' => wp_get_attachment_image_url($aid, 'large') ?: wp_get_attachment_url($aid), 'alt' => $alt];
    }

    /* ---------------------------------------------------------------- Sync Monitor: remote scan */

    /**
     * One Drive listing per folder gives every doc's modifiedTime and last editor. Only docs whose
     * modifiedTime moved since the last sync get a second, precise check (Docs revisionId), so a
     * rename or sharing change isn't reported as an edit.
     */
    public function scan_remote(array $post_ids) {
        $s = $this->settings();
        $map = [];
        foreach ($post_ids as $pid) { $doc = $this->meta($pid, 'link'); if ($doc) $map[$doc] = (int) $pid; }
        if (!$map) return ['items' => [], 'checked' => 0, 'note' => ''];

        $files = []; $note = '';
        foreach (array_unique(array_filter([$s['folder_id'], $s['import_folder']])) as $folder) {
            $token = '';
            for ($page = 0; $page < 20; $page++) {
                $q = "'" . str_replace("'", "\\'", $folder) . "' in parents and trashed=false and mimeType='application/vnd.google-apps.document'";
                $r = $this->api('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query(array_filter([
                    'q' => $q, 'pageSize' => 1000, 'pageToken' => $token,
                    'fields' => 'nextPageToken,files(id,name,modifiedTime,lastModifyingUser(displayName,emailAddress))',
                    'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
                ])));
                if (is_wp_error($r)) { $note = 'Couldn’t list a Drive folder: ' . $r->get_error_message(); break; }
                foreach ($r['files'] ?? [] as $f) $files[$f['id']] = $f;
                $token = $r['nextPageToken'] ?? '';
                if ($token === '') break;
            }
        }

        $items = []; $lookups = 0; $revchecks = 0;
        foreach ($map as $doc_id => $pid) {
            $f = $files[$doc_id] ?? null;
            if (!$f) { // not in the folders (moved, trashed or deleted): look it up directly
                if ($lookups++ >= 60) continue;
                $g = $this->api('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($doc_id) . '?fields=id,name,modifiedTime,trashed,lastModifyingUser(displayName,emailAddress)&supportsAllDrives=true');
                if (is_wp_error($g)) {
                    if (self::http_status($g) === 404) $items[$pid] = ['state' => 'missing', 'when' => 0, 'by' => '', 'detail' => 'The Google Doc was deleted, or the connected account can no longer open it.', 'url' => ''];
                    continue;
                }
                if (!empty($g['trashed'])) { $items[$pid] = ['state' => 'missing', 'when' => strtotime($g['modifiedTime'] ?? '') ?: 0, 'by' => $g['lastModifyingUser']['displayName'] ?? '', 'detail' => 'The Google Doc is in the Drive trash.', 'url' => $this->view_url($pid)]; continue; }
                $f = $g;
            }
            $known = get_post_meta($pid, self::META_DRIVEMOD, true);
            if ($known && ($f['modifiedTime'] ?? '') === $known) continue; // untouched since the last sync

            // Something changed (or there's no baseline yet): confirm against the doc's revision.
            if ($revchecks++ >= 150) continue;
            $rev = $this->api('GET', 'https://docs.googleapis.com/v1/documents/' . rawurlencode($doc_id) . '?fields=revisionId');
            if (is_wp_error($rev) || empty($rev['revisionId'])) continue;
            $krev = get_post_meta($pid, self::META_REV, true);
            if (!$krev || $rev['revisionId'] === $krev) { // no content change: refresh the cheap baseline
                if (!empty($f['modifiedTime'])) update_post_meta($pid, self::META_DRIVEMOD, $f['modifiedTime']);
                continue;
            }
            set_transient('mcds_docrev_' . $pid, $rev['revisionId'], 2 * MINUTE_IN_SECONDS);
            $who = $f['lastModifyingUser']['displayName'] ?? ($f['lastModifyingUser']['emailAddress'] ?? '');
            $items[$pid] = ['state' => 'changed', 'when' => strtotime($f['modifiedTime'] ?? '') ?: 0, 'by' => $who, 'detail' => 'Edited in Google Docs', 'url' => $this->view_url($pid)];
        }
        if ($lookups > 60) $note = trim($note . ' Some docs outside the sync folders weren’t checked this time.');
        return ['items' => $items, 'checked' => count($map), 'note' => $note];
    }

    /* ---------------------------------------------------------------- Import new posts from a Drive folder */

    public function tabs() { return ['import' => 'Import from Docs']; }

    private function import_url($doc_id, $type) {
        return wp_nonce_url(admin_url('admin-post.php?action=mascamp_gdocs_import&doc=' . rawurlencode($doc_id) . '&type=' . $type), 'mascamp_gdocs_import_' . $doc_id);
    }

    public function render_tab($tab) {
        $s = $this->settings();
        echo '<p>Write a new post as a Google Doc in your import folder, then import it here as a <strong>draft</strong>. A <em>Title</em>-styled first line becomes the post title (otherwise the doc’s name is used). Images are added to the Media Library. Once imported, the post stays linked to its doc, so you can use <em>Pull from Google Doc</em> later to bring in further edits.</p>';
        if (is_wp_error($r = $this->ready())) { echo '<p>' . esc_html($r->get_error_message()) . '</p>'; return; }
        if (!$s['import_folder']) {
            echo '<div class="notice notice-info inline"><p>Set an <strong>Import folder</strong> on the <a href="' . esc_url($this->core->settings_url('gdocs')) . '">Google Docs</a> tab first.</p></div>';
            return;
        }
        $q = "'" . str_replace("'", "\\'", $s['import_folder']) . "' in parents and mimeType='application/vnd.google-apps.document' and trashed=false";
        $list = $this->api('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
            'q' => $q, 'fields' => 'files(id,name,modifiedTime,webViewLink)', 'orderBy' => 'modifiedTime desc', 'pageSize' => 100,
            'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
        ]));
        if (is_wp_error($list)) {
            echo '<div class="notice notice-error inline"><p>Couldn’t read the import folder: ' . esc_html($list->get_error_message()) . '</p></div>';
            return;
        }
        $files = $list['files'] ?? [];
        if (!$files) { echo '<p><em>No Google Docs in the import folder yet.</em></p>'; return; }

        // Which docs are already linked to a post?
        $linked = [];
        $ids = array_column($files, 'id');
        $posts = get_posts(['post_type' => Module::TYPES, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => [['key' => $this->keys()['link'], 'value' => $ids, 'compare' => 'IN']]]);
        foreach ($posts as $pid) $linked[$this->meta($pid, 'link')] = $pid;

        echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>Google Doc</th><th>Last edited</th><th>WordPress</th><th style="text-align:right">Action</th></tr></thead><tbody>';
        foreach ($files as $f) {
            $pid = $linked[$f['id']] ?? 0;
            echo '<tr><td><a target="_blank" rel="noopener" href="' . esc_url($f['webViewLink'] ?? ('https://docs.google.com/document/d/' . $f['id'] . '/edit')) . '">' . esc_html($f['name']) . ' ↗</a></td>';
            echo '<td>' . esc_html(!empty($f['modifiedTime']) ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($f['modifiedTime'])) : '') . '</td>';
            if ($pid) {
                $st = get_post_status($pid);
                echo '<td><a href="' . esc_url(get_edit_post_link($pid, 'url')) . '">' . esc_html(Module::plain_title($pid)) . '</a> <small>(' . esc_html($st === 'publish' ? 'published' : $st) . ')</small></td>';
                echo '<td style="text-align:right">';
                if (!Module::is_elementor($pid)) echo '<a class="button mcds-confirm mcds-run" data-mcds-title="Pulling from Google Docs…" data-confirm="' . esc_attr($this->pull_confirm_text($pid)) . '" href="' . esc_url($this->pull_url($pid)) . '">Pull latest</a>';
                echo '</td></tr>';
            } else {
                echo '<td><em>Not imported</em></td><td style="text-align:right;white-space:nowrap">';
                echo '<a class="button button-primary mcds-run" data-mcds-title="Importing from Google Docs…" data-mcds-text="Creating a draft and bringing in the doc’s content and images. If you leave, it still finishes on the server." href="' . esc_url($this->import_url($f['id'], 'post')) . '">Import as draft post</a> ';
                echo '<a class="button mcds-run" data-mcds-title="Importing from Google Docs…" href="' . esc_url($this->import_url($f['id'], 'page')) . '">as page</a>';
                echo '</td></tr>';
            }
        }
        echo '</tbody></table>';
    }

    public function import_handler() {
        $doc_id = preg_replace('~[^A-Za-z0-9_-]~', '', (string) ($_GET['doc'] ?? ''));
        $type = ($_GET['type'] ?? 'post') === 'page' ? 'page' : 'post';
        $pto = get_post_type_object($type);
        if (!$doc_id || !$pto || !current_user_can($pto->cap->create_posts)) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_gdocs_import_' . $doc_id);
        if (function_exists('ignore_user_abort')) ignore_user_abort(true);
        $back = $this->core->settings_url('import');

        $exists = get_posts(['post_type' => Module::TYPES, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => $this->keys()['link'], 'meta_value' => $doc_id]);
        if ($exists) { $this->core->notice('error', 'That doc is already imported.'); wp_safe_redirect($back); exit; }

        $file = $this->api('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($doc_id) . '?fields=name&supportsAllDrives=true');
        if (is_wp_error($file)) { $this->core->notice('error', 'Import failed: ' . $file->get_error_message()); wp_safe_redirect($back); exit; }
        Module::$suppress['gdocs'] = true;
        $post_id = wp_insert_post(['post_type' => $type, 'post_status' => 'draft', 'post_title' => sanitize_text_field($file['name'] ?? 'Imported doc'), 'post_author' => get_current_user_id()], true);
        Module::$suppress['gdocs'] = false;
        if (is_wp_error($post_id)) { $this->core->notice('error', 'Import failed: ' . $post_id->get_error_message()); wp_safe_redirect($back); exit; }
        $this->set_meta($post_id, 'link', $doc_id);
        update_post_meta($post_id, self::META_IMPORTED, '1');

        $r = $this->pull($post_id, true);
        if (is_wp_error($r)) {
            $this->core->notice('error', 'Created a draft but couldn’t bring in the doc’s content: ' . $r->get_error_message());
        } else {
            $msg = 'Imported as a draft ' . $type . ': <a href="' . esc_url(get_edit_post_link($post_id, 'url')) . '"><strong>' . esc_html(Module::plain_title($post_id)) . '</strong></a>. Review it, then publish when ready.';
            if ($r['warnings']) $msg .= '<br>' . count($r['warnings']) . ' warning(s): ' . esc_html(implode(' | ', array_slice($r['warnings'], 0, 3)));
            $this->core->notice($r['warnings'] ? 'warning' : 'success', $msg, true);
        }
        wp_safe_redirect($back); exit;
    }
}
