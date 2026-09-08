<?php
/**
 * Plugin Name: FolderFolio
 * Plugin URI: https://github.com/moustakalis/folderfolio
 * Description: Organize your WordPress Media Library with unlimited virtual folders. No tiers. No upsells.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL-2.0-or-later
 * Requires at least: 6.4
 * Requires PHP: 8.0
 */

declare(strict_types=1);

namespace FolderFolio;

if (!defined('ABSPATH')) {
    exit;
}

define('FOLDERFOLIO_VERSION', '1.0.0');
define('FOLDERFOLIO_PLUGIN_FILE', __FILE__);
define('FOLDERFOLIO_PLUGIN_DIR', dirname(__DIR__));
define('FOLDERFOLIO_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load Composer autoloader (bundled, no runtime Composer).
if (file_exists(FOLDERFOLIO_PLUGIN_DIR . '/vendor/autoload.php')) {
    require_once FOLDERFOLIO_PLUGIN_DIR . '/vendor/autoload.php';
}

// Initialize plugin.
add_action('plugins_loaded', function () {
    $plugin = new Plugin();
    $plugin->boot();
});

// Activation hook.
register_activation_hook(__FILE__, function () {
    $plugin = new Plugin();
    $plugin->activate();
});

// Deactivation hook.
register_deactivation_hook(__FILE__, function () {
    $plugin = new Plugin();
    $plugin->deactivate();
});
