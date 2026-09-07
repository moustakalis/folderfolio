<?php
namespace FolderFolio;

defined( 'ABSPATH' ) || exit;

class Manager {
	// Static property to hold the class instance
	private static $instance = null;

	// Private constructor to prevent creating a new instance of the class via the `new` operator from outside of this class
	private function __construct() {
		// Initialize things here, similar to a constructor
		$this->init();
	}

	// Initialization method
	private function init() {
		// add_filter( 'script_loader_tag', array( $this, 'check_es_module' ) , 10, 3);
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	// Method to retrieve or create the class instance
	public static function getInstance() {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	function enqueue_scripts($hook) {
		if ($hook !== 'upload.php') {
			return;
		}

		Helper::vite_enqueue_script('vendor');

		Helper::vite_enqueue_script(
			'_plugin-vue_export-helper',
			array('folderfolio-vendor')
		);

		Helper::vite_enqueue_script(
			'manager',
			['wp-api', 'folderfolio-vendor', 'folderfolio-_plugin-vue_export-helper'],
			false,
			true,
			array('folderfolioManager', [
				'nonce' => wp_create_nonce('wp_rest'),
				'apiUrl' => rest_url('folderfolio/v1/'),
			])
		);

		Helper::vite_enqueue_style('manager');
	}
}

