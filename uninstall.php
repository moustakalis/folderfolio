<?php

/**
 * Removes every trace of FolderFolio when the plugin is deleted.
 *
 * Deactivation deliberately keeps folder data - people deactivate to test
 * something and expect their folders back. Deleting the plugin is the explicit
 * "I am done with this" action, so that is where cleanup belongs.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Drop this site's FolderFolio tables and options.
 */
function folderfolio_uninstall_site(): void
{
    global $wpdb;

    $tables = [
        'folderfolio_attachment_folders',
        'folderfolio_folder_meta',
        'folderfolio_user_preferences',
        'folderfolio_folders',
    ];

    foreach ($tables as $table) {
        $name = $wpdb->prefix . $table;

        // Table names cannot be bound as parameters — %i quotes one as an
        // identifier; these are built from the install's own prefix and a
        // fixed list, never from input.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping our own tables on uninstall; a schema change by definition.
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $name));
    }

    // The ZIP download's cache of each file's checksum (FolderArchive).
    delete_post_meta_by_key('_folderfolio_crc32');

    // Smart folders (tier 3 item 13) and the size index their size rule reads.
    delete_option('folderfolio_smart_folders');
    delete_option('folderfolio_filesizes_checked');
    delete_post_meta_by_key('_folderfolio_filesize');

    // Settings, a stored import file and the last import run — the readme
    // promises every option goes, and until 24 Sep these three stayed.
    delete_option('folderfolio_settings');
    delete_option('folderfolio_import_file');
    delete_option('folderfolio_import_run');

    // Each person's rail: width, collapsed, stars, starting folder. A user
    // option since 25 Sep, so each site's copy carries that site's prefix;
    // the unprefixed row is the older form (and a single site's fallback).
    delete_metadata('user', 0, $wpdb->get_blog_prefix() . 'folderfolio_rail', '', true);
    delete_metadata('user', 0, 'folderfolio_rail', '', true);

    delete_option('folderfolio_db_version');
    delete_transient('folderfolio_upgrading');
    delete_transient('folderfolio_elsewhere');
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $folderfolio_site_id) {
        switch_to_blog((int) $folderfolio_site_id);
        folderfolio_uninstall_site();
        restore_current_blog();
    }
} else {
    folderfolio_uninstall_site();
}
