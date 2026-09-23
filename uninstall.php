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

        // Table names cannot be bound as parameters; these are built from the
        // install's own prefix and a fixed list, never from input.
        $wpdb->query("DROP TABLE IF EXISTS {$name}");
    }

    // The ZIP download's cache of each file's checksum (FolderArchive).
    delete_post_meta_by_key('_folderfolio_crc32');

    delete_option('folderfolio_db_version');
    delete_transient('folderfolio_upgrading');
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
        switch_to_blog((int) $siteId);
        folderfolio_uninstall_site();
        restore_current_blog();
    }
} else {
    folderfolio_uninstall_site();
}
