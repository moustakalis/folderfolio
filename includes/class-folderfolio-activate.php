<?php

namespace FolderFolio;

defined( 'ABSPATH' ) || exit;

class Activate {
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$table_ffbz = $wpdb->prefix . 'ffbz';
		//type == 0: folder
		//type == 1: collection
		if ( $wpdb->get_var( "show tables like '$table_ffbz'" ) != $table_ffbz ) {
			$sql = 'CREATE TABLE ' . $table_ffbz . ' (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(250) NOT NULL,
            `parent` int(11) NOT NULL DEFAULT 0,
            `type` int(2) NOT NULL DEFAULT 0,
            `order` int(11) NULL DEFAULT 0,
            `color` varchar(30) NULL,
            `created_by` int(11) NULL DEFAULT 0,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` TIMESTAMP on update CURRENT_TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
			`deleted_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY `id` (id)) ' . $charset_collate . ';';
			dbDelta( $sql );
		}

		$table = $wpdb->prefix . 'ffbz_folder_attachments';
		//type == 0: folder
		//type == 1: collection
		if ( $wpdb->get_var( "show tables like '{$wpdb->prefix}ffbz_attachment_folder'" ) != $table ) {
			$sql = 'CREATE TABLE ' . $table . ' (
            `folder_id` int(11) unsigned NOT NULL,
            `attachment_id` bigint(20) unsigned NOT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` TIMESTAMP on update CURRENT_TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
			`deleted_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY( `folder_id`, `attachment_id`)
            )' . $charset_collate . ';';
			dbDelta( $sql );
		}
	}
}
