<?php
/**
 * Plugin Name: MasCamp DocSync
 * Description: Sync chosen WordPress posts and pages to Google Docs in one Drive folder, with images, formatting, per-post Keep synced, bulk actions and sync status.
 * Version: 0.1.1
 * Author: MasCamp
 */
if (!defined('ABSPATH')) exit;

define('MASCAMP_DOCSYNC_VERSION', '0.1.1');
define('MASCAMP_DOCSYNC_DIR', plugin_dir_path(__FILE__));
require_once MASCAMP_DOCSYNC_DIR . 'includes/class-mascamp-gdocs-converter.php';
require_once MASCAMP_DOCSYNC_DIR . 'includes/class-mascamp-gdocs-sync.php';
add_action('plugins_loaded', static function () {
    \MasCamp\GoogleDocsSync\Module::instance();
});
