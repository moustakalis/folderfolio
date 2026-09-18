<?php

/**
 * Plugin Name: FolderFolio
 * Plugin URI: https://github.com/moustakalis/folderfolio
 * Description: Organize the WordPress Media Library with unlimited virtual folders.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Nickos Moustakas
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: folderfolio
 */

declare(strict_types=1);

namespace FolderFolio;

if (!defined('ABSPATH')) {
    exit;
}

define('FOLDERFOLIO_VERSION', '1.0.0');
define('FOLDERFOLIO_PLUGIN_FILE', __FILE__);
define('FOLDERFOLIO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FOLDERFOLIO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FOLDERFOLIO_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Register the minimal PSR-4 autoloader for the FolderFolio\ namespace.
require_once FOLDERFOLIO_PLUGIN_DIR . 'includes/Autoloader.php';
Autoloader::register(FOLDERFOLIO_PLUGIN_DIR);

// Bootstrap the plugin.
// The public PHP API. Global namespace, so it is required rather than
// autoloaded, and loaded early so integrators can hook plugins_loaded.
require_once FOLDERFOLIO_PLUGIN_DIR . 'includes/api.php';

require_once FOLDERFOLIO_PLUGIN_DIR . 'includes/Plugin.php';

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);

add_action('plugins_loaded', [Plugin::class, 'init']);
