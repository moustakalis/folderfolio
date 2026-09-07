<?php
namespace FolderFolio;

use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

class API {
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

	private function init() {
		// Hook into REST API init to register our routes
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	// Register REST API routes
	public function register_routes() {
		register_rest_route('folderfolio/v1', '/create-folder', array(
			'methods' => 'POST',
			'callback' => array( $this, 'create_folder' ),
			'permission_callback' => array( $this, 'permissions_check' )
		));

		register_rest_route('folderfolio/v1', '/rename-folder', array(
			'methods' => 'POST',
			'callback' => array( $this, 'rename_folder' ),
			'permission_callback' => array( $this, 'permissions_check' )
		));

		register_rest_route('folderfolio/v1', '/delete-folder', array(
			'methods' => 'DELETE',
			'callback' => array( $this, 'delete_folder' ),
			'permission_callback' => array( $this, 'permissions_check' )
		));

		register_rest_route('folderfolio/v1', '/folders', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_folders' ),
			'permission_callback' => array( $this, 'permissions_check' )
		));
	}

	// Permission callback to check if the user can perform the action
	public function permissions_check($request) {
		return current_user_can('upload_files');
	}

	// Create a new folder in the database
	public function create_folder($request) {
		global $wpdb;

		$folder_name = sanitize_text_field($request['name']);
		$folder_order = intval($request['order']);
		$folder_type = sanitize_text_field($request['type']);
		$folder_color = sanitize_hex_color($request['color']);
		$created_by = get_current_user_id();

		$wpdb->insert(
			"{$wpdb->prefix}ffbz",
			array(
				'name' => $folder_name,
				'order' => $folder_order,
				'type' => $folder_type,
				'color' => $folder_color,
				'created_by' => $created_by
			),
			array('%s', '%d', '%s', '%s', '%d')
		);

		return new WP_REST_Response(array('message' => 'Folder created successfully.', 'folderId' => $wpdb->insert_id), 200);
	}

	// Rename an existing folder in the database
	public function rename_folder($request) {
		global $wpdb;

		$folder_id = intval($request['id']);
		$new_name = sanitize_text_field($request['newName']);
		$updated_by = get_current_user_id();

		$wpdb->update(
			"{$wpdb->prefix}ffbz",
			array(
				'name' => $new_name,
				'updated_by' => $updated_by
			),
			array('id' => $folder_id),
			array('%s', '%d'),
			array('%d')
		);

		return new WP_REST_Response(array('message' => 'Folder renamed successfully.', 'folderId' => $folder_id), 200);
	}

	// Delete a folder from the database (soft delete)
	public function delete_folder($request) {
		global $wpdb;

		$folder_id = intval($request['id']);
		$deleted_by = get_current_user_id();

		// Update folder as deleted
		$result = $wpdb->update(
			"{$wpdb->prefix}ffbz",
			array(
				'deleted_by' => $deleted_by,
				'deleted_at' => current_time('mysql'),
			),
			array('id' => $folder_id),
			array('%d', '%s'),
			array('%d')
		);

		if ($result === false) {
			// If there was an error in the deletion process
			return new WP_REST_Response(array('message' => 'Failed to delete folder.', 'folderId' => $folder_id), 400);
		} else {
			// Optionally, delete all relations to this folder in wp_my_media_folder_attachments
			$wpdb->delete(
				"{$wpdb->prefix}ffbz_folder_attachments",
				array('folder_id' => $folder_id),
				array('%d')
			);

			return new WP_REST_Response(array('message' => 'Folder deleted successfully.', 'folderId' => $folder_id), 200);
		}
	}

	public function get_folders($request) {
		global $wpdb;
		$folders = $wpdb->get_results("SELECT id, name, parent_id FROM {$wpdb->prefix}ffbz WHERE deleted_at IS NULL ORDER BY `order` ASC", ARRAY_A);

		// Optionally, format the folders into a hierarchical structure
		$folders = $this->build_tree($folders);

		return new WP_REST_Response($folders, 200);
	}

	protected function build_tree(array $folders, $parent_id = 0) {
		$branch = array();

		foreach ($folders as $folder) {
			if ($folder['parent_id'] == $parent_id) {
				$children = $this->build_tree($folders, $folder['id']);
				if ($children) {
					$folder['children'] = $children;
				}
				$branch[] = $folder;
			}
		}

		return $branch;
	}
}
