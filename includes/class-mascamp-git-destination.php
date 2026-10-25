<?php
namespace MasCamp\GoogleDocsSync;
if (!defined('ABSPATH')) exit;

/**
 * Git destination: each post becomes a Markdown file (front matter + body) committed to a repository.
 * Images are referenced by their website URL only and never copied into the repository.
 */
final class GitDestination extends Destination {
    private $option = 'mascamp_docsync_git';

    public function id() { return 'git'; }
    public function label() { return 'Git'; }
    public function short_label() { return 'Git'; }

    public function keys() {
        return [
            'status' => '_mascamp_git_status',
            'synced' => '_mascamp_git_synced',
            'error'  => '_mascamp_git_error',
            'warn'   => '_mascamp_git_warnings',
            'auto'   => '_mascamp_git_auto',
            'link'   => '_mascamp_git_path',
        ];
    }

    public function extra_keys() { return ['_mascamp_git_url', '_mascamp_git_blob', '_mascamp_git_wp_modified']; }

    public function hooks() {
        add_action('admin_post_mascamp_docsync_git_test', [$this, 'test_connection']);
    }

    private function settings() {
        return wp_parse_args(get_option($this->option, []), [
            'provider' => 'github', 'repo_url' => '', 'branch' => 'main', 'username' => '', 'token' => '',
            'folder' => 'wordpress-content', 'auto_sync' => '1', 'protected' => '0',
        ]);
    }

    private function token() {
        if (defined('MASCAMP_DOCSYNC_GIT_TOKEN') && MASCAMP_DOCSYNC_GIT_TOKEN) return MASCAMP_DOCSYNC_GIT_TOKEN;
        return $this->settings()['token'];
    }

    /** @return GitClient|\WP_Error */
    private function client() {
        $s = $this->settings();
        return GitClient::create($s['provider'], $s['repo_url'], $s['branch'], $this->token(), $s['provider'] === 'bitbucket' ? $s['username'] : '');
    }

    public function ready() {
        $s = $this->settings();
        if (!$s['repo_url'] || !$this->token()) return new \WP_Error('git_setup', 'Git is not set up: add the repository URL and access token in Settings → MasCamp DocSync → Git.');
        $c = $this->client();
        return is_wp_error($c) ? $c : true;
    }

    public function auto_enabled() { return $this->settings()['auto_sync'] === '1'; }

    public function view_url($post_id) { return (string) get_post_meta($post_id, '_mascamp_git_url', true); }

    private static function clean_folder($folder) {
        $parts = array_filter(explode('/', str_replace('\\', '/', (string) $folder)), static function ($p) { return $p !== '' && $p !== '.' && $p !== '..'; });
        $parts = array_map(static function ($p) { return preg_replace('~[^A-Za-z0-9._-]~', '-', $p); }, $parts);
        return implode('/', $parts);
    }

    /* ---------------------------------------------------------------- Settings */

    public function register_settings() {
        register_setting('mascamp_docsync_git', $this->option, [
            'type' => 'array',
            'sanitize_callback' => function ($v) {
                $old = $this->settings();
                $v = is_array($v) ? $v : [];
                $provider = sanitize_key($v['provider'] ?? 'github');
                $branch = trim(sanitize_text_field($v['branch'] ?? ''));
                return [
                    'provider'  => isset(GitClient::PROVIDERS[$provider]) ? $provider : 'github',
                    'repo_url'  => trim(sanitize_text_field($v['repo_url'] ?? '')),
                    'branch'    => $branch !== '' ? preg_replace('~[^A-Za-z0-9._/-]~', '', $branch) : 'main',
                    'username'  => trim(sanitize_text_field($v['username'] ?? '')),
                    'token'     => !empty($v['clear_token']) ? '' : self::keep_secret($v['token'] ?? null, $old['token']),
                    'folder'    => self::clean_folder($v['folder'] ?? ''),
                    'auto_sync' => !empty($v['auto_sync']) ? '1' : '0',
                    'protected' => !empty($v['protected']) ? '1' : '0',
                ];
            },
        ]);
    }

