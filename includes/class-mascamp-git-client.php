<?php
namespace MasCamp\GoogleDocsSync;
if (!defined('ABSPATH')) exit;

/**
 * Minimal file-commit client for GitHub, GitLab, Bitbucket Cloud and Gitea REST APIs.
 * Reads one file and creates/updates one file per commit, all over HTTPS. No git binary needed.
 */
final class GitClient {
    const PROVIDERS = ['github' => 'GitHub', 'gitlab' => 'GitLab', 'bitbucket' => 'Bitbucket', 'gitea' => 'Gitea'];
    const DEFAULT_HOSTS = ['github' => 'github.com', 'gitlab' => 'gitlab.com', 'bitbucket' => 'bitbucket.org'];

    private $provider, $scheme, $host, $path, $owner, $repo, $branch, $token, $username, $api;

    /** @return GitClient|\WP_Error */
    public static function create($provider, $repo_url, $branch, $token, $username = '') {
        if (!isset(self::PROVIDERS[$provider])) return new \WP_Error('git_provider', 'Choose a Git provider type.');
        if (trim((string) $token) === '') return new \WP_Error('git_token', 'Add an access token for the repository.');
        $p = self::parse_repo_url($provider, $repo_url);
        if (is_wp_error($p)) return $p;
        $c = new self();
        $c->provider = $provider;
        $c->scheme = $p['scheme']; $c->host = $p['host']; $c->path = $p['path'];
        $parts = explode('/', $p['path']);
        $c->owner = $parts[0]; $c->repo = end($parts);
        $c->branch = trim((string) $branch) !== '' ? trim($branch) : 'main';
        $c->token = trim($token);
        $c->username = trim((string) $username);
        switch ($provider) {
            case 'github': $c->api = $c->host === 'github.com' ? 'https://api.github.com' : $c->scheme . '://' . $c->host . '/api/v3'; break;
            case 'gitlab': $c->api = $c->scheme . '://' . $c->host . '/api/v4'; break;
            case 'bitbucket':
                if ($c->host !== 'bitbucket.org') return new \WP_Error('git_bitbucket_host', 'Only Bitbucket Cloud (bitbucket.org) is supported.');
                $c->api = 'https://api.bitbucket.org/2.0'; break;
            case 'gitea': $c->api = $c->scheme . '://' . $c->host . '/api/v1'; break;
        }
        return $c;
    }

    /**
     * Accepts https://host/owner/repo(.git), git@host:owner/repo.git, browser URLs with /tree/… or /src/…,
     * or plain owner/repo (for GitHub, GitLab.com and Bitbucket).
     */
    public static function parse_repo_url($provider, $url) {
        $url = trim((string) $url);
        if ($url === '') return new \WP_Error('git_url', 'Add the repository URL.');
        $scheme = 'https';
        if (preg_match('~^(?:ssh://)?git@([^:/]+)[:/](.+)$~i', $url, $m)) { $host = $m[1]; $path = $m[2]; }
        elseif (preg_match('~^(https?)://([^/]+)/(.+)$~i', $url, $m)) { $scheme = strtolower($m[1]); $host = $m[2]; $path = $m[3]; }
        elseif (preg_match('~^[\w.-]+(/[\w.-]+)+/?$~', $url)) {
            if (!isset(self::DEFAULT_HOSTS[$provider])) return new \WP_Error('git_url', 'Enter the full repository URL, e.g. https://gitea.example.com/owner/repo');
            $host = self::DEFAULT_HOSTS[$provider]; $path = $url;
        } else {
            return new \WP_Error('git_url', 'That doesn’t look like a repository URL. Example: https://github.com/owner/repo');
        }
        $host = strtolower(preg_replace('~^[^@]+@~', '', $host));       // https://user@bitbucket.org/…
        $path = preg_replace('~[?#].*$~', '', $path);
        $path = preg_replace('~/(-/)?(tree|blob|src|browse|commits?|pulls?|issues|wiki)(/.*)?$~', '', $path);
        $path = trim(preg_replace('~\.git$~', '', trim($path, '/')), '/');
        $parts = array_values(array_filter(explode('/', $path), 'strlen'));
        if ($provider === 'gitlab' ? count($parts) < 2 : count($parts) !== 2) {
            return new \WP_Error('git_url', 'The repository URL should look like https://' . (self::DEFAULT_HOSTS[$provider] ?? 'git.example.com') . '/owner/repo');
        }
        return ['scheme' => $scheme, 'host' => $host, 'path' => implode('/', $parts)];
    }

