<?php

namespace FolderFolio;

defined( 'ABSPATH' ) || exit;

class Helper {
	private static $script_handles = [];

	// Static property to hold the class instance
	private static $instance = null;

	// Private constructor to prevent creating a new instance of the class via the `new` operator from outside of this class
	private function __construct() {
		// Initialize things here, similar to a constructor
		$this->init();
	}

	// Prevent cloning of the instance
	private function __clone() {}

	// Prevent unserialization of the instance
	private function __wakeup() {}

	// Method to retrieve or create the class instance
	public static function getInstance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	// Initialization method
	private function init() {
		add_filter( 'script_loader_tag', array( $this, 'check_es_module' ), 10, 3 );
	}

	function stringContainsAny( $string, $items ) {
		foreach ( $items as $item ) {
			if ( strpos( $string, $item ) !== false ) {
				// If the item is found in the string, return true
				return true;
			}
		}

		// If none of the items are found in the string, return false
		return false;
	}

	function check_es_module( $tag, $handle, $src ) {
		if ( $this->stringContainsAny( $handle, Helper::$script_handles ) ) {
			return '<script type="module" src="' . esc_url( $src ) . '"></script>';
		}

		return $tag;
	}

	// New method to add script handles to the array
	public static function addScriptHandle( $handle ) {
		self::$script_handles[] = $handle;
	}

	public static function vite_enqueue_script( $fileName, $deps = array(), $ver = false, $args = true, $localize = null ) {
		$dir = BZFF_PLUGIN_PATH . 'dist/assets/js/';

		foreach ( glob( $dir . $fileName . '*.js' ) as $file ) {
			$handle = 'folderfolio-' . $fileName;
			Helper::addScriptHandle( $handle );
			wp_enqueue_script( $handle, BZFF_PLUGIN_URL . 'dist/assets/js/' . basename( $file ), $deps, $ver, $args );

			if ( ! $localize ) {
				return;
			}

			// Localize script
			wp_localize_script( $handle, $localize[0], $localize[1] );
		}
	}

	public static function vite_enqueue_style( $fileName ) {
		$dir = BZFF_PLUGIN_PATH . 'dist/assets/css/';

		foreach ( glob( $dir . $fileName . '*.css' ) as $file ) {
			$handle = 'folderfolio-' . $fileName;
			wp_enqueue_style($handle, BZFF_PLUGIN_URL . 'dist/assets/css/' . basename($file));
		}
	}
}