    public function render_settings() {
        $s = $this->settings();
        $n = esc_attr($this->option);
        $has_token = (bool) $this->token();
        $from_const = defined('MASCAMP_DOCSYNC_GIT_TOKEN') && MASCAMP_DOCSYNC_GIT_TOKEN;
        $help = [
            'github' => ['https://github.com/owner/repo', 'https://github.com/settings/personal-access-tokens/new', 'Create a <strong>fine-grained token</strong>: Repository access → <em>Only select repositories</em> → this repo; Permissions → <strong>Contents: Read and write</strong>.'],
            'gitlab' => ['https://gitlab.com/group/project', 'https://gitlab.com/-/user_settings/personal_access_tokens', 'Create a project access token (Project → Settings → Access tokens, role Developer or higher) or a personal token, with the <strong>api</strong> scope.'],
            'bitbucket' => ['https://bitbucket.org/workspace/repo', 'https://support.atlassian.com/bitbucket-cloud/docs/create-a-repository-access-token/', 'Easiest: a <strong>repository access token</strong> (Repository settings → Access tokens) with <strong>Repositories: Write</strong>. Leave Username blank. Or use an Atlassian API token with repository write scope plus your Atlassian account email as Username.'],
            'gitea' => ['https://git.example.com/owner/repo', '', 'In Gitea: Settings → Applications → Generate new token, with <strong>repository: Read and Write</strong>. Use the full URL of your Gitea server.'],
        ];

        echo '<form method="post" action="options.php" id="mcds-git-form">';
        settings_fields('mascamp_docsync_git');
        echo '<table class="form-table">';

        echo '<tr><th><label for="mcds-provider">Provider Type</label></th><td><select id="mcds-provider" name="' . $n . '[provider]">';
        foreach (GitClient::PROVIDERS as $k => $label) echo '<option value="' . esc_attr($k) . '" ' . selected($s['provider'], $k, false) . '>' . esc_html($label) . '</option>';
        echo '</select></td></tr>';

        echo '<tr><th><label for="mcds-repo">Repository URL</label></th><td><input id="mcds-repo" class="regular-text" name="' . $n . '[repo_url]" value="' . esc_attr($s['repo_url']) . '" placeholder="' . esc_attr($help[$s['provider']][0]) . '">';
        echo '<p class="description">Example: <code id="mcds-repo-example">' . esc_html($help[$s['provider']][0]) . '</code>. The browser address of the repo, or its clone URL.</p></td></tr>';

        echo '<tr><th><label for="mcds-branch">Branch</label></th><td><input id="mcds-branch" class="regular-text" style="max-width:200px" name="' . $n . '[branch]" value="' . esc_attr($s['branch']) . '" placeholder="main"><p class="description">Usually <code>main</code> (older repositories often use <code>master</code>).</p></td></tr>';

        echo '<tr><th><label for="mcds-folder">Folder in repository</label></th><td><input id="mcds-folder" class="regular-text" style="max-width:260px" name="' . $n . '[folder]" value="' . esc_attr($s['folder']) . '" placeholder="(repository root)">';
        echo '<p class="description">Files are saved as <code>' . esc_html(($s['folder'] ? $s['folder'] . '/' : '') . 'posts/your-post-slug.md') . '</code> and <code>' . esc_html(($s['folder'] ? $s['folder'] . '/' : '') . 'pages/your-page-slug.md') . '</code>.</p></td></tr>';

        echo '<tr id="mcds-user-row"' . ($s['provider'] === 'bitbucket' ? '' : ' style="display:none"') . '><th><label for="mcds-user">Username / email</label></th><td><input id="mcds-user" class="regular-text" name="' . $n . '[username]" value="' . esc_attr($s['username']) . '" autocomplete="off"><p class="description">Bitbucket only, and only for Atlassian API tokens. Leave blank for a repository access token.</p></td></tr>';

        echo '<tr><th><label for="mcds-token">Access Token</label></th><td>';
        if ($from_const) {
            echo '<p><strong>Set in wp-config.php</strong> (MASCAMP_DOCSYNC_GIT_TOKEN).</p>';
        } else {
            echo '<input id="mcds-token" type="password" autocomplete="new-password" class="regular-text" name="' . $n . '[token]" value="" placeholder="' . ($has_token ? 'Saved — leave blank to keep' : 'Paste access token') . '">';
            if ($has_token) echo ' <label style="margin-left:8px"><input type="checkbox" name="' . $n . '[clear_token]" value="1"> Remove saved token</label>';
        }
        echo '<p class="description" id="mcds-token-help">' . wp_kses_post($help[$s['provider']][2]) . '</p>';
        echo '<p class="description" id="mcds-token-link"' . ($help[$s['provider']][1] ? '' : ' style="display:none"') . '><a target="_blank" rel="noopener" href="' . esc_url($help[$s['provider']][1]) . '">Create a token ↗</a></p>';
        echo '<p class="description">A token is always needed to push, for public and private repositories alike. It is stored in the WordPress options table; for hardened deployments define <code>MASCAMP_DOCSYNC_GIT_TOKEN</code> in wp-config.php instead.</p></td></tr>';

        echo '<tr><th>Automatic sync</th><td><label><input type="checkbox" name="' . $n . '[auto_sync]" value="1" ' . checked($s['auto_sync'], '1', false) . '> Commit automatically when a post marked <em>Keep synced</em> is updated</label><p class="description">Untick to pause all automatic Git syncing.</p></td></tr>';
        echo '<tr><th>Password-protected posts</th><td><label><input type="checkbox" name="' . $n . '[protected]" value="1" ' . checked($s['protected'], '1', false) . '> Include their content in the repository</label><p class="description">Off by default, because repositories can be public: protected posts are committed with their details only, plus a note that the content is protected. Google Docs always gets the full content (your Drive is private).</p></td></tr>';
        echo '<tr><th>Images</th><td><p>Referenced by their website URL. Image files are never copied into the repository.</p></td></tr>';
        echo '</table>';
        submit_button('Save Git settings');
        echo '</form>';

        echo '<hr><h2>Test connection</h2><p>Checks the repository and branch can be reached with the saved settings. Save first if you changed anything.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mascamp_docsync_git_test">';
        wp_nonce_field('mascamp_docsync_git_test');
        submit_button('Test connection', 'secondary', 'submit', false, $this->is_ready() ? [] : ['disabled' => 'disabled']);
        echo '</form>';

        echo '<script>(function(){var h=' . wp_json_encode(array_map(static function ($x) { return ['example' => $x[0], 'link' => $x[1], 'help' => wp_kses_post($x[2])]; }, $help)) . ';var sel=document.getElementById("mcds-provider");if(!sel)return;sel.addEventListener("change",function(){var x=h[sel.value];if(!x)return;document.getElementById("mcds-repo").placeholder=x.example;document.getElementById("mcds-repo-example").textContent=x.example;document.getElementById("mcds-token-help").innerHTML=x.help;var l=document.getElementById("mcds-token-link");l.style.display=x.link?"":"none";if(x.link)l.querySelector("a").href=x.link;document.getElementById("mcds-user-row").style.display=sel.value==="bitbucket"?"":"none";});})();</script>';
    }

