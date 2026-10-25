<?php
namespace MasCamp\GoogleDocsSync;
if (!defined('ABSPATH')) exit;

/**
 * A place posts can be synced to (Google Docs, Git).
 * The core Module handles the admin UI, triggers, queueing and Sync all; a destination only
 * knows how to configure itself and push one post.
 */
abstract class Destination {
    /** @var Module */
    protected $core;

    public function __construct(Module $core) { $this->core = $core; }

    /** Short machine id, e.g. "gdocs". */
    abstract public function id();
    /** Human label, e.g. "Google Docs". */
    abstract public function label();
    /** Very short label for the posts-list column, e.g. "Docs". */
    abstract public function short_label();

    /**
     * Post meta keys used by this destination:
     * status, synced, error, warn, auto, link (link = meta that exists once an item has been synced).
     */
    abstract public function keys();

    /** true when configured and connected, otherwise WP_Error explaining what's missing. */
    abstract public function ready();

    /** Whether "Keep synced" updates are switched on for this destination. */
    abstract public function auto_enabled();

    /**
     * Push one published post. Returns ['status' => 'Synced'|'Up to date'|'Synced with warnings', 'warnings' => []]
     * or WP_Error. A WP_Error with ['status' => 429] in its data means "rate limited, retry later".
     */
    abstract public function sync(\WP_Post $post);

    /** URL to view the synced copy, or ''. */
    abstract public function view_url($post_id);

    abstract public function register_settings();
    abstract public function render_settings();

    /** Register extra hooks (OAuth callbacks, test buttons…). */
    public function hooks() {}

    /** Extra controls in the post editor's DocSync box (e.g. Pull). */
    public function metabox_extra(\WP_Post $post) {}
    /** Extra Posts/Pages row links. @return array */
    public function row_actions_extra(\WP_Post $post) { return []; }
    /** Extra settings tabs this destination adds: ['slug' => 'Label']. */
    public function tabs() { return []; }
    public function render_tab($tab) {}
    /** Allow overwriting a destination copy that was edited there since the last sync (manual push only). */
    public function set_force($force) {}

    public function is_ready() { return !is_wp_error($this->ready()); }

    public function meta($post_id, $key) { return get_post_meta($post_id, $this->keys()[$key], true); }
    public function set_meta($post_id, $key, $value) { update_post_meta($post_id, $this->keys()[$key], $value); }
    public function del_meta($post_id, $key) { delete_post_meta($post_id, $this->keys()[$key]); }

    /** Keeps a saved secret when the field is submitted blank. */
    protected static function keep_secret($submitted, $old) {
        $submitted = isset($submitted) ? trim(sanitize_text_field($submitted)) : '';
        return $submitted !== '' ? $submitted : $old;
    }

    public static function http_status($error) {
        $d = is_wp_error($error) ? $error->get_error_data() : null;
        return is_array($d) ? (int) ($d['status'] ?? 0) : 0;
    }
}
