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
 * centrally and handing each screen its hook suffix keeps the two in step.
 *
 * One entry, not three. Settings, Import and Status are tabs of a single
 * screen — see SettingsPage — because they are read in sequence by the same
 * person and a plugin that owns one panel in the media library does not get
 * four rows in the sidebar.
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
    public const SLUG = SettingsPage::SLUG;

    /*
     * v0.2.0's `folderfolio-import` slug is gone rather than redirected. An
     * unregistered page slug is refused by wp-admin/admin.php before
     * `admin_init` runs, so the redirect would have to be a second, hidden
     * submenu page existing only to forward — and the plugin has not shipped,
     * so there is no link out there to forward.
     */

    public function __construct(
        private readonly SettingsPage $settings
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
    }

    public function addMenuPage(): void
    {
        $hookSuffix = add_menu_page(
            __('FolderFolio', 'folderfolio'),
            __('FolderFolio', 'folderfolio'),
            'manage_options',
            self::SLUG,
            [$this->settings, 'renderPage'],
            Brand::menu_icon()
        );

        // Rename the auto-created first child from "FolderFolio" to
        // "Settings". Same slug as the parent, so no duplicate row appears.
        add_submenu_page(
            self::SLUG,
            __('FolderFolio Settings', 'folderfolio'),
            __('Settings', 'folderfolio'),
            'manage_options',
            self::SLUG,
            [$this->settings, 'renderPage']
        );

        $this->settings->setHookSuffix((string) $hookSuffix);
    }
}