    public function test_connection() {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_docsync_git_test');
        $c = $this->client();
        $r = is_wp_error($c) ? $c : $c->test();
        if (is_wp_error($r)) $this->core->notice('error', 'Git connection failed: ' . $r->get_error_message());
        else $this->core->notice('success', $r . ' Ready to sync.');
        wp_safe_redirect($this->core->settings_url('git')); exit;
    }

    /* ---------------------------------------------------------------- Sync */

    /** First sync picks the path from the slug; later syncs keep that path so the file's history stays in one place. */
    private function path_for(\WP_Post $post) {
        $saved = $this->meta($post->ID, 'link');
        if ($saved) return $saved;
        $folder = $this->settings()['folder'];
        if ($post->post_type === 'page') {
            $slug = get_page_uri($post);
            $slug = implode('/', array_map('sanitize_title', array_filter(explode('/', (string) $slug))));
        } else {
            $slug = sanitize_title($post->post_name);
        }
        if ($slug === '') $slug = $post->post_type . '-' . $post->ID;
        $dir = $post->post_type === 'page' ? 'pages' : 'posts';
        return ltrim(($folder ? $folder . '/' : '') . $dir . '/' . $slug . '.md', '/');
    }

    public function sync(\WP_Post $post) {
        $c = $this->client();
        if (is_wp_error($c)) return $c;
        $path = $this->path_for($post);
        $markdown = $this->build_markdown($post);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $existing = $c->get_file($path);
            if (is_wp_error($existing)) return $existing;
            if ($existing && $existing['content'] !== null && $existing['content'] === $markdown) {
                $this->set_meta($post->ID, 'link', $path);
                update_post_meta($post->ID, '_mascamp_git_url', esc_url_raw($c->web_url($path)));
                update_post_meta($post->ID, '_mascamp_git_blob', GitClient::blob_sha($markdown));
                return ['status' => 'Up to date'];
            }
            $title = Module::plain_title($post->ID);
            $type = $post->post_type === 'page' ? 'page' : 'post';
            $message = sprintf('%s %s: %s', $existing ? 'Update' : 'Add', $type, $title) . "\n\nSynced from WordPress " . $type . ' #' . $post->ID . ' by MasCamp DocSync.' . "\n" . get_permalink($post->ID);
            $put = $c->put_file($path, $markdown, $message, $existing);
            if (is_wp_error($put) && $put->get_error_code() === 'git_conflict' && $attempt === 1) continue; // re-read and retry once
            if (is_wp_error($put)) return $put;
            $this->set_meta($post->ID, 'link', $path);
            update_post_meta($post->ID, '_mascamp_git_url', esc_url_raw($put['url']));
            update_post_meta($post->ID, '_mascamp_git_blob', GitClient::blob_sha($markdown)); // what we committed: the Sync Monitor baseline
            return ['status' => 'Synced'];
        }
        return new \WP_Error('git_conflict', 'The file kept changing in the repository. Sync again.');
    }

    /** Front matter (YAML) + Markdown body. Deterministic, so unchanged posts produce identical files (no empty commits). */
    private function build_markdown(\WP_Post $post) {
        $id = $post->ID;
        $q = static function ($v) { return wp_json_encode((string) $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); };
        $fm = [];
        $fm[] = 'title: ' . $q(Module::plain_title($id));
        $fm[] = 'id: ' . (int) $id;
        $fm[] = 'type: ' . $post->post_type;
        $fm[] = 'slug: ' . $q($post->post_name);
        $fm[] = 'date: ' . $q(get_post_time('c', false, $post));
        $fm[] = 'modified: ' . $q(get_post_modified_time('c', false, $post));
        $author = get_the_author_meta('display_name', $post->post_author);
        if ($author) $fm[] = 'author: ' . $q($author);
        $fm[] = 'permalink: ' . $q(get_permalink($id));
        if ($post->post_type === 'page' && $post->post_parent) $fm[] = 'parent: ' . $q(get_post_field('post_name', $post->post_parent));
        if (has_excerpt($id)) $fm[] = 'excerpt: ' . $q(html_entity_decode(wp_strip_all_tags($post->post_excerpt), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        foreach (['categories' => 'category', 'tags' => 'post_tag'] as $key => $tax) {
            if (!is_object_in_taxonomy($post->post_type, $tax)) continue;
            $terms = get_the_terms($id, $tax);
            $names = (is_array($terms) ? array_map(static function ($t) { return html_entity_decode($t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }, $terms) : []);
            sort($names);
            if (!$names) { $fm[] = $key . ': []'; continue; }
            $fm[] = $key . ':';
            foreach ($names as $name) $fm[] = '  - ' . $q($name);
        }
        $thumb = get_the_post_thumbnail_url($id, 'full');
        if ($thumb) $fm[] = 'featured_image: ' . $q($thumb);

        if (Module::is_protected($id)) $fm[] = 'password_protected: true';
        $body = (Module::is_protected($id) && $this->settings()['protected'] !== '1')
            ? '_This ' . $post->post_type . ' is password-protected; its content isn’t published to the repository._'
            : MarkdownConverter::convert(Module::content_html($id));
        return "---\n" . implode("\n", $fm) . "\n---\n\n" . ($body !== '' ? $body . "\n" : '');
    }

    /* ---------------------------------------------------------------- Sync Monitor: remote scan */

    public function history_url($post_id) {
        $path = $this->meta($post_id, 'link');
        $c = $this->client();
        return ($path && !is_wp_error($c)) ? $c->history_url($path) : $this->view_url($post_id);
    }

    /**
     * One tree listing compares each synced file's current blob id with the one DocSync committed.
     * Different = edited in the repository; absent = deleted/moved. Bitbucket can't list blob ids,
     * so only WordPress-side changes are reported there.
     */
    public function scan_remote(array $post_ids) {
        $c = $this->client();
        if (is_wp_error($c)) return ['items' => [], 'checked' => 0, 'note' => $c->get_error_message()];
        $paths = [];
        foreach ($post_ids as $pid) { $p = $this->meta($pid, 'link'); if ($p) $paths[(int) $pid] = $p; }
        if (!$paths) return ['items' => [], 'checked' => 0, 'note' => ''];

        $tree = $c->list_tree($this->settings()['folder']);
        if ($tree === null) return ['items' => [], 'checked' => 0, 'note' => 'Bitbucket can’t report edits made directly in the repository, so only WordPress-side Git changes are shown.'];
        if (is_wp_error($tree)) return ['items' => [], 'checked' => 0, 'note' => 'Couldn’t read the repository: ' . $tree->get_error_message()];

        $items = []; $lookups = 0; $verifies = 0;
        foreach ($paths as $pid => $path) {
            $remote = $tree[$path] ?? null;
            if ($remote === null) {
                $items[$pid] = ['state' => 'missing', 'when' => 0, 'by' => '', 'detail' => 'The file is missing from the repository (deleted, moved, or the branch changed).', 'url' => $c->history_url($path)];
                continue;
            }
            // One-time check that the file really holds this post (a duplicated post may have written into it).
            if (get_post_meta($pid, $this->verified_key(), true) !== '1' && $verifies++ < 150) {
                $file = $c->get_file($path);
                if (!is_wp_error($file) && $file && $file['content'] !== null) {
                    $other = preg_match('/^id:\s*(\d+)\s*$/m', substr($file['content'], 0, 2000), $mm) ? (int) $mm[1] : 0;
                    if ($other && $other !== $pid) {
                        $items[$pid] = ['state' => 'wrong', 'when' => 0, 'by' => '', 'other' => $other,
                            'detail' => 'This file holds “' . Module::plain_title($other) . '” (#' . $other . ') instead of this ' . get_post_type($pid) . ', written there by a duplicated post. Restore it from WordPress.',
                            'url' => $c->web_url($path)];
                        continue;
                    }
                    update_post_meta($pid, $this->verified_key(), '1');
                    if ((string) get_post_meta($pid, '_mascamp_git_blob', true) === '') update_post_meta($pid, '_mascamp_git_blob', $remote);
                }
            }
            $known = (string) get_post_meta($pid, '_mascamp_git_blob', true);
            if ($known === '') { update_post_meta($pid, '_mascamp_git_blob', $remote); continue; } // synced before baselines existed
            if ($remote === $known) continue;
            $lc = $lookups++ < 25 ? $c->last_commit($path) : null;
            $items[$pid] = [
                'state' => 'changed', 'when' => $lc['when'] ?? 0, 'by' => $lc['by'] ?? '',
                'detail' => 'Changed in the repository' . (!empty($lc['message']) ? ': “' . $lc['message'] . '”' : ''),
                'url' => $lc['url'] ?? $c->history_url($path),
            ];
        }
        return ['items' => $items, 'checked' => count($paths), 'note' => ''];
    }
}
