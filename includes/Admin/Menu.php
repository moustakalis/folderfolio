<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The top-level FolderFolio admin menu.
 *
 * It exists now for one reason: the menu icon is the cheapest visible proof
 * that the colour-scheme plumbing works. Brand::menu_icon() is an SVG data URI
 * drawn in currentColor, so WordPress tints it with the scheme's own icon
 * colour — #a7aaad at rest, #fff when current. A PNG, or any hard-coded fill,
 * would be the single item in the sidebar that ignores the user's scheme, and
 * "the menu icon inherits the scheme's icon colour and is never red" is in the
 * design's definition of done.
 *
 * This class owns the whole menu structure. Screens do not register
 * themselves: a page that adds its own menu entry cannot know where it sits,
 * and the hook suffix it gets back depends on that placement. Registering
 * centrally and handing each screen its hook suffix keeps the two in step —
 * Settings and Status join here as siblings when they are built.
 *
 * The landing screen is the Import page rather than a placeholder, because a
 * stub page is worse than no page.
 *
 * Capability is manage_options on the menu itself. CatFolders registers its
 * menu at `read`, so every subscriber on those sites sees a menu item that
 * then refuses them; a menu you cannot use should not be in your sidebar.
 */
final class Menu
{
    /**
     * Slug of both the top-level menu and its first screen.
     *
     * They are deliberately identical: WordPress renders a duplicate first
     * submenu item unless the first child's slug matches its parent's.
     */
    public const SLUG = 'folderfolio-import';

    public function __construct(
        private readonly ImportPage $import
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
    }

    public function addMenuPage(): void
    {
        $hookSuffix = add_menu_page(
            __('Import Folders', 'folderfolio'),
            __('FolderFolio', 'folderfolio'),
            'manage_options',
            self::SLUG,
            [$this->import, 'renderPage'],
            Brand::menu_icon()
        );

        // Rename the auto-created first child from "FolderFolio" to "Import".
        // Same slug as the parent, so no duplicate row appears.
        add_submenu_page(
            self::SLUG,
            __('Import Folders', 'folderfolio'),
            __('Import', 'folderfolio'),
            'manage_options',
            self::SLUG,
            [$this->import, 'renderPage']
        );

        $this->import->setHookSuffix((string) $hookSuffix);
    }
}
