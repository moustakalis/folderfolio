<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Support\Capabilities;
use FolderFolio\Support\Settings;

/**
 * The folder column inside WordPress's media picker — screen 10.
 *
 * ## What this replaces
 *
 * v0.2.0's version of this class shipped a bundle that reassigned
 * `wp.media.create` to a wrapper of its own. That is a global swap of the
 * factory every plugin on the site calls: two plugins doing it means one
 * wrapper wins and the other's frames are never decorated, and which one
 * depends on enqueue order. It was left unregistered through the rebuild for
 * that reason, and none of it survives — see `assets/src/lib/media-frame.ts`
 * for what does.
 *
 * ## Where it loads
 *
 * Every admin screen that can open a picker, and **not** `upload.php`.
 *
 * The library grid's own frame has the same
 * `.media-frame-content > .attachments-browser` markup as a modal, so the
 * column's own slot would find it there — and that screen already has the
 * rail. Two folder trees on one screen is worse than one, and the modal it
 * does open (attachment details) has no attachments browser to filter anyway.
 *
 * The screen list is a filter, so a plugin with its own picker screen can add
 * to it rather than forking the plugin.
 */
final class MediaModalIntegration
{
    /**
     * Admin screens that can open a media picker.
     *
     * `edit.php` and `post.php`/`post-new.php` cover the editors, `themes.php`
     * and `customize.php` the appearance screens, `widgets.php` the classic
     * widgets screen, `site-editor.php` the block themes' editor.
     *
     * @var list<string>
     */
    private const SCREENS = [
        'post.php',
        'post-new.php',
        'edit.php',
        'themes.php',
        'customize.php',
        'widgets.php',
        'site-editor.php',
    ];

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if (!current_user_can('upload_files')) {
            return;
        }

        /** @var list<string> $screens */
        $screens = (array) apply_filters('folderfolio_media_frame_screens', self::SCREENS);

        if (!in_array($hookSuffix, $screens, true)) {
            return;
        }

        $manifest = FOLDERFOLIO_PLUGIN_DIR . 'assets/build/apps/modal.asset.php';

        if (!file_exists($manifest)) {
            return;
        }

        /*
         * `wp_enqueue_media()` is what puts `wp.media` on the page. The
         * editors call it themselves, but the appearance screens do not always,
         * and a column that renders into a frame that never exists is not a
         * failure worth debugging twice.
         */
        wp_enqueue_media();

        /** @var array{dependencies: list<string>, version: string} $asset */
        $asset = require $manifest;

        wp_enqueue_style(
            'folderfolio-frame',
            FOLDERFOLIO_PLUGIN_URL . 'assets/build/core/frame.css',
            [],
            FOLDERFOLIO_VERSION
        );

        wp_enqueue_script(
            'folderfolio-frame',
            FOLDERFOLIO_PLUGIN_URL . 'assets/build/apps/modal.js',
            array_merge($asset['dependencies'], ['wp-api-fetch', 'media-views', 'wp-plupload']),
            $asset['version'],
            ['in_footer' => true, 'strategy' => 'defer']
        );

        wp_add_inline_script(
            'folderfolio-frame',
            'window.folderFolio = window.folderFolio || ' . wp_json_encode($this->config()) . ';',
            'before'
        );
    }

    /**
     * The labels the column reads.
     *
     * The same keys the rail's app uses, because it is the same components —
     * see Rail::appConfig(). Duplicated as data rather than shared through a
     * base class: the two screens do not load the same bundles, and a label
     * this screen never renders has no business being sent to it.
     *
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $settings = Settings::get();

        return [
            'restUrl' => esc_url_raw(rest_url('folderfolio/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'pluginUrl' => FOLDERFOLIO_PLUGIN_URL,
            'version' => FOLDERFOLIO_VERSION,

            // The same four abilities and the same three settings as the
            // library's config. The modal renders the same components, so a
            // key missing here is a component behaving differently inside the
            // picker than it does in the library.
            'can' => [
                'create' => Capabilities::can('create'),
                'rename' => Capabilities::can('rename'),
                'delete' => Capabilities::can('delete'),
                'assign' => Capabilities::can('assign'),
            ],
            'countMode' => $settings['count_mode'],
            'defaultSort' => $settings['default_sort'],
            'undoWindow' => $settings['undo_window'],
            'i18n' => [
                'folders' => __('Folders', 'folderfolio'),
                'folderActions' => __('Folder actions', 'folderfolio'),
                // Screen 10's footer line. Only this screen renders it: the
                // rail has no Select button to sit beside.
                'uploadsGoToFolder' => __('Uploads go to the selected folder.', 'folderfolio'),
                'newFolder' => __('New folder', 'folderfolio'),
                'rename' => __('Rename', 'folderfolio'),
                'renameFolder' => __('Rename folder', 'folderfolio'),
                'newFolderName' => __('Name for the new folder', 'folderfolio'),
                'delete' => __('Delete', 'folderfolio'),
                'sortNameAsc' => __('Name, A to Z', 'folderfolio'),
                'sortNameDesc' => __('Name, Z to A', 'folderfolio'),
                'sortNewest' => __('Newest first', 'folderfolio'),
                'sortOldest' => __('Oldest first', 'folderfolio'),
                'allMedia' => __('All media', 'folderfolio'),
                'unassigned' => __('Unassigned', 'folderfolio'),
                'searchPlaceholder' => __('Search folders', 'folderfolio'),
                'breadcrumb' => __('Folder path', 'folderfolio'),
                'emptyTree' => __('No folders yet', 'folderfolio'),
                'retry' => __('Retry', 'folderfolio'),
                'undo' => __('Undo', 'folderfolio'),
                /* translators: %s is the folder name. */
                'deleted' => __('Deleted “%s”', 'folderfolio'),
                /* translators: 1: folder name, 2: number of files. */
                'deletedWithFiles' => __(
                    'Deleted “%s” — %s files moved to Unassigned',
                    'folderfolio'
                ),
                'treeFailed' => __('Could not load your folders.', 'folderfolio'),
                /* translators: %s is the search term that matched nothing. */
                'noMatch' => __('No folder matches “%s”.', 'folderfolio'),
            ],
        ];
    }
}
