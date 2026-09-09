<?php

declare(strict_types=1);

namespace FolderFolio\Database;

class Schema
{
    public function migrate(): void
    {
        global $wpdb;

        if (! function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $charset_collate = $wpdb->get_charset_collate();

        // Folders table.
        $table_folders = $wpdb->prefix . 'folderfolio_folders';
        $sql_folders = "CREATE TABLE {$table_folders} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id BIGINT UNSIGNED NULL,
            name VARCHAR(191) NOT NULL,
            slug VARCHAR(191) NULL,
            color CHAR(7) NULL,
            icon VARCHAR(50) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            template_id BIGINT UNSIGNED NULL,
            owner_id BIGINT UNSIGNED NULL,
            visibility VARCHAR(20) NOT NULL DEFAULT 'all',
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY parent_id (parent_id),
            KEY slug (slug),
            KEY owner_id (owner_id)
        ) {$charset_collate};";

        \dbDelta($sql_folders);

        // Attachment-folder assignments table.
        $table_assignments = $wpdb->prefix . 'folderfolio_attachment_folders';
        $sql_assignments = "CREATE TABLE {$table_assignments} (
            folder_id BIGINT UNSIGNED NOT NULL,
            attachment_id BIGINT UNSIGNED NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            assigned_at DATETIME NOT NULL,
            PRIMARY KEY  (folder_id, attachment_id),
            KEY attachment_id (attachment_id)
        ) {$charset_collate};";

        \dbDelta($sql_assignments);

        // Folder meta table.
        $table_meta = $wpdb->prefix . 'folderfolio_folder_meta';
        $sql_meta = "CREATE TABLE {$table_meta} (
            folder_id BIGINT UNSIGNED NOT NULL,
            meta_key VARCHAR(191) NOT NULL,
            meta_value LONGTEXT NULL,
            PRIMARY KEY  (folder_id),
            KEY meta_key (meta_key)
        ) {$charset_collate};";

        \dbDelta($sql_meta);

        // User preferences table.
        $table_preferences = $wpdb->prefix . 'folderfolio_user_preferences';
        $sql_preferences = "CREATE TABLE {$table_preferences} (
            user_id BIGINT UNSIGNED NOT NULL,
            preferences LONGTEXT NULL,
            PRIMARY KEY  (user_id)
        ) {$charset_collate};";

        \dbDelta($sql_preferences);
    }
}
