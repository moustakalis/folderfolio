<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

/**
 * Admin page handler.
 */
final class AdminPage
{
    /**
     * Register admin hooks.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addAdminMenu']);
    }

    /**
     * Add admin menu page.
     *
     * @return void
     */
    public static function addAdminMenu(): void
    {
        add_media_page(
            'FolderFolio',
            'FolderFolio',
            'manage_options',
            'folderfolio',
            [self::class, 'renderPage']
        );
    }

    /**
     * Render admin page.
     *
     * @return void
     */
    public static function renderPage(): void
    {
        echo '<div class="wrap">';
        echo '<h1>FolderFolio - Media Library Folders</h1>';
        echo '<p>Folder organization for the WordPress Media Library.</p>';
        echo '</div>';
    }
}