    public function name() { return $this->path; }
    public function branch() { return $this->branch; }

    /* ---------------------------------------------------------------- HTTP */

    private function headers() {
        switch ($this->provider) {
            case 'github': return ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'];
            case 'gitlab': return ['PRIVATE-TOKEN' => $this->token];
            case 'gitea': return ['Authorization' => 'token ' . $this->token, 'Accept' => 'application/json'];
            case 'bitbucket':
                // Atlassian API token + email → Basic; repository/workspace access token → Bearer.
                return ['Authorization' => $this->username !== '' ? 'Basic ' . base64_encode($this->username . ':' . $this->token) : 'Bearer ' . $this->token];
        }
        return [];
    }

    /** @return array{code:int,body:string,json:mixed}|\WP_Error */
    private function request($method, $url, $body = null, $form = false) {
        $headers = $this->headers();
        $args = ['method' => $method, 'timeout' => 45, 'headers' => $headers, 'user-agent' => 'MasCamp-DocSync/' . (defined('MASCAMP_DOCSYNC_VERSION') ? MASCAMP_DOCSYNC_VERSION : '1')];
        if ($body !== null) {
            if ($form) { $args['body'] = $body; }
            else { $args['body'] = wp_json_encode($body); $args['headers']['Content-Type'] = 'application/json'; }
        }
        $r = wp_remote_request($url, $args);
        if (is_wp_error($r)) return new \WP_Error('git_http', 'Could not reach ' . $this->host . ': ' . $r->get_error_message());
        $raw = (string) wp_remote_retrieve_body($r);
        return ['code' => (int) wp_remote_retrieve_response_code($r), 'body' => $raw, 'json' => json_decode($raw, true)];
    }

    private static function enc_path($path) { return implode('/', array_map('rawurlencode', explode('/', $path))); }

    private function repo_base() {
        switch ($this->provider) {
            case 'gitlab': return $this->api . '/projects/' . rawurlencode($this->path);
            case 'bitbucket': return $this->api . '/repositories/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo);
            default: return $this->api . '/repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo);
        }
    }

    /** Converts a non-2xx response into a friendly WP_Error. Rate limits get status 429. */
    private function error($res, $what) {
        $code = $res['code'];
        $j = $res['json'];
        $detail = '';
        if (is_array($j)) $detail = $j['message'] ?? ($j['error']['message'] ?? (is_string($j['error'] ?? null) ? $j['error'] : ''));
        if (is_array($detail)) $detail = wp_json_encode($detail);
        $detail = trim(wp_strip_all_tags((string) $detail));
        $rate = $code === 429 || ($code === 403 && stripos($res['body'], 'rate limit') !== false);
        if ($rate) return new \WP_Error('git_rate', self::PROVIDERS[$this->provider] . ' rate limit reached. Wait a minute and sync again.', ['status' => 429]);
        $msg = [
            401 => 'The access token was rejected (401). Check it was copied correctly and hasn’t expired.',
            403 => 'Access denied (403). The token needs write access to this repository’s contents.',
            404 => 'Not found (404). Check the repository URL and branch, and that the token can access this repository.',
        ][$code] ?? (self::PROVIDERS[$this->provider] . ' returned HTTP ' . $code . ' while ' . $what . '.');
        if ($detail !== '' && !in_array($code, [401], true)) $msg .= ' ' . self::PROVIDERS[$this->provider] . ' says: ' . wp_trim_words($detail, 30);
        return new \WP_Error('git_api', $msg, ['status' => $code]);
    }

