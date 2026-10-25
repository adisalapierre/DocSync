<?php
/**
 * Plugin Name: MasCamp DocSync
 * Description: Two-way Google Docs sync, one-way Git sync, and Plugin & Theme Sync (deploy plugins and themes from GitHub, GitLab, Bitbucket or Gitea) for WordPress.
 * Version: 0.5.1
 * Author: MasCamp
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Text Domain: mascamp-docsync
 */
if (!defined('ABSPATH')) exit;

/*
 * Another copy of DocSync is already loaded (for example one installed by Deployer for Git
 * in a folder named after the repository, next to one uploaded as a zip). Loading this copy
 * too would declare the same classes twice, which PHP treats as a fatal error. Stand down
 * and explain instead.
 */
if (defined('MASCAMP_DOCSYNC_VERSION')) {
    $mcds_other = defined('MASCAMP_DOCSYNC_DIR') ? basename(rtrim(MASCAMP_DOCSYNC_DIR, '/\\')) : '';
    $mcds_this  = basename(__DIR__);
    if ($mcds_other !== $mcds_this) {
        $mcds_msg = sprintf(
            'MasCamp DocSync %1$s is already active from the <code>%2$s</code> folder. This copy in <code>%3$s</code> was not loaded. Keep one copy: deactivate and delete the other one from the Plugins page. Your settings are kept either way.',
            esc_html(MASCAMP_DOCSYNC_VERSION), esc_html($mcds_other !== '' ? $mcds_other : 'another'), esc_html($mcds_this)
        );
        register_activation_hook(__FILE__, static function () use ($mcds_msg) {
            wp_die('<p>' . $mcds_msg . '</p>', 'DocSync is already installed', ['back_link' => true]);
        });
        add_action('admin_notices', static function () use ($mcds_msg) {
            if (current_user_can('activate_plugins')) echo '<div class="notice notice-warning"><p>' . $mcds_msg . '</p></div>';
        });
    }
    unset($mcds_other, $mcds_this, $mcds_msg);
    return;
}

define('MASCAMP_DOCSYNC_VERSION', '0.5.1');
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
