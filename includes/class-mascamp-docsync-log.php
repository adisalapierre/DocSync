<?php
namespace MasCamp\GoogleDocsSync;
if (!defined('ABSPATH')) exit;

/**
 * Activity log: every scan, push, pull, update, import and Sync all, shown on the Changes tab.
 * Stored in its own table so busy syncs (Sync all, Keep synced) never lose entries. Kept for 90 days.
 */
final class Log {
    const TABLE = 'mascamp_docsync_log';
    const DB_VERSION = '1';
    const KEEP_DAYS = 90;
    const TIME_FORMAT = 'F j, Y g:i:s a';

    public static function table() { global $wpdb; return $wpdb->prefix . self::TABLE; }

    public static function install() {
        if (get_option('mascamp_docsync_log_db') === self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta('CREATE TABLE ' . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_gmt datetime NOT NULL,
            title varchar(40) NOT NULL,
            event_type varchar(190) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'info',
            dest varchar(20) NOT NULL DEFAULT '',
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            detail text NULL,
            PRIMARY KEY  (id),
            KEY created_gmt (created_gmt),
            KEY post_id (post_id)
        ) $charset;");
        update_option('mascamp_docsync_log_db', self::DB_VERSION, false);
    }

    /**
     * @param string $title      Short heading: Push, Pull, Update, Sync Update, Import, Sync all, Change detected, Error
     * @param string $event_type e.g. "Post to Git started"
     * @param string $status     info | success | warning | error
     */
    public static function add($title, $event_type, $post_id = 0, $dest = '', $status = 'info', $detail = '') {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'created_gmt' => gmdate('Y-m-d H:i:s'),
            'title' => mb_substr((string) $title, 0, 40),
            'event_type' => mb_substr((string) $event_type, 0, 190),
            'status' => $status,
            'dest' => (string) $dest,
            'post_id' => (int) $post_id,
            'user_id' => (wp_doing_cron() || !function_exists('get_current_user_id')) ? 0 : (int) get_current_user_id(),
            'detail' => (string) $detail,
        ]);
        if (mt_rand(1, 200) === 1) self::prune();
    }

    public static function prune() {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE created_gmt < %s', gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS)));
    }

    /**
     * @param array $f ['type' => title|'error'|'', 'dest' => 'gdocs'|'git'|'', 'post_ids' => int[]|null (null = any), 'page' => int, 'per_page' => int]
     * @return array{rows: object[], total: int}
     */
    public static function query(array $f = []) {
        global $wpdb;
        $f = wp_parse_args($f, ['type' => '', 'dest' => '', 'post_ids' => null, 'page' => 1, 'per_page' => 50]);
        $where = '1=1'; $args = [];
        if ($f['type'] === 'error') { $where .= ' AND status = %s'; $args[] = 'error'; }
        elseif ($f['type'] !== '') { $where .= ' AND title = %s'; $args[] = $f['type']; }
        if (in_array($f['dest'], ['gdocs', 'git', 'deploy'], true)) { $where .= ' AND dest = %s'; $args[] = $f['dest']; }
        if (is_array($f['post_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $f['post_ids'])));
            if (!$ids) return ['rows' => [], 'total' => 0];
            $where .= ' AND post_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')';
            $args = array_merge($args, $ids);
        }
        $t = self::table();
        $count_sql = "SELECT COUNT(*) FROM $t WHERE $where";
        $total = (int) $wpdb->get_var($args ? $wpdb->prepare($count_sql, $args) : $count_sql);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d",
            array_merge($args, [(int) $f['per_page'], max(0, ((int) $f['page'] - 1) * (int) $f['per_page'])])));
        return ['rows' => $rows ?: [], 'total' => $total];
    }

    public static function titles() {
        global $wpdb;
        return $wpdb->get_col('SELECT DISTINCT title FROM ' . self::table() . ' ORDER BY title') ?: [];
    }

    /** "Post to Git" / "Post to Google Doc" wording per destination. */
    public static function target($dest) { return $dest === 'git' ? 'Git' : ($dest === 'deploy' ? 'Plugins & themes' : 'Google Doc'); }
}