    /* ---------------------------------------------------------------- Operations */

    /** Checks the repository and branch are reachable. Returns a success message or WP_Error. */
    public function test() {
        $repo = $this->request('GET', $this->repo_base());
        if (is_wp_error($repo)) return $repo;
        if ($repo['code'] !== 200) return $this->error($repo, 'opening the repository');
        $j = (array) $repo['json'];
        $default = ''; $can_push = null; $empty = false;
        switch ($this->provider) {
            case 'github': $default = $j['default_branch'] ?? ''; $can_push = $j['permissions']['push'] ?? null; $empty = isset($j['size']) && (int) $j['size'] === 0; break;
            case 'gitea': $default = $j['default_branch'] ?? ''; $can_push = $j['permissions']['push'] ?? null; $empty = !empty($j['empty']); break;
            case 'gitlab': $default = $j['default_branch'] ?? ''; $empty = !empty($j['empty_repo']); break;
            case 'bitbucket': $default = $j['mainbranch']['name'] ?? ''; break;
        }
        $burl = [
            'github' => $this->repo_base() . '/branches/' . rawurlencode($this->branch),
            'gitea' => $this->repo_base() . '/branches/' . rawurlencode($this->branch),
            'gitlab' => $this->repo_base() . '/repository/branches/' . rawurlencode($this->branch),
            'bitbucket' => $this->repo_base() . '/refs/branches/' . rawurlencode($this->branch),
        ][$this->provider];
        $br = $this->request('GET', $burl);
        if (is_wp_error($br)) return $br;
        if ($br['code'] === 404) {
            if ($empty) return new \WP_Error('git_empty', 'Connected, but the repository is empty. Add a README (or any first commit) on GitHub/GitLab first, then test again.');
            return new \WP_Error('git_branch', 'Connected to ' . $this->path . ', but branch “' . $this->branch . '” was not found.' . ($default ? ' The default branch is “' . $default . '”.' : ''));
        }
        if ($br['code'] !== 200) return $this->error($br, 'checking the branch');
        if ($can_push === false) return new \WP_Error('git_readonly', 'Connected to ' . $this->path . ', but this account can’t push to it. Give the token write access to repository contents.');
        return 'Connected to ' . $this->path . ' on branch “' . $this->branch . '”.';
    }

    /**
     * Reads a file on the branch. Returns null if it doesn't exist, ['sha' => ?string, 'content' => ?string]
     * (content null when the provider omits it, e.g. very large files), or WP_Error.
     */
    public function get_file($path) {
        switch ($this->provider) {
            case 'github':
            case 'gitea':
                $r = $this->request('GET', $this->repo_base() . '/contents/' . self::enc_path($path) . '?ref=' . rawurlencode($this->branch));
                break;
            case 'gitlab':
                $r = $this->request('GET', $this->repo_base() . '/repository/files/' . rawurlencode($path) . '?ref=' . rawurlencode($this->branch));
                break;
            case 'bitbucket':
                $r = $this->request('GET', $this->repo_base() . '/src/' . rawurlencode($this->branch) . '/' . self::enc_path($path));
                if (is_wp_error($r)) return $r;
                if ($r['code'] === 404) return null;
                if ($r['code'] !== 200) return $this->error($r, 'reading ' . $path);
                return ['sha' => null, 'content' => $r['body']];
        }
        if (is_wp_error($r)) return $r;
        if ($r['code'] === 404) return null;
        if ($r['code'] !== 200) return $this->error($r, 'reading ' . $path);
        $j = $r['json'];
        if (!is_array($j) || isset($j[0])) return new \WP_Error('git_not_file', $path . ' exists in the repository but is a folder, not a file.');
        $sha = $this->provider === 'gitlab' ? ($j['blob_id'] ?? null) : ($j['sha'] ?? null);
        $content = isset($j['content']) && $j['content'] !== '' ? base64_decode(str_replace(["\n", "\r"], '', $j['content']), true) : null;
        return ['sha' => $sha, 'content' => $content === false ? null : $content];
    }

