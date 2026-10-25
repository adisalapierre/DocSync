<?php
namespace MasCamp\GoogleDocsSync;
if (!defined('ABSPATH')) exit;

/**
 * Plugin & Theme Sync — install and update plugins/themes from a Git branch (GitHub, GitLab, Bitbucket, Gitea).
 *
 * Each connected package has an update mode:
 *   manual — only when someone clicks Deploy;
 *   notify — checked hourly (and on push), shows "Update available", someone clicks Deploy;
 *   auto   — deploys on every push to its branch (webhook), with the hourly check as a fallback.
 *
 * Safety: a deploy is refused when the download isn't a plugin/theme, when the target folder is a local git
 * clone, or when file changes are disabled. Files are swapped in by renaming within the same folder, so a
 * failed deploy never leaves a half-copied plugin behind.
 */
final class Deploy {
    const OPTION     = 'mascamp_docsync_packages';
    const SETTINGS   = 'mascamp_docsync_deploy';
    const CHECK_HOOK = 'mascamp_docsync_deploy_check';
    const RUN_HOOK   = 'mascamp_docsync_deploy_run';
    const REST_NS    = 'mascamp-docsync/v1';
    const MODES      = ['manual' => 'Manual', 'notify' => 'Check & notify', 'auto' => 'Automatic'];

    /** @var Module */
    private $core;

    public function __construct(Module $core) {
        $this->core = $core;
        foreach (['save', 'remove', 'now', 'check', 'check_all', 'update_all', 'settings', 'regen_secret', 'import_dfg'] as $a) {
            add_action('admin_post_mascamp_deploy_' . $a, [$this, 'handle_' . $a]);
        }
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('init', [$this, 'schedule']);
        add_action(self::CHECK_HOOK, [$this, 'check_all']);
        add_action(self::RUN_HOOK, [$this, 'cron_deploy'], 10, 1);
        add_action('admin_notices', [$this, 'notices'], 6);
    }

    /* ---------------------------------------------------------------- Storage */

    public function packages() { $p = get_option(self::OPTION, []); return is_array($p) ? $p : []; }
    private function save_packages(array $p) { update_option(self::OPTION, $p, false); }
    public static function key($type, $slug) { return ($type === 'theme' ? 'theme' : 'plugin') . ':' . $slug; }

    private function package($key) {
        $all = $this->packages();
        if (!isset($all[$key])) return null;
        return wp_parse_args($all[$key], [
            'type' => 'plugin', 'slug' => '', 'provider' => 'github', 'repo_url' => '', 'branch' => 'main', 'subdir' => '',
            'auth' => 'public', 'token' => '', 'username' => '', 'mode' => 'manual', 'keep' => '', 'activate' => '1',
            'deployed' => [], 'latest' => [], 'error' => '', 'checked' => 0,
        ]);
    }

    private function update_package($key, array $changes) {
        $all = $this->packages();
        if (!isset($all[$key])) return;
        $all[$key] = array_merge($all[$key], $changes);
        $this->save_packages($all);
    }

    public function settings() {
        $s = wp_parse_args(get_option(self::SETTINGS, []), ['secret' => '', 'legacy_secret' => '', 'flush_cache' => '0', 'alert' => '0']);
        if ($s['secret'] === '') { $s['secret'] = wp_generate_password(40, false, false); update_option(self::SETTINGS, $s, false); }
        return $s;
    }

    /* ---------------------------------------------------------------- Package helpers */

    public static function target_dir(array $p) {
        return $p['type'] === 'theme' ? trailingslashit(get_theme_root()) . $p['slug'] : trailingslashit(WP_PLUGIN_DIR) . $p['slug'];
    }

    public static function installed(array $p) { return $p['slug'] !== '' && is_dir(self::target_dir($p)); }
    public static function is_git_clone(array $p) { return file_exists(self::target_dir($p) . '/.git'); }

    /** Name from the plugin/theme header, falling back to the folder. */
    public static function display_name(array $p) {
        if (self::installed($p)) {
            if ($p['type'] === 'theme') {
                $t = function_exists('wp_get_theme') ? wp_get_theme($p['slug']) : null;
                if ($t && $t->exists()) return (string) $t->get('Name');
            } else {
                if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
                $pl = get_plugins('/' . $p['slug']);
                if ($pl) { $first = reset($pl); if (!empty($first['Name'])) return (string) $first['Name']; }
            }
        }
        return $p['slug'];
    }

    /** The plugin's main file relative to the plugins folder (e.g. mascamp-docsync/mascamp-docsync.php), or ''. */
    public static function plugin_file(array $p) {
        if ($p['type'] !== 'plugin' || !self::installed($p)) return '';
        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $pl = get_plugins('/' . $p['slug']);
        return $pl ? $p['slug'] . '/' . key($pl) : '';
    }

    /** @return GitClient|\WP_Error */
    private function client(array $p) {
        $token = ''; $user = ''; $provider = $p['provider'];
        if ($p['auth'] === 'git_tab') {
            $c = ($g = $this->core->dest('git')) && method_exists($g, 'credentials') ? $g->credentials() : ['provider' => '', 'token' => '', 'username' => ''];
            if ($c['provider'] !== $provider) return new \WP_Error('deploy_auth', 'The Git tab uses ' . (GitClient::PROVIDERS[$c['provider']] ?? 'another provider') . ', but this repository is on ' . (GitClient::PROVIDERS[$provider] ?? $provider) . '. Enter a token for this package instead.');
            $token = $c['token']; $user = $c['username'];
        } elseif ($p['auth'] === 'token') {
            $token = (string) $p['token']; $user = (string) $p['username'];
        }
        return GitClient::create($provider, $p['repo_url'], $p['branch'], $token, $user, true);
    }

    public function update_available(array $p) {
        return self::installed($p) && !empty($p['latest']['sha']) && !empty($p['deployed']['sha']) && $p['latest']['sha'] !== $p['deployed']['sha'];
    }

    /** All packages with a newer commit than the deployed one. */
    public function updates() {
        $out = [];
        foreach (array_keys($this->packages()) as $k) { $p = $this->package($k); if ($p && $this->update_available($p)) $out[$k] = $p; }
        return $out;
    }

    private static function short($sha) { return substr((string) $sha, 0, 7); }

    /* ---------------------------------------------------------------- Check */

    /** Read the branch's latest commit and remember it. @return array|\WP_Error */
    public function check($key) {
        $p = $this->package($key);
        if (!$p) return new \WP_Error('deploy_missing', 'Unknown package.');
        $c = $this->client($p);
        $commit = is_wp_error($c) ? $c : $c->head_commit();
        if (is_wp_error($commit)) {
            $this->update_package($key, ['error' => $commit->get_error_message(), 'checked' => time()]);
            return $commit;
        }
        $was = $p['latest']['sha'] ?? '';
        $this->update_package($key, ['latest' => $commit, 'error' => '', 'checked' => time()]);
        $p['latest'] = $commit;
        if ($commit['sha'] !== $was && $this->update_available($p)) {
            Log::add('Change detected', sprintf('%s update available from Git: %s “%s”%s', ucfirst($p['type']) . ' “' . self::display_name($p) . '”', self::short($commit['sha']), $commit['message'], $commit['by'] ? ' by ' . $commit['by'] : ''), 0, 'deploy', 'info');
        }
        return $commit;
    }

