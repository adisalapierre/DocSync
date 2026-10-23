<?php
/**
 * Plugin Name: MasCamp DocSync
 * Description: Sync chosen WordPress posts and pages to Google Docs and/or a Git repository (GitHub, GitLab, Bitbucket, Gitea), with per-post Keep synced, bulk actions, Sync all and sync status.
 * Version: 0.2.1
 * Author: MasCamp
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

define('MASCAMP_DOCSYNC_VERSION', '0.2.1');
define('MASCAMP_DOCSYNC_DIR', plugin_dir_path(__FILE__));

require_once MASCAMP_DOCSYNC_DIR . 'includes/class-mascamp-gdocs-converter.php';
require_once MASCAMP_DOCSYNC_DIR . 'includes/class-mascamp-markdown-converter.php';
require_once MASCAMP_DOCSYNC_DIR . 'includes/class-mascamp-docsync-destination.php';
require_once MASCAMP_DOCSYNC_DIR . 'includes/class-mascamp-gdocs-destination.php';
require_once MASCAMP_DOCSYNC_DIR . 'includes/class-mascamp-git-client.php';
require_once MASCAMP_DOCSYNC_DIR . 'includes/class-mascamp-git-destination.php';
require_once MASCAMP_DOCSYNC_DIR . 'includes/class-mascamp-docsync.php';

add_action('plugins_loaded', static function () {
    \MasCamp\GoogleDocsSync\Module::instance();
});

/** Quick link from the Plugins screen. */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), static function ($links) {
    array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=mascamp-docsync')) . '">Settings</a>');
    return $links;
});