    /** Creates or updates one file in a single commit. Returns ['url' => web URL] or WP_Error. */
    public function put_file($path, $content, $message, $existing) {
        $b64 = base64_encode($content);
        switch ($this->provider) {
            case 'github':
                $body = ['message' => $message, 'content' => $b64, 'branch' => $this->branch];
                if (!empty($existing['sha'])) $body['sha'] = $existing['sha'];
                $r = $this->request('PUT', $this->repo_base() . '/contents/' . self::enc_path($path), $body);
                break;
            case 'gitea':
                $body = ['message' => $message, 'content' => $b64, 'branch' => $this->branch];
                if ($existing) $body['sha'] = $existing['sha'];
                $r = $this->request($existing ? 'PUT' : 'POST', $this->repo_base() . '/contents/' . self::enc_path($path), $body);
                break;
            case 'gitlab':
                $body = ['branch' => $this->branch, 'content' => $b64, 'encoding' => 'base64', 'commit_message' => $message];
                $r = $this->request($existing ? 'PUT' : 'POST', $this->repo_base() . '/repository/files/' . rawurlencode($path), $body);
                break;
            case 'bitbucket':
                $r = $this->request('POST', $this->repo_base() . '/src', [$path => $content, 'message' => $message, 'branch' => $this->branch], true);
                break;
            default:
                return new \WP_Error('git_provider', 'Unsupported provider.');
        }
        if (is_wp_error($r)) return $r;
        if ($r['code'] < 200 || $r['code'] >= 300) {
            if ($r['code'] === 409 || $r['code'] === 422) {
                // File changed on the server between read and write (or sha mismatch): caller can retry.
                return new \WP_Error('git_conflict', 'The file changed in the repository while syncing. Sync again.', ['status' => $r['code']]);
            }
            return $this->error($r, 'committing ' . $path);
        }
        $url = is_array($r['json']) ? ($r['json']['content']['html_url'] ?? '') : '';
        return ['url' => $url ?: $this->web_url($path)];
    }

    public function web_url($path) {
        $b = rawurlencode($this->branch); $p = self::enc_path($path);
        switch ($this->provider) {
            case 'github': return 'https://' . $this->host . '/' . $this->path . '/blob/' . $b . '/' . $p;
            case 'gitlab': return $this->scheme . '://' . $this->host . '/' . $this->path . '/-/blob/' . $b . '/' . $p;
            case 'bitbucket': return 'https://bitbucket.org/' . $this->path . '/src/' . $b . '/' . $p;
            case 'gitea': return $this->scheme . '://' . $this->host . '/' . $this->path . '/src/branch/' . $b . '/' . $p;
        }
        return '';
    }

    /* ---------------------------------------------------------------- Sync Monitor support */

    /** Git's blob id for some content (what tree listings report per file). */
    public static function blob_sha($content) { return sha1('blob ' . strlen($content) . "\0" . $content); }