    /** Hourly: check notify/auto packages; deploy automatic ones that are behind. */
    public function check_all($deploy_auto = true) {
        foreach (array_keys($this->packages()) as $key) {
            $p = $this->package($key);
            if (!$p || $p['mode'] === 'manual') continue;
            $r = $this->check($key);
            if (is_wp_error($r) || !$deploy_auto) continue;
            $p = $this->package($key);
            if ($p['mode'] === 'auto' && $this->update_available($p) && !self::is_git_clone($p)) $this->deploy($key, 'automatic (hourly check)');
        }
    }

    public function schedule() {
        $needs = false;
        foreach ($this->packages() as $p) if (($p['mode'] ?? 'manual') !== 'manual') { $needs = true; break; }
        $next = wp_next_scheduled(self::CHECK_HOOK);
        if ($needs && !$next) wp_schedule_event(time() + 600, 'hourly', self::CHECK_HOOK);
        if (!$needs && $next) wp_clear_scheduled_hook(self::CHECK_HOOK);
    }

    /* ---------------------------------------------------------------- Deploy */

    public function cron_deploy($key) { $this->deploy((string) $key, 'push-to-deploy'); }

    /**
     * Download the branch's latest commit and install it over the plugin/theme folder.
     * @return array{sha:string,message:string,warnings:string[]}|\WP_Error
     */
    public function deploy($key, $via = 'admin') {
        $p = $this->package($key);
        if (!$p) return new \WP_Error('deploy_missing', 'Unknown package.');
        $name = self::display_name($p);
        $what = ucfirst($p['type']) . ' “' . $name . '”';
        Log::add('Deploy', $what . ' deploy from Git started (' . $via . ')', 0, 'deploy');
        $r = $this->deploy_inner($p, $key);
        if (is_wp_error($r)) {
            $this->update_package($key, ['error' => $r->get_error_message()]);
            Log::add('Error', $what . ' deploy from Git failed', 0, 'deploy', 'error', $r->get_error_message());
            return $r;
        }
        Log::add('Deploy', sprintf('%s deployed from Git: %s “%s”', $what, self::short($r['sha']), $r['message']), 0, 'deploy', $r['warnings'] ? 'warning' : 'success',
            implode(' | ', array_merge([$p['repo_url'] . ' @ ' . $p['branch']], $r['warnings'])));
        return $r;
    }

    private function deploy_inner(array $p, $key) {
        if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) return new \WP_Error('deploy_locked', 'File changes are disabled on this site (DISALLOW_FILE_MODS).');
        if ($p['slug'] === '' || preg_match('~[/\\\\]|\.\.~', $p['slug'])) return new \WP_Error('deploy_slug', 'Invalid folder name.');
        $target = self::target_dir($p);
        if (self::is_git_clone($p)) return new \WP_Error('deploy_git_clone', 'This folder is a local git clone (it has a .git folder). Update it with “git pull” instead, so your working copy isn’t overwritten.');
        if (!function_exists('get_filesystem_method')) require_once ABSPATH . 'wp-admin/includes/file.php';
        if (get_filesystem_method([], dirname($target)) !== 'direct') return new \WP_Error('deploy_fs', 'WordPress can’t write to the ' . ($p['type'] === 'theme' ? 'themes' : 'plugins') . ' folder directly on this server, so deploying isn’t possible here.');
        if (function_exists('set_time_limit')) @set_time_limit(600);
        if (function_exists('ignore_user_abort')) ignore_user_abort(true);

        $c = $this->client($p);
        if (is_wp_error($c)) return $c;
        $commit = $c->head_commit();
        if (is_wp_error($commit)) return $commit;

        $work = trailingslashit(get_temp_dir()) . 'mcds-deploy-' . wp_generate_password(10, false, false);
        $zip = $work . '.zip';
        $cleanup = function () use ($work, $zip) { @unlink($zip); self::rrmdir($work); };

        $dl = $c->download_archive($commit['sha'], $zip);
        if (is_wp_error($dl)) { $cleanup(); return $dl; }
        if (!function_exists('WP_Filesystem')) require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        $un = unzip_file($zip, $work);
        if (is_wp_error($un)) { $cleanup(); return new \WP_Error('deploy_unzip', 'Couldn’t unpack the download: ' . $un->get_error_message()); }

        // Archives contain one top folder (owner-repo-sha/). Use it, then the optional subfolder.
        $entries = array_values(array_diff((array) scandir($work), ['.', '..', '__MACOSX']));
        $src = (count($entries) === 1 && is_dir($work . '/' . $entries[0])) ? $work . '/' . $entries[0] : $work;
        $sub = trim(str_replace('\\', '/', (string) $p['subdir']), '/');
        if ($sub !== '') {
            if (strpos($sub, '..') !== false || !is_dir($src . '/' . $sub)) { $cleanup(); return new \WP_Error('deploy_subdir', 'The folder “' . $sub . '” isn’t in the repository.'); }
            $src .= '/' . $sub;
        }

        // Make sure it really is a plugin/theme before touching the site.
        $warnings = [];
        if ($p['type'] === 'theme') {
            $hdr = file_exists($src . '/style.css') ? get_file_data($src . '/style.css', ['Name' => 'Theme Name']) : [];
            if (empty($hdr['Name'])) { $cleanup(); return new \WP_Error('deploy_not_theme', 'The repository (branch ' . $p['branch'] . ($sub ? ', folder ' . $sub : '') . ') has no style.css with a “Theme Name:” header, so it isn’t a theme. Nothing was changed.'); }
        } else {
            $main = '';
            foreach ((array) glob($src . '/*.php') as $f) {
                $hdr = get_file_data($f, ['Name' => 'Plugin Name']);
                if (!empty($hdr['Name'])) { $main = basename($f); break; }
            }
            if ($main === '') { $cleanup(); return new \WP_Error('deploy_not_plugin', 'The repository (branch ' . $p['branch'] . ($sub ? ', folder ' . $sub : '') . ') has no PHP file with a “Plugin Name:” header, so it isn’t a plugin. Nothing was changed.'); }
            $current = self::plugin_file($p);
            if ($current && basename($current) !== $main) $warnings[] = 'The main file changed from ' . basename($current) . ' to ' . $main . '; WordPress will see it as a different plugin, so check it’s still active.';
        }

        // Carry over files the site keeps inside the folder (e.g. config, cache).
        $was_installed = is_dir($target);
        foreach (preg_split('/[\r\n,]+/', (string) $p['keep']) as $rel) {
            $rel = trim(str_replace('\\', '/', $rel), '/ ');
            if ($rel === '' || strpos($rel, '..') !== false) continue;
            if ($was_installed && file_exists($target . '/' . $rel)) {
                if (!self::rcopy($target . '/' . $rel, $src . '/' . $rel)) $warnings[] = 'Couldn’t keep ' . $rel . '.';
            }
        }

