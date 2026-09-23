<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Support\Assets;
use FolderFolio\Support\Capabilities;
use FolderFolio\Support\ClientConfig;
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

        // A post list with its own folder rail (tier 3 item 12). Both would
        // write window.folderFolio — media's abilities and labels against the
        // rail's for posts — and the list screen opens no media picker of its
        // own to need this.
        if ('edit.php' === $hookSuffix && null !== Rail::screenType()) {
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
            Assets::version('assets/build/core/frame.css')
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
            ClientConfig::script($this->config()),
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
                'lock' => Capabilities::can('lock'),
                'download' => Capabilities::can('download'),
            ],
            // The person's stars, so the picker's ⋮ shows Star pressed where it
            // is — the same key the rail's writer carries.
            'stars' => RailPreferences::forUser(get_current_user_id())['stars'],
            /*
             * The same key the rail's own writer carries, for the reason that
             * writer states: whichever bundle is enqueued first wins and the
             * other does not clobber it, so a key present in only one is a
             * value that exists on some screens and not others. The media
             * modal never draws the toggle, but on upload.php both scripts
             * are enqueued and either can be the one that wins.
             */
            'startupFolder' => RailPreferences::forUser(get_current_user_id())['startup'],
            'countMode' => $settings['count_mode'],
            'defaultSort' => $settings['default_sort'],
            'undoWindow' => $settings['undo_window'],
            // The rail's strings first — the picker renders the same menu,
            // marks and sheets — and the picker's own over them.
            'i18n' => array_merge(Rail::strings(), [
                'folders' => __('Folders', 'folderfolio'),
                'folderActions' => __('Folder actions', 'folderfolio'),
                // Screen 10's footer line. Only this screen renders it: the
                // rail has no Select button to sit beside.
                'uploadsGoToFolder' => __('Uploads go to the selected folder.', 'folderfolio'),

                // Folder upload — tier 2 item 9 (core/folder-upload.ts). The
                // rail says these on its notice sheet; the picker has none yet
                // and carries them so the day it does they are translated.
                // The link under core's Select Files (core/select-folder.ts),
                // board UzMC1qdGkxa2JQckXu65tW option B.
                'selectFolder' => __('or select a folder', 'folderfolio'),
                'uploadNoFolders' => __('You can upload these files but not make folders, so they are uploading without their folders.', 'folderfolio'),
                /* translators: %s: the reason, a sentence from the server. */
                'uploadFoldersFailed' => __('The folders in this upload could not be made, so its files are uploading without them. %s', 'folderfolio'),
                'newFolder' => __('New folder', 'folderfolio'),
                'rename' => __('Rename', 'folderfolio'),
                'renameFolder' => __('Rename folder', 'folderfolio'),
                'newFolderName' => __('Name for the new folder', 'folderfolio'),
                'delete' => __('Delete', 'folderfolio'),
                'clearFilter' => __('Clear filter', 'folderfolio'),
                'startHere' => __('Start here', 'folderfolio'),
                'startHereHint' => __('Open the media library in this folder', 'folderfolio'),
                'dismiss' => __('Dismiss', 'folderfolio'),
                'startupFolderNote' => __('The media library opens in this folder. Clear the filter in the path above to see everything.', 'folderfolio'),
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
                /*
                 * Two forms, chosen by the count (`tn()`), and the count is of
                 * the files that are filed nowhere else — a file still in
                 * another folder does not move to Unassigned, and the toast
                 * used to say it did.
                 *
                 * translators: 1: folder name, 2: number of files, always 1.
                 */
                'deletedWithFile' => __(
                    'Deleted “%1$s” — %2$s file moved to Unassigned',
                    'folderfolio'
                ),
                /* translators: 1: folder name, 2: number of files. */
                'deletedWithFiles' => __(
                    'Deleted “%1$s” — %2$s files moved to Unassigned',
                    'folderfolio'
                ),
                'treeFailed' => __('Could not load your folders.', 'folderfolio'),
                /* translators: %s is the search term that matched nothing. */
                'noMatch' => __('No folder matches “%s”.', 'folderfolio'),
            ]),
        ];
    }
}
