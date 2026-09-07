<?php
namespace FolderFolio;

use FolderFolio\Activate;

defined( 'ABSPATH' ) || exit;

class Plugin {
	private static $instance = null;

	// Private constructor to prevent creating a new instance of the class via the `new` operator from outside of this class
	private function __construct() {}

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


	/** Plugin activated hook */
	public static function activate() {
		$first_time_active = get_option( 'ffbz_first_time_active' );
		if ( $first_time_active === false ) {
			update_option( 'ffbz_is_new_user', 1 );
			update_option( 'ffbz_first_time_active', 1 );
		}
		Activate::create_tables();
	}
}