        // Stage next to the target (same disk), then swap with renames.
        $parent = dirname($target);
        $rand = wp_generate_password(6, false, false);
        $new = $parent . '/.' . $p['slug'] . '.docsync-new-' . $rand;
        $old = $parent . '/.' . $p['slug'] . '.docsync-old-' . $rand;
        if (!self::rcopy($src, $new)) { self::rrmdir($new); $cleanup(); return new \WP_Error('deploy_copy', 'Couldn’t copy the new files into ' . $parent . '. Check folder permissions.'); }
        $cleanup();
        if ($was_installed && !@rename($target, $old)) { self::rrmdir($new); return new \WP_Error('deploy_swap', 'Couldn’t move the current ' . $p['type'] . ' aside. Check folder permissions.'); }
        if (!@rename($new, $target)) {
            if ($was_installed) @rename($old, $target); // put the old version back
            self::rrmdir($new);
            return new \WP_Error('deploy_swap', 'Couldn’t move the new version into place; the previous version was kept.');
        }
        if ($was_installed) self::rrmdir($old);

        // Make PHP pick up the new code straight away.
        if (function_exists('opcache_invalidate')) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) if (substr($f->getFilename(), -4) === '.php') @opcache_invalidate($f->getPathname(), true);
        }
        if ($p['type'] === 'theme') { if (function_exists('wp_clean_themes_cache')) wp_clean_themes_cache(); }
        else {
            if (function_exists('wp_clean_plugins_cache')) wp_clean_plugins_cache();
            if (!$was_installed && $p['activate'] === '1') {
                if (!function_exists('activate_plugin')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
                $file = self::plugin_file($p);
                $act = $file ? activate_plugin($file) : null;
                if (is_wp_error($act)) $warnings[] = 'Installed, but couldn’t activate: ' . $act->get_error_message();
            }
        }
        if ($this->settings()['flush_cache'] === '1') { $f = self::flush_caches(); if ($f) $warnings[] = 'Cache cleared: ' . implode(', ', $f) . '.'; }

        $user = wp_doing_cron() ? 0 : get_current_user_id();
        $this->update_package($key, ['deployed' => ['sha' => $commit['sha'], 'message' => $commit['message'], 'by' => $commit['by'], 'date' => $commit['date'], 'at' => time(), 'user' => $user], 'latest' => $commit, 'error' => '', 'checked' => time()]);
        return ['sha' => $commit['sha'], 'message' => $commit['message'], 'warnings' => $warnings];
    }

    /** Clear page caches of common caching plugins. @return string[] names cleared */
    public static function flush_caches() {
        $done = [];
        if (function_exists('rocket_clean_domain')) { rocket_clean_domain(); $done[] = 'WP Rocket'; }
        if (function_exists('WP_Optimize') && method_exists('WP_Optimize', 'get_page_cache')) { $pc = WP_Optimize()->get_page_cache(); if ($pc && method_exists($pc, 'purge')) { $pc->purge(); $done[] = 'WP-Optimize'; } }
        if (function_exists('w3tc_flush_all')) { w3tc_flush_all(); $done[] = 'W3 Total Cache'; }
        if (defined('LSCWP_V') || has_action('litespeed_purge_all')) { do_action('litespeed_purge_all'); $done[] = 'LiteSpeed Cache'; }
        if (function_exists('wp_cache_clear_cache')) { wp_cache_clear_cache(); $done[] = 'WP Super Cache'; }
        if (isset($GLOBALS['wp_fastest_cache']) && method_exists($GLOBALS['wp_fastest_cache'], 'deleteCache')) { $GLOBALS['wp_fastest_cache']->deleteCache(true); $done[] = 'WP Fastest Cache'; }
        if (class_exists('\autoptimizeCache') && method_exists('\autoptimizeCache', 'clearall')) { \autoptimizeCache::clearall(); $done[] = 'Autoptimize'; }
        if (function_exists('wp_cache_flush')) wp_cache_flush();
        return $done;
    }

    public static function cache_plugins() {
        return [
            'WP Rocket' => function_exists('rocket_clean_domain'), 'WP-Optimize' => function_exists('WP_Optimize'),
            'W3 Total Cache' => function_exists('w3tc_flush_all'), 'LiteSpeed Cache' => defined('LSCWP_V'),
            'WP Super Cache' => function_exists('wp_cache_clear_cache'), 'WP Fastest Cache' => isset($GLOBALS['wp_fastest_cache']),
            'Autoptimize' => class_exists('\autoptimizeCache'),
        ];
    }

    private static function rcopy($from, $to) {
        if (is_file($from)) { if (!is_dir(dirname($to)) && !@mkdir(dirname($to), 0755, true)) return false; return @copy($from, $to); }
        if (!is_dir($from)) return false;
        if (!is_dir($to) && !@mkdir($to, 0755, true)) return false;
        foreach (array_diff((array) scandir($from), ['.', '..']) as $e) {
            if (!self::rcopy($from . '/' . $e, $to . '/' . $e)) return false;
        }
        return true;
    }

    private static function rrmdir($dir) {
        if (is_link($dir) || is_file($dir)) { @unlink($dir); return; }
        if (!is_dir($dir)) return;
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $e) self::rrmdir($dir . '/' . $e);
        @rmdir($dir);
    }

    /* ---------------------------------------------------------------- Push-to-deploy webhook */

    public function webhook_url(array $p) {
        return add_query_arg(['type' => $p['type'], 'package' => $p['slug'], 'secret' => $this->settings()['secret']], rest_url(self::REST_NS . '/deploy'));
    }

    public function register_routes() {
        register_rest_route(self::REST_NS, '/deploy', ['methods' => ['GET', 'POST'], 'callback' => [$this, 'webhook'], 'permission_callback' => '__return_true']);
        // Keep existing Deployer for Git webhooks working after switching (only while that plugin is inactive).
        if (!defined('DFG_FILE') && $this->settings()['legacy_secret'] !== '') {
            register_rest_route('dfg/v1', '/package_update/', ['methods' => ['GET', 'POST'], 'callback' => [$this, 'webhook'], 'permission_callback' => '__return_true']);
        }
    }

    /** Accepts ?secret=, GitHub/Gitea HMAC signatures, or GitLab's X-Gitlab-Token. */
    private function webhook_authorized(\WP_REST_Request $req) {
        $s = $this->settings();
        $secrets = array_filter([$s['secret'], $s['legacy_secret']]);
        $q = (string) $req->get_param('secret');
        foreach ($secrets as $sec) if ($q !== '' && hash_equals($sec, $q)) return true;
        $body = (string) $req->get_body();
        $gl = (string) $req->get_header('x_gitlab_token');
        $gh = (string) $req->get_header('x_hub_signature_256');
        $gt = (string) $req->get_header('x_gitea_signature');
        foreach ($secrets as $sec) {
            if ($gl !== '' && hash_equals($sec, $gl)) return true;
            if ($gh !== '' && hash_equals('sha256=' . hash_hmac('sha256', $body, $sec), $gh)) return true;
            if ($gt !== '' && hash_equals(hash_hmac('sha256', $body, $sec), $gt)) return true;
        }
        return false;
    }

    /** Branch the push was for, if the payload says (GitHub/GitLab/Gitea "ref", Bitbucket push.changes). */
    private static function pushed_branch(\WP_REST_Request $req) {
        $j = json_decode((string) $req->get_body(), true);
        if (!is_array($j)) { $pl = $req->get_param('payload'); $j = is_string($pl) ? json_decode($pl, true) : null; }
        if (!is_array($j)) return null;
        if (!empty($j['ref']) && strpos($j['ref'], 'refs/heads/') === 0) return substr($j['ref'], 11);
        if (!empty($j['ref']) && strpos($j['ref'], 'refs/tags/') === 0) return '';
        if (!empty($j['push']['changes'][0]['new']['name'])) return (string) $j['push']['changes'][0]['new']['name'];
        return null;
    }

    public function webhook(\WP_REST_Request $req) {
        if (!$this->webhook_authorized($req)) return new \WP_REST_Response(['success' => false, 'message' => 'Invalid secret'], 401);
        $type = $req->get_param('type') === 'theme' ? 'theme' : 'plugin';
        $slug = sanitize_file_name((string) $req->get_param('package'));
        $key = self::key($type, $slug);
        $p = $this->package($key);
        if (!$p) return new \WP_REST_Response(['success' => false, 'message' => 'Unknown package: ' . $key], 404);
        $branch = self::pushed_branch($req);
        if ($branch !== null && $branch !== $p['branch']) {
            return new \WP_REST_Response(['success' => true, 'message' => 'Ignored: push was to “' . ($branch === '' ? 'a tag' : $branch) . '”, this package follows “' . $p['branch'] . '”.'], 200);
        }
        $commit = $this->check($key);
        if (is_wp_error($commit)) return new \WP_REST_Response(['success' => false, 'message' => $commit->get_error_message()], 502);
        if ($p['mode'] !== 'auto') {
            return new \WP_REST_Response(['success' => true, 'message' => 'Update recorded (' . self::MODES[$p['mode']] . ' mode): deploy it from WordPress → Settings → MasCamp DocSync.'], 200);
        }
        if ($req->get_param('sync')) { // CI can ask to wait for the result
            $r = $this->deploy($key, 'push-to-deploy');
            return is_wp_error($r) ? new \WP_REST_Response(['success' => false, 'message' => $r->get_error_message()], 500)
                                   : new \WP_REST_Response(['success' => true, 'message' => 'Deployed ' . self::short($r['sha'])], 200);
        }
        if (!wp_next_scheduled(self::RUN_HOOK, [$key])) wp_schedule_single_event(time(), self::RUN_HOOK, [$key]);
        if (function_exists('spawn_cron')) spawn_cron();
        Log::add('Deploy', ucfirst($p['type']) . ' “' . self::display_name($p) . '” push received: deploy queued (' . self::short($commit['sha']) . ')', 0, 'deploy');
        return new \WP_REST_Response(['success' => true, 'message' => 'Deploy queued for ' . self::short($commit['sha'])], 202);
    }

    /* ---------------------------------------------------------------- Admin handlers */

    private function back($type, $extra = '') {
        wp_safe_redirect($this->core->settings_url($type === 'theme' ? 'themes' : 'plugins') . $extra); exit;
    }

    private function can_manage($type) {
        return current_user_can('manage_options') && current_user_can($type === 'theme' ? 'install_themes' : 'install_plugins');
    }

    public function handle_save() {
        $type = ($_POST['type'] ?? '') === 'theme' ? 'theme' : 'plugin';
        if (!$this->can_manage($type)) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_deploy_save');
        $orig = sanitize_text_field(wp_unslash($_POST['orig_key'] ?? ''));
        $provider = sanitize_key($_POST['provider'] ?? 'github');
        $repo = trim(sanitize_text_field(wp_unslash($_POST['repo_url'] ?? '')));
        $parsed = GitClient::parse_repo_url($provider, $repo);
        if (is_wp_error($parsed)) { $this->core->notice('error', $parsed->get_error_message()); $this->back($type, $orig ? '&edit=' . rawurlencode($orig) : '&new=1'); }
        $slug = sanitize_file_name(wp_unslash($_POST['slug_existing'] ?? ''));
        if ($slug === '' || $slug === '__new') $slug = sanitize_file_name(wp_unslash($_POST['slug_new'] ?? ''));
        if ($slug === '') $slug = sanitize_file_name(basename($parsed['path']));
        $key = self::key($type, $slug);
        $all = $this->packages();
        if ($key !== $orig && isset($all[$key])) { $this->core->notice('error', 'That ' . $type . ' is already connected.'); $this->back($type); }
        $prev = $orig && isset($all[$orig]) ? $all[$orig] : [];
        $auth = in_array($_POST['auth'] ?? '', ['public', 'git_tab', 'token'], true) ? $_POST['auth'] : 'public';
        $token = trim(sanitize_text_field(wp_unslash($_POST['token'] ?? '')));
        $branch = trim(preg_replace('~[^A-Za-z0-9._/-]~', '', (string) wp_unslash($_POST['branch'] ?? '')));
        $mode = sanitize_key($_POST['mode'] ?? 'manual');
        $pkg = array_merge($prev, [
            'type' => $type, 'slug' => $slug, 'provider' => isset(GitClient::PROVIDERS[$provider]) ? $provider : 'github',
            'repo_url' => $repo, 'branch' => $branch !== '' ? $branch : 'main',
            'subdir' => trim(preg_replace('~[^A-Za-z0-9._/-]~', '', (string) wp_unslash($_POST['subdir'] ?? '')), '/'),
            'auth' => $auth, 'token' => $auth === 'token' ? ($token !== '' ? $token : ($prev['token'] ?? '')) : '',
            'username' => $auth === 'token' ? trim(sanitize_text_field(wp_unslash($_POST['username'] ?? ''))) : '',
            'mode' => isset(self::MODES[$mode]) ? $mode : 'manual',
            'keep' => sanitize_textarea_field(wp_unslash($_POST['keep'] ?? '')),
            'activate' => !empty($_POST['activate']) ? '1' : '0',
        ]);
        if ($orig && $orig !== $key) unset($all[$orig]);
        $all[$key] = $pkg;
        $this->save_packages($all);
        $this->schedule();
        Log::add('Deploy', ucfirst($type) . ' “' . $slug . '” ' . ($prev ? 'connection updated' : 'connected to ' . $repo . ' @ ' . $pkg['branch']) . ' (' . self::MODES[$pkg['mode']] . ')', 0, 'deploy');
        $c = $this->check($key);
        $p = $this->package($key);
        if (is_wp_error($c)) { $this->core->notice('error', 'Saved, but the repository check failed: ' . $c->get_error_message()); $this->back($type); }
        if (!empty($_POST['deploy_now'])) {
            $r = $this->deploy($key);
            $this->core->notice(is_wp_error($r) ? 'error' : 'success', is_wp_error($r) ? 'Saved, but the deploy failed: ' . $r->get_error_message() : 'Saved and deployed ' . self::short($r['sha']) . ' “' . $r['message'] . '”.' . ($r['warnings'] ? ' ' . implode(' ', $r['warnings']) : ''));
        } else {
            $this->core->notice('success', 'Saved. Latest commit on ' . $p['branch'] . ': ' . self::short($c['sha']) . ' “' . $c['message'] . '”.' . (self::installed($p) ? '' : ' Click Install to add it to this site.'));
        }
        $this->back($type);
    }

    public function handle_remove() {
        $key = sanitize_text_field(wp_unslash($_GET['key'] ?? ''));
        $p = $this->package($key);
        if (!$p || !$this->can_manage($p['type'])) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_deploy_remove_' . $key);
        $all = $this->packages(); unset($all[$key]); $this->save_packages($all); $this->schedule();
        Log::add('Deploy', ucfirst($p['type']) . ' “' . self::display_name($p) . '” disconnected from Git (files left in place)', 0, 'deploy');
        $this->core->notice('success', '“' . self::display_name($p) . '” is no longer connected to Git. Its files were left as they are.');
        $this->back($p['type']);
    }

    public function handle_now() {
        $key = sanitize_text_field(wp_unslash($_GET['key'] ?? ''));
        $p = $this->package($key);
        if (!$p || !$this->can_manage($p['type'])) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_deploy_now_' . $key);
        $r = $this->deploy($key);
        if (is_wp_error($r)) $this->core->notice('error', 'Deploy failed for “' . self::display_name($p) . '”: ' . $r->get_error_message());
        else $this->core->notice($r['warnings'] ? 'warning' : 'success', '“' . self::display_name($p) . '” is now on ' . self::short($r['sha']) . ' “' . $r['message'] . '”.' . ($r['warnings'] ? ' ' . implode(' ', $r['warnings']) : ''));
        $this->back($p['type']);
    }

    public function handle_check() {
        $key = sanitize_text_field(wp_unslash($_GET['key'] ?? ''));
        $p = $this->package($key);
        if (!$p || !$this->can_manage($p['type'])) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_deploy_check_' . $key);
        $c = $this->check($key);
        $p = $this->package($key);
        if (is_wp_error($c)) $this->core->notice('error', 'Check failed for “' . self::display_name($p) . '”: ' . $c->get_error_message());
        else $this->core->notice('success', $this->update_available($p) ? 'Update available for “' . self::display_name($p) . '”: ' . self::short($c['sha']) . ' “' . $c['message'] . '”.' : '“' . self::display_name($p) . '”: latest on ' . $p['branch'] . ' is ' . self::short($c['sha']) . ' “' . $c['message'] . '”.');
        $this->back($p['type']);
    }

    public function handle_check_all() {
        $type = ($_GET['type'] ?? '') === 'theme' ? 'theme' : 'plugin';
        if (!$this->can_manage($type)) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_deploy_check_all');
        $n = 0; $errors = 0;
        foreach ($this->packages() as $k => $p) {
            if (($p['type'] ?? 'plugin') !== $type) continue;
            $r = $this->check($k); $n++; if (is_wp_error($r)) $errors++;
        }
        $u = count(array_filter($this->updates(), static function ($p) use ($type) { return $p['type'] === $type; }));
        $this->core->notice($errors ? 'warning' : 'success', sprintf('Checked %d %s: %d update(s) available%s.', $n, $type . 's', $u, $errors ? ', ' . $errors . ' couldn’t be checked' : ''));
        $this->back($type);
    }

    /** Deploy every package of this type with an update available, as a background job with progress. */
    public function handle_update_all() {
        $type = ($_GET['type'] ?? '') === 'theme' ? 'theme' : 'plugin';
        if (!$this->can_manage($type)) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_deploy_update_all');
        $steps = [];
        foreach ($this->updates() as $k => $p) if ($p['type'] === $type && !self::is_git_clone($p)) $steps[] = ['kind' => 'deploy', 'dest' => 'deploy', 'id' => 0, 'key' => $k];
        if (!$steps) $this->core->notice('success', 'No updates to deploy.');
        elseif (!$this->core->start_job(sprintf('Deploying %d %s update(s) from Git', count($steps), $type), $steps, 'deploy')) $this->core->notice('error', 'Another DocSync job is running. Try again when it finishes.');
        $this->back($type);
    }

    public function handle_settings() {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_deploy_settings');
        $s = $this->settings();
        $s['flush_cache'] = !empty($_POST['flush_cache']) ? '1' : '0';
        $s['alert'] = !empty($_POST['alert']) ? '1' : '0';
        update_option(self::SETTINGS, $s, false);
        $this->core->notice('success', 'Deploy settings saved.');
        $this->back(($_POST['type'] ?? '') === 'theme' ? 'theme' : 'plugin');
    }

    public function handle_regen_secret() {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_deploy_regen_secret');
        $s = $this->settings();
        $s['secret'] = wp_generate_password(40, false, false);
        update_option(self::SETTINGS, $s, false);
        Log::add('Deploy', 'Push-to-deploy secret regenerated: update the webhook URLs in your repositories', 0, 'deploy', 'warning');
        $this->core->notice('warning', 'New secret created. Every Push-to-Deploy URL has changed: update the webhooks in your repositories.');
        $this->back(($_GET['type'] ?? '') === 'theme' ? 'theme' : 'plugin');
    }

    /** Copy plugin/theme connections from Deployer for Git (its data is left untouched). */
    public function handle_import_dfg() {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('mascamp_deploy_import_dfg');
        $all = $this->packages(); $added = 0; $skipped = 0;
        foreach (['plugin' => 'dfg_plugins_list', 'theme' => 'dfg_themes_list'] as $type => $opt) {
            foreach ((array) get_option($opt, []) as $d) {
                if (!is_array($d) || empty($d['slug']) || empty($d['repo_url'])) continue;
                $key = self::key($type, sanitize_file_name($d['slug']));
                if (isset($all[$key])) { $skipped++; continue; }
                $o = (array) ($d['options'] ?? []);
                $token = (string) ($o['access_token'] ?? '');
                $user = '';
                if ($token === '' && !empty($o['password'])) { $token = (string) $o['password']; $user = (string) ($o['username'] ?? ''); }
                $all[$key] = [
                    'type' => $type, 'slug' => sanitize_file_name($d['slug']), 'provider' => isset(GitClient::PROVIDERS[$d['provider'] ?? '']) ? $d['provider'] : 'github',
                    'repo_url' => (string) $d['repo_url'], 'branch' => (string) ($d['branch'] ?? 'main') ?: 'main', 'subdir' => '',
                    'auth' => (!empty($d['is_private_repository']) && $token !== '') ? 'token' : 'public', 'token' => $token, 'username' => $user,
                    'mode' => 'notify', 'keep' => '', 'activate' => '0', 'deployed' => [], 'latest' => [], 'error' => '', 'checked' => 0,
                ];
                $added++;
            }
        }
        $this->save_packages($all);
        $legacy = (string) get_option('deployer_for_git_api_secret', '');
        if ($legacy !== '') { $s = $this->settings(); $s['legacy_secret'] = $legacy; update_option(self::SETTINGS, $s, false); }
        foreach (array_keys($all) as $k) $this->check($k);
        $this->schedule();
        Log::add('Deploy', sprintf('Imported %d package(s) from Deployer for Git', $added), 0, 'deploy', 'success');
        $this->core->notice('success', sprintf('Imported %d package(s) from Deployer for Git%s. They start in “Check & notify” mode; switch the ones you want to Automatic. Click Deploy once on each to start tracking its version.', $added, $skipped ? ' (' . $skipped . ' already connected)' : '') . ($legacy !== '' ? ' Its existing Push-to-Deploy URLs will keep working here once you deactivate Deployer for Git.' : ''));
        $this->back(($_GET['type'] ?? '') === 'theme' ? 'theme' : 'plugin');
    }

    /* ---------------------------------------------------------------- Notices */

    public function notices() {
        if (!current_user_can('manage_options')) return;
        $s = $this->settings();
        if ($s['alert'] === '1' && $this->packages()) {
            echo '<div class="notice notice-warning" style="border-left-color:#d63638"><p><strong>Code on this site is deployed from Git.</strong> Don’t edit connected plugins or themes here: changes are overwritten on the next deploy. Make changes in the repository instead.</p></div>';
        }
        $page = $_GET['page'] ?? ''; $tab = $_GET['tab'] ?? '';
        if ($page === Module::PAGE && in_array($tab, ['plugins', 'themes'], true)) return;
        $u = $this->updates();
        if (!$u) return;
        $names = array_map(static function ($p) { return self::display_name($p); }, array_slice($u, 0, 4));
        $hasThemes = (bool) array_filter($u, static function ($p) { return $p['type'] === 'theme'; });
        echo '<div class="notice notice-info"><p><strong>' . count($u) . ' plugin/theme ' . _n('update is', 'updates are', count($u)) . ' available from Git:</strong> ' . esc_html(implode(', ', $names)) . (count($u) > 4 ? '…' : '') . ' <a class="button button-small" style="margin-left:6px" href="' . esc_url($this->core->settings_url($hasThemes && count($u) === 1 ? 'themes' : 'plugins')) . '">Review &amp; deploy</a></p></div>';
    }

    /* ---------------------------------------------------------------- Tabs */

    public function tabs() {
        $u = $this->updates();
        $badge = static function ($n) { return $n ? ' <span style="display:inline-block;min-width:18px;padding:0 5px;border-radius:9px;background:#2271b1;color:#fff;font-size:11px;line-height:18px;text-align:center">' . (int) $n . '</span>' : ''; };
        $pn = count(array_filter($u, static function ($p) { return $p['type'] === 'plugin'; }));
        return ['plugins' => 'Plugin Sync' . $badge($pn), 'themes' => 'Theme Sync' . $badge(count($u) - $pn)];
    }

    public function render_tab($tab) {
        $type = $tab === 'themes' ? 'theme' : 'plugin';
        $label = $type === 'theme' ? 'theme' : 'plugin';
        if (!$this->can_manage($type)) { echo '<p>You don’t have permission to install ' . $label . 's.</p>'; return; }
        $pk = array_filter($this->packages(), static function ($p) use ($type) { return ($p['type'] ?? 'plugin') === $type; });
        $edit = sanitize_text_field(wp_unslash($_GET['edit'] ?? ''));
        $showForm = $edit !== '' || !empty($_GET['new']) || !$pk;

        echo '<p>Install and update ' . $label . 's straight from a Git branch (GitHub, GitLab, Bitbucket or Gitea). For each ' . $label . ' you choose how it updates: <strong>Manual</strong> (only when you click Deploy), <strong>Check &amp; notify</strong> (hourly check shows “Update available”), or <strong>Automatic</strong> (deploys on every push to its branch).</p>';
        if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) echo '<div class="notice notice-error inline"><p>File changes are disabled on this site (<code>DISALLOW_FILE_MODS</code>), so deploys can’t run here.</p></div>';

        $updates = array_filter($this->updates(), static function ($p) use ($type) { return $p['type'] === $type; });
        echo '<p style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">';
        echo '<a class="button button-primary" href="' . esc_url($this->core->settings_url($tab) . '&new=1#mcds-deploy-form') . '">Connect a ' . $label . ' from Git</a>';
        if ($pk) echo '<a class="button mcds-run" data-mcds-title="Checking ' . esc_attr($label) . 's for updates…" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=mascamp_deploy_check_all&type=' . $type), 'mascamp_deploy_check_all')) . '">Check all for updates</a>';
        if ($updates && !$this->core->batch_running()) echo '<a class="button mcds-confirm mcds-run" data-mcds-title="Starting deploys…" data-confirm="' . esc_attr('Deploy ' . count($updates) . ' ' . $label . ' update(s) from Git now?') . '" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=mascamp_deploy_update_all&type=' . $type), 'mascamp_deploy_update_all')) . '">Deploy all updates (' . count($updates) . ')</a>';
        echo '</p>';
        if ($this->core->batch_running()) $this->core->render_progress();

        // Cards
        if ($pk) {
            echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;max-width:1300px;margin:12px 0 20px">';
            foreach ($pk as $key => $raw) $this->render_card($key, $this->package($key));
            echo '</div>';
        } else {
            echo '<p><em>No ' . $label . 's connected yet.</em></p>';
        }

        if ($showForm) $this->render_form($type, $edit !== '' ? $this->package($edit) : null, $edit);
        $this->render_settings($type);
    }

    private function render_card($key, array $p) {
        $installed = self::installed($p); $clone = $installed && self::is_git_clone($p);
        $name = self::display_name($p);
        $latest = $p['latest']; $dep = $p['deployed'];
        if ($p['error'] !== '')            { $st = ['Error', '#d63638', $p['error']]; }
        elseif (!$installed)               { $st = ['Not installed', '#8c8f94', 'Install it from ' . $p['branch'] . '.']; }
        elseif ($clone)                    { $st = ['Local git clone', '#8c8f94', 'This folder is a git working copy: update it with git pull. Deploying is disabled here.']; }
        elseif (empty($dep['sha']))        { $st = ['Version unknown', '#dba617', 'Not deployed by DocSync yet. Deploy once to start tracking which commit is installed.']; }
        elseif ($this->update_available($p)) { $st = ['Update available', '#2271b1', '']; }
        else                               { $st = ['Up to date', '#00a32a', '']; }
        $ago = static function ($t) { return $t ? human_time_diff((int) $t, time()) . ' ago' : ''; };

        echo '<div style="background:#fff;border:1px solid #c3c4c7;border-top:4px solid ' . esc_attr($st[1]) . ';padding:12px 14px;display:flex;flex-direction:column;gap:6px">';
        echo '<div style="display:flex;justify-content:space-between;gap:8px"><strong style="font-size:14px">' . esc_html($name) . '</strong><span style="white-space:nowrap;padding:1px 8px;border-radius:10px;font-size:11px;color:#fff;background:' . esc_attr($st[1]) . '">' . esc_html($st[0]) . '</span></div>';
        echo '<div style="color:#50575e;font-size:12px"><code>' . esc_html($p['slug']) . '</code> · ' . esc_html(GitClient::PROVIDERS[$p['provider']] ?? $p['provider']) . ($p['auth'] !== 'public' ? ' 🔒' : '') . ' · branch <code>' . esc_html($p['branch']) . '</code>' . ($p['subdir'] ? ' · folder <code>' . esc_html($p['subdir']) . '</code>' : '') . '</div>';
        echo '<div style="font-size:12px;word-break:break-all"><a href="' . esc_url($p['repo_url']) . '" target="_blank" rel="noopener">' . esc_html($p['repo_url']) . '</a></div>';
        echo '<div style="font-size:12px">Updates: <strong>' . esc_html(self::MODES[$p['mode']] ?? $p['mode']) . '</strong></div>';
        if (!empty($dep['sha'])) echo '<div style="font-size:12px">Installed: <code>' . esc_html(self::short($dep['sha'])) . '</code> “' . esc_html(wp_trim_words($dep['message'], 10)) . '” <span style="color:#646970">· deployed ' . esc_html($ago($dep['at'] ?? 0)) . '</span></div>';
        if (!empty($latest['sha']) && ($latest['sha'] !== ($dep['sha'] ?? '') || empty($dep['sha']))) {
            echo '<div style="font-size:12px">Latest on ' . esc_html($p['branch']) . ': <code>' . esc_html(self::short($latest['sha'])) . '</code> “' . esc_html(wp_trim_words($latest['message'], 10)) . '”' . ($latest['by'] ? ' by <strong>' . esc_html($latest['by']) . '</strong>' : '') . ' <span style="color:#646970">' . esc_html($ago($latest['date'] ?? 0)) . '</span>' . (!empty($latest['url']) ? ' · <a target="_blank" rel="noopener" href="' . esc_url($latest['url']) . '">view ↗</a>' : '') . '</div>';
        }
        if ($st[2] !== '') echo '<div style="font-size:12px;color:' . ($st[0] === 'Error' ? '#b32d2e' : '#50575e') . '">' . esc_html($st[2]) . '</div>';
        if ($p['checked']) echo '<div style="font-size:11px;color:#8c8f94">Checked ' . esc_html($ago($p['checked'])) . '</div>';

        // Push-to-deploy URL
        echo '<details style="font-size:12px"><summary style="cursor:pointer;color:#2271b1">Show Push-to-Deploy URL</summary>';
        echo '<input type="text" readonly onclick="this.select()" class="large-text code" style="font-size:11px;margin:6px 0" value="' . esc_attr($this->webhook_url($p)) . '">';
        echo '<span style="color:#50575e">' . ($p['mode'] === 'auto' ? 'Add this as a webhook for push events in your repository. Each push to <code>' . esc_html($p['branch']) . '</code> deploys it here.' : 'In ' . esc_html(self::MODES[$p['mode']]) . ' mode a push only records the update; you deploy it here. Switch to Automatic to deploy on push.') . ' GitHub: Settings → Webhooks → Add webhook (content type <em>application/json</em>).</span></details>';

        // Actions
        $act = [];
        if (!$clone) {
            $lbl = !$installed ? 'Install' : ($this->update_available($p) ? 'Update to ' . self::short($latest['sha']) : 'Deploy now');
            $conf = !$installed ? 'Install “' . $name . '” from ' . $p['branch'] . '?' : 'Replace the installed “' . $name . '” with the latest ' . $p['branch'] . ' from Git? Any edits made to its files on this site will be lost.';
            $act[] = '<a class="button button-primary mcds-confirm mcds-run" data-mcds-title="' . esc_attr('Deploying “' . $name . '”…') . '" data-mcds-text="Downloading from Git and installing. This usually takes a few seconds. If you leave, it still finishes on the server." data-confirm="' . esc_attr($conf) . '" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=mascamp_deploy_now&key=' . rawurlencode($key)), 'mascamp_deploy_now_' . $key)) . '">' . esc_html($lbl) . '</a>';
        }
        $act[] = '<a class="button mcds-run" data-mcds-title="Checking for updates…" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=mascamp_deploy_check&key=' . rawurlencode($key)), 'mascamp_deploy_check_' . $key)) . '">Check</a>';
        $act[] = '<a class="button" href="' . esc_url($this->core->settings_url($p['type'] === 'theme' ? 'themes' : 'plugins') . '&edit=' . rawurlencode($key) . '#mcds-deploy-form') . '">Edit</a>';
        $act[] = '<a class="button-link mcds-confirm" style="color:#b32d2e;margin-left:auto" data-confirm="' . esc_attr('Disconnect “' . $name . '” from Git? Its files stay installed; it just stops updating from Git.') . '" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=mascamp_deploy_remove&key=' . rawurlencode($key)), 'mascamp_deploy_remove_' . $key)) . '">Disconnect</a>';
        echo '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:4px">' . implode('', $act) . '</div>';
        echo '</div>';
    }

    private function render_form($type, $p, $edit_key) {
        $label = $type === 'theme' ? 'theme' : 'plugin';
        $p = $p ?: ['slug' => '', 'provider' => 'github', 'repo_url' => '', 'branch' => 'main', 'subdir' => '', 'auth' => 'token', 'token' => '', 'username' => '', 'mode' => 'manual', 'keep' => '', 'activate' => '1'];
        $connected = array_map(static function ($x) { return $x['slug']; }, array_filter($this->packages(), static function ($x) use ($type) { return ($x['type'] ?? 'plugin') === $type; }));
        if ($type === 'theme') { $list = function_exists('wp_get_themes') ? array_map(static function ($t) { return $t->get('Name'); }, wp_get_themes()) : []; }
        else {
            if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $list = [];
            foreach (get_plugins() as $file => $d) { $dir = dirname($file); if ($dir !== '.') $list[$dir] = $d['Name']; }
        }
        asort($list);
        $git = ($g = $this->core->dest('git')) && method_exists($g, 'credentials') ? $g->credentials() : ['provider' => '', 'token' => ''];

        echo '<div id="mcds-deploy-form" style="background:#fff;border:1px solid #c3c4c7;padding:4px 18px 14px;max-width:900px;margin:10px 0 24px">';
        echo '<h2>' . ($edit_key ? 'Edit ' . esc_html(self::display_name($p)) : 'Connect a ' . $label . ' from Git') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mascamp_deploy_save"><input type="hidden" name="type" value="' . esc_attr($type) . '"><input type="hidden" name="orig_key" value="' . esc_attr($edit_key) . '">';
        wp_nonce_field('mascamp_deploy_save');
        echo '<table class="form-table">';
        echo '<tr><th>' . ucfirst($label) . '</th><td><select name="slug_existing" id="mcds-slug-existing"><option value="__new">New: install from the repository</option>';
        foreach ($list as $slug => $nm) {
            if (in_array($slug, $connected, true) && $slug !== $p['slug']) continue;
            echo '<option value="' . esc_attr($slug) . '" ' . selected($p['slug'], $slug, false) . '>' . esc_html($nm . ' (' . $slug . ')') . '</option>';
        }
        echo '</select><p class="description">Pick an installed ' . $label . ' to manage from Git, or choose “New” to install one.</p>';
        echo '<p id="mcds-slug-new-row" style="margin-top:8px"><label>Folder name <input type="text" name="slug_new" class="regular-text" value="' . esc_attr(isset($list[$p['slug']]) ? '' : $p['slug']) . '" placeholder="defaults to the repository name"></label></p></td></tr>';
        echo '<tr><th>Provider Type</th><td><select name="provider">';
        foreach (GitClient::PROVIDERS as $k => $l) echo '<option value="' . esc_attr($k) . '" ' . selected($p['provider'], $k, false) . '>' . esc_html($l) . '</option>';
        echo '</select></td></tr>';
        echo '<tr><th>Repository URL</th><td><input type="text" name="repo_url" class="regular-text" required value="' . esc_attr($p['repo_url']) . '" placeholder="https://github.com/owner/' . $label . '-name"></td></tr>';
        echo '<tr><th>Branch</th><td><input type="text" name="branch" value="' . esc_attr($p['branch']) . '" placeholder="main" style="width:200px"><p class="description">e.g. <code>main</code> for clients that get every release, or a release branch for legacy clients.</p></td></tr>';
        echo '<tr><th>Folder in repository</th><td><input type="text" name="subdir" value="' . esc_attr($p['subdir']) . '" placeholder="(repository root)" style="width:260px"><p class="description">Only if the ' . $label . ' lives in a subfolder of the repository.</p></td></tr>';
        echo '<tr><th>Access</th><td><fieldset>';
        echo '<label><input type="radio" name="auth" value="public" ' . checked($p['auth'], 'public', false) . '> Public repository (no token)</label><br>';
        echo '<label><input type="radio" name="auth" value="git_tab" ' . checked($p['auth'], 'git_tab', false) . ' ' . ($git['token'] === '' ? 'disabled' : '') . '> Use the token from the Git tab' . ($git['token'] !== '' ? ' (' . esc_html(GitClient::PROVIDERS[$git['provider']] ?? '') . ')' : ' (not set)') . '</label><br>';
        echo '<label><input type="radio" name="auth" value="token" ' . checked($p['auth'], 'token', false) . '> Private: use this token</label><br>';
        echo '<input type="password" name="token" class="regular-text" autocomplete="new-password" style="margin:6px 0 0 24px" placeholder="' . ($p['token'] !== '' ? 'Saved — leave blank to keep' : 'Access token (read access to contents)') . '"><br>';
        echo '<input type="text" name="username" class="regular-text" style="margin:6px 0 0 24px" value="' . esc_attr($p['username']) . '" placeholder="Bitbucket only: username/email for an app password or API token">';
        echo '<p class="description">GitHub: a fine-grained token with <strong>Contents: Read</strong> on this repository is enough for deploying.</p></fieldset></td></tr>';
        echo '<tr><th>Updates</th><td><fieldset>';
        $desc = ['manual' => 'Only when you click Deploy. Good for legacy clients on older code.', 'notify' => 'Checked hourly (and on push); shows “Update available” and you deploy when ready.', 'auto' => 'Deploys on every push to the branch (add the Push-to-Deploy URL as a webhook), with an hourly check as a fallback.'];
        foreach (self::MODES as $k => $l) echo '<label style="display:block;margin-bottom:4px"><input type="radio" name="mode" value="' . esc_attr($k) . '" ' . checked($p['mode'], $k, false) . '> <strong>' . esc_html($l) . '</strong>: ' . esc_html($desc[$k]) . '</label>';
        echo '</fieldset></td></tr>';
        echo '<tr><th>Keep on deploy</th><td><textarea name="keep" rows="2" class="large-text code" placeholder="config.php&#10;cache/">' . esc_textarea($p['keep']) . '</textarea><p class="description">Files or folders inside the ' . $label . ' that this site creates and that aren’t in Git; they’re copied into each new version. One per line.</p></td></tr>';
        if ($type === 'plugin') echo '<tr><th>Activate</th><td><label><input type="checkbox" name="activate" value="1" ' . checked($p['activate'], '1', false) . '> Activate after the first install</label></td></tr>';
        echo '<tr><th></th><td><label><input type="checkbox" name="deploy_now" value="1"> Deploy now after saving</label></td></tr>';
        echo '</table><p><button type="submit" class="button button-primary">' . ($edit_key ? 'Save changes' : 'Connect ' . $label) . '</button> <a class="button-link" style="margin-left:8px" href="' . esc_url($this->core->settings_url($type === 'theme' ? 'themes' : 'plugins')) . '">Cancel</a></p></form>';
        echo "<script>(function(){var s=document.getElementById('mcds-slug-existing'),r=document.getElementById('mcds-slug-new-row');if(!s||!r)return;function t(){r.style.display=s.value==='__new'?'':'none';}s.addEventListener('change',t);t();})();</script>";
        echo '</div>';
    }

    private function render_settings($type) {
        $s = $this->settings();
        echo '<details style="max-width:900px;margin-top:10px"' . (empty($this->packages()) ? '' : '') . '><summary style="cursor:pointer;font-weight:600;font-size:14px">Deploy settings</summary><div style="background:#fff;border:1px solid #c3c4c7;padding:4px 18px 12px;margin-top:8px">';
        // Secret
        echo '<h3>Push-to-Deploy secret</h3><p>Part of every Push-to-Deploy URL. It can also be used as the webhook “Secret” in GitHub/Gitea (signature) or GitLab (token).</p>';
        echo '<p><input type="text" readonly class="regular-text code" onclick="this.select()" value="' . esc_attr($s['secret']) . '"> <a class="button mcds-confirm" data-confirm="Create a new secret? Every Push-to-Deploy URL changes and the webhooks in your repositories must be updated." href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=mascamp_deploy_regen_secret&type=' . $type), 'mascamp_deploy_regen_secret')) . '">Regenerate secret</a></p>';
        // Flush cache + alert
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mascamp_deploy_settings"><input type="hidden" name="type" value="' . esc_attr($type) . '">';
        wp_nonce_field('mascamp_deploy_settings');
        echo '<h3>Flush cache</h3><p><label><input type="checkbox" name="flush_cache" value="1" ' . checked($s['flush_cache'], '1', false) . '> Clear the page cache after every deploy</label></p><p class="description">';
        $parts = [];
        foreach (self::cache_plugins() as $n => $on) $parts[] = esc_html($n) . ': ' . ($on ? '<strong style="color:#00a32a">detected</strong>' : 'not detected');
        echo implode(' · ', $parts) . '</p>';
        echo '<h3>Alert notification</h3><p><label><input type="checkbox" name="alert" value="1" ' . checked($s['alert'], '1', false) . '> Show a banner in wp-admin reminding developers not to edit connected plugins or themes on this site, and to use Git instead</label></p>';
        submit_button('Save settings', 'secondary', 'submit', false);
        echo '</form>';
        // Import from Deployer for Git
        $dfg = count((array) get_option('dfg_plugins_list', [])) + count((array) get_option('dfg_themes_list', []));
        if ($dfg) {
            echo '<h3>Import from Deployer for Git</h3><p>Deployer for Git manages ' . (int) $dfg . ' package(s) on this site. Import copies their repository, branch and token here; its own data is left as it is. Its existing Push-to-Deploy URLs keep working with DocSync once you deactivate Deployer for Git.</p>';
            echo '<p><a class="button mcds-confirm mcds-run" data-mcds-title="Importing from Deployer for Git…" data-confirm="Copy the plugins and themes managed by Deployer for Git into DocSync?" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=mascamp_deploy_import_dfg&type=' . $type), 'mascamp_deploy_import_dfg')) . '">Import ' . (int) $dfg . ' package(s)</a></p>';
        }
        echo '</div></details>';
    }
}
