<?php

/**
 * Plugin Name: FolderFolio
 * Plugin URI: https://github.com/moustakalis/folderfolio
 * Description: WordPress Media Library folder organization plugin.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
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

define('FOLDERFOLIO_VERSION', '0.1.0');
define('FOLDERFOLIO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FOLDERFOLIO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FOLDERFOLIO_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Register the minimal PSR-4 autoloader for the FolderFolio\ namespace.
require_once FOLDERFOLIO_PLUGIN_DIR . 'includes/Autoloader.php';
\FolderFolio\Autoloader::register(FOLDERFOLIO_PLUGIN_DIR);

// Bootstrap the plugin.
require_once FOLDERFOLIO_PLUGIN_DIR . 'includes/Plugin.php';
add_action('plugins_loaded', [\FolderFolio\Plugin::class, 'init']);