    /**
     * Every file under $folder on the branch: [path => blob sha]. One or a few API calls.
     * Returns null when the provider can't list blob ids cheaply (Bitbucket), or WP_Error.
     */
    public function list_tree($folder = '') {
        $prefix = $folder !== '' ? rtrim($folder, '/') . '/' : '';
        $out = [];
        switch ($this->provider) {
            case 'github':
                $r = $this->request('GET', $this->repo_base() . '/git/trees/' . rawurlencode($this->branch) . '?recursive=1');
                if (is_wp_error($r)) return $r;
                if ($r['code'] !== 200) return $this->error($r, 'listing repository files');
                foreach ((array) ($r['json']['tree'] ?? []) as $e) {
                    if (($e['type'] ?? '') === 'blob' && ($prefix === '' || strpos($e['path'], $prefix) === 0)) $out[$e['path']] = $e['sha'];
                }
                return $out;
            case 'gitea':
                for ($page = 1; $page <= 30; $page++) {
                    $r = $this->request('GET', $this->repo_base() . '/git/trees/' . rawurlencode($this->branch) . '?recursive=true&per_page=1000&page=' . $page);
                    if (is_wp_error($r)) return $r;
                    if ($r['code'] !== 200) return $this->error($r, 'listing repository files');
                    $tree = (array) ($r['json']['tree'] ?? []);
                    foreach ($tree as $e) {
                        if (($e['type'] ?? '') === 'blob' && ($prefix === '' || strpos($e['path'], $prefix) === 0)) $out[$e['path']] = $e['sha'];
                    }
                    if (empty($r['json']['truncated']) || !$tree) break;
                }
                return $out;
            case 'gitlab':
                for ($page = 1; $page <= 100; $page++) {
                    $q = ['ref' => $this->branch, 'recursive' => 'true', 'per_page' => 100, 'page' => $page];
                    if ($prefix !== '') $q['path'] = rtrim($prefix, '/');
                    $r = $this->request('GET', $this->repo_base() . '/repository/tree?' . http_build_query($q));
                    if (is_wp_error($r)) return $r;
                    if ($r['code'] === 404 && $page === 1 && $prefix !== '') return []; // folder doesn't exist yet
                    if ($r['code'] !== 200) return $this->error($r, 'listing repository files');
                    $rows = (array) $r['json'];
                    foreach ($rows as $e) if (($e['type'] ?? '') === 'blob') $out[$e['path']] = $e['id'];
                    if (count($rows) < 100) break;
                }
                return $out;
        }
        return null; // bitbucket: no cheap blob listing
    }

    /** Latest commit touching $path: ['by', 'when' (unix), 'message', 'url'] or null. */
    public function last_commit($path) {
        switch ($this->provider) {
            case 'github':
                $r = $this->request('GET', $this->repo_base() . '/commits?' . http_build_query(['path' => $path, 'sha' => $this->branch, 'per_page' => 1]));
                $c = (!is_wp_error($r) && $r['code'] === 200) ? ($r['json'][0] ?? null) : null;
                return $c ? ['by' => $c['commit']['author']['name'] ?? '', 'when' => strtotime($c['commit']['author']['date'] ?? '') ?: 0, 'message' => strtok((string) ($c['commit']['message'] ?? ''), "\n"), 'url' => $c['html_url'] ?? ''] : null;
            case 'gitea':
                $r = $this->request('GET', $this->repo_base() . '/commits?' . http_build_query(['path' => $path, 'sha' => $this->branch, 'limit' => 1]));
                $c = (!is_wp_error($r) && $r['code'] === 200) ? ($r['json'][0] ?? null) : null;
                return $c ? ['by' => $c['commit']['author']['name'] ?? '', 'when' => strtotime($c['commit']['author']['date'] ?? '') ?: 0, 'message' => strtok((string) ($c['commit']['message'] ?? ''), "\n"), 'url' => $c['html_url'] ?? ''] : null;
            case 'gitlab':
                $r = $this->request('GET', $this->repo_base() . '/repository/commits?' . http_build_query(['path' => $path, 'ref_name' => $this->branch, 'per_page' => 1]));
                $c = (!is_wp_error($r) && $r['code'] === 200) ? ($r['json'][0] ?? null) : null;
                return $c ? ['by' => $c['author_name'] ?? '', 'when' => strtotime($c['committed_date'] ?? '') ?: 0, 'message' => (string) ($c['title'] ?? ''), 'url' => $c['web_url'] ?? ''] : null;
        }
        return null;
    }

    public function history_url($path) {
        $b = rawurlencode($this->branch); $p = self::enc_path($path);
        switch ($this->provider) {
            case 'github': return 'https://' . $this->host . '/' . $this->path . '/commits/' . $b . '/' . $p;
            case 'gitlab': return $this->scheme . '://' . $this->host . '/' . $this->path . '/-/commits/' . $b . '/' . $p;
            case 'gitea': return $this->scheme . '://' . $this->host . '/' . $this->path . '/commits/branch/' . $b . '/' . $p;
        }
        return $this->web_url($path);
    }

    public function provider() { return $this->provider; }
}
