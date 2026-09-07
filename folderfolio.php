<?php
/**
 * Plugin Name: FolderFolio Lite
 * Plugin URI: https://beezna.gr/folderfolio/
 * Description: Organize thousands of WordPress media files into folders/ categories at ease.
 * Version: 0.0.1
 * Author: Beezna
 * Author URI: https://beezna.gr/
 * Text Domain: folderfolio
 * Domain Path: /i18n/languages/
 *
 * @package FolderFolio
 */
namespace FolderFolio;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'BZFF_VERSION' ) ) {
	define( 'BZFF_VERSION', '0.0.1' );
}

if ( ! defined( 'NJFB_PREFIX' ) ) {
	define( 'BZFF_PREFIX', 'filebird' );
}

if ( ! defined( 'BZFF_PLUGIN_FILE' ) ) {
	define( 'BZFF_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'BZFF_PLUGIN_URL' ) ) {
	define( 'BZFF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'BZFF_PLUGIN_PATH' ) ) {
	define( 'BZFF_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'BZFF_PLUGIN_BASE_NAME' ) ) {
	define( 'BZFF_PLUGIN_BASE_NAME', plugin_basename( __FILE__ ) );
}

// Register classes autoloader
spl_autoload_register(
	function ( $class_name ) {
		$prefix   = __NAMESPACE__; // project-specific namespace prefix
		$base_dir = __DIR__ . '/includes'; // base directory for the namespace prefix

		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class_name, $len ) !== 0 ) { // does the class use the namespace prefix?
			return; // no, move to the next registered autoloader
		}

		$relative_class_name = substr( strtolower($class_name), $len );

		// replace the namespace prefix with the base directory, replace namespace
		// separators with directory separators in the relative class name, append
		// with .php
		$fileName = str_replace( '\\', '',  $relative_class_name );

		$file = $base_dir . '/class-folderfolio-' . $fileName . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

if ( ! function_exists( 'FolderFolio\\init' ) ) {
	function init() {
		Helper::getInstance();
		Settings::getInstance();
		API::getInstance();
		Manager::getInstance();
	}
}

add_action( 'plugins_loaded', 'FolderFolio\\init' );

register_activation_hook( __FILE__, array( 'FolderFolio\\Plugin', 'activate' ) );
//register_deactivation_hook( __FILE__, array( 'FolderFolio\\Plugin', 'deactivate' ) );
