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

    /** Other post meta this destination writes that belongs to the linked copy (cleared with the link). */
    public function extra_keys() { return []; }

    public function meta($post_id, $key) {
        $value = get_post_meta($post_id, $this->keys()[$key], true);
        if ($key === 'link' && $value !== '' && $this->link_is_copied((int) $post_id, (string) $value)) {
            $this->forget_link((int) $post_id);
            return '';
        }
        return $value;
    }

    public function set_meta($post_id, $key, $value) {
        update_post_meta($post_id, $this->keys()[$key], $value);
        if ($key === 'link') update_post_meta($post_id, $this->owner_key(), (int) $post_id);
    }

    public function del_meta($post_id, $key) {
        delete_post_meta($post_id, $this->keys()[$key]);
        if ($key === 'link') delete_post_meta($post_id, $this->owner_key());
    }

    /* ------------------------------------------------ Duplicated posts
     * Duplicator plugins copy every custom field, including the link to the synced
     * copy (Git file path, Google Doc ID). Without this check a duplicate would
     * overwrite the original's file or doc. Each link is stamped with the ID of the
     * post that owns it; a post whose stamp names another post is a copy, and its
     * copied sync data is cleared so it gets its own file/doc on the next sync.
     */

    private function owner_key() { return '_mascamp_' . $this->id() . '_owner'; }

    /** true when this post's link was copied from another post. */
    private function link_is_copied($post_id, $value) {
        static $checked = [];
        $ck = $this->id() . ':' . $post_id . ':' . md5($value);
        if (isset($checked[$ck])) return $checked[$ck];

        $owner = get_post_meta($post_id, $this->owner_key(), true);
        if ($owner !== '') return $checked[$ck] = ((int) $owner !== $post_id);

        // Linked before ownership stamps existed: the lowest-ID live post with this
        // link is the original (a duplicate always gets a higher ID).
        global $wpdb;
        $first = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(pm.post_id) FROM {$wpdb->postmeta} pm
               JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key = %s AND pm.meta_value = %s
                AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit')",
            $this->keys()['link'], $value
        ));
        if ($first && $first !== $post_id) return $checked[$ck] = true;

        update_post_meta($post_id, $this->owner_key(), $post_id); // stamp the original
        return $checked[$ck] = false;
    }

    /** Clear a copied link and everything tied to it. "Keep synced" stays as chosen. */
    private function forget_link($post_id) {
        foreach ($this->keys() as $key => $meta_key) {
            if ($key !== 'auto') delete_post_meta($post_id, $meta_key);
        }
        foreach ($this->extra_keys() as $meta_key) delete_post_meta($post_id, $meta_key);
        delete_post_meta($post_id, $this->owner_key());
    }

    /** Every meta key this destination writes, for duplicators' "do not copy" lists. */
    public function all_meta_keys() {
        return array_values(array_merge(array_values($this->keys()), $this->extra_keys(), [$this->owner_key()]));
    }

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
