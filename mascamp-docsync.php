<?php
/**
 * Plugin Name: MasCamp DocSync
 * Description: Two-way Google Docs sync, one-way Git sync, and Plugin & Theme Sync (deploy plugins and themes from GitHub, GitLab, Bitbucket or Gitea) for WordPress.
 * Version: 0.5.0
 * Author: MasCamp
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

define('MASCAMP_DOCSYNC_VERSION', '0.5.0');
define('MASCAMP_DOCSYNC_DIR', plugin_dir_path(__FILE__));

require_once MASCAMP_DOCSYNC_DIR . 'includes/class-mascamp-gdocs-converter.php';
require_once MASCAMP_DOCSYNC_DIR . 'includes/class-mascamp-markdown-converter.php';
require_once MASCAMP_DOCSYNC_DIR . 'includes/class-mascamp-docs-importer.php';
require_once MASCAMP_DOCSYNC_DIR . 'includes/class-mascamp-docsync-destination.php';
require_once MASCAMP_DOCSYNC_DIR . 'includes/class-mascamp-gdocs-destination.php';
require_once MASCAMP_DOCSYNC_DIR . 'includes/class-mascamp-git-client.php';
require_once MASCAMP_DOCSYNC_DIR . 'includes/class-mascamp-git-destination.php';
require_once MASCAMP_DOCSYNC_DIR . 'includes/class-mascamp-docsync-log.php';
require_once MASCAMP_DOCSYNC_DIR . 'includes/class-mascamp-docsync-monitor.php';
require_once MASCAMP_DOCSYNC_DIR . 'includes/class-mascamp-deploy.php';
require_once MASCAMP_DOCSYNC_DIR . 'includes/class-mascamp-docsync.php';

add_action('plugins_loaded', static function () {
    \MasCamp\GoogleDocsSync\Module::instance();
});

/** Quick link from the Plugins screen. */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), static function ($links) {
    array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=mascamp-docsync')) . '">Settings</a>');
    return $links;
});

/** Stop the hourly Sync Monitor check when the plugin is deactivated. */
register_deactivation_hook(__FILE__, static function () {
    wp_clear_scheduled_hook('mascamp_docsync_monitor_scan');
    wp_clear_scheduled_hook('mascamp_docsync_deploy_check');
});
