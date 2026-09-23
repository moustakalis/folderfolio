<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Domain\FolderService;
use FolderFolio\Support\Capabilities;
use FolderFolio\Support\PostTypes;

/**
 * Filing a post from the editor — tier 3 item 12, Nick's 12d.
 *
 * Two halves:
 *
 * - **The Folders panel** in the block editor's document sidebar
 *   (assets/src/apps/post-folders.tsx), for a type with folders and a person
 *   who can reach them. Its config is its own global, `folderFolioPost`: the
 *   same page loads the media picker's bundle, whose `folderFolio` is media's.
 * - **Add New from inside a folder.** The rail on a post list adds the folder
 *   being viewed to the *Add New* link (`folderfolio_folder`), and the post
 *   that screen creates — the auto-draft `post-new.php` inserts before the
 *   editor opens — is filed there. The panel then opens with that folder
 *   ticked, and it can be unticked like any other. FileBird does the same
 *   from the referrer; a parameter in the link says it without guessing.
 */
final class PostFolders
{
    public function __construct(
        private readonly FolderService $folders = new FolderService()
    ) {
    }

    public function register(): void
    {
        add_action('enqueue_block_editor_assets', [$this, 'enqueueAssets']);
        add_action('wp_insert_post', [$this, 'fileNewPost'], 10, 3);
    }

    public function enqueueAssets(): void
    {
        $type = self::editorType();

        if (null === $type) {
            return;
        }

        $manifest = FOLDERFOLIO_PLUGIN_DIR . 'assets/build/apps/post-folders.asset.php';

        if (!file_exists($manifest)) {
            return;
        }

        /** @var array{dependencies: list<string>, version: string} $asset */
        $asset = require $manifest;

        wp_enqueue_script(
            'folderfolio-post-folders',
            FOLDERFOLIO_PLUGIN_URL . 'assets/build/apps/post-folders.js',
            array_merge(
                $asset['dependencies'],
                ['wp-api-fetch', 'wp-plugins', 'wp-editor', 'wp-data', 'wp-components']
            ),
            $asset['version'],
            ['in_footer' => true]
        );

        wp_add_inline_script(
            'folderfolio-post-folders',
            'window.folderFolioPost = ' . wp_json_encode($this->config($type)) . ';',
            'before'
        );

        // Four rules; not worth a stylesheet request.
        wp_register_style('folderfolio-post-folders', false, [], FOLDERFOLIO_VERSION);
        wp_enqueue_style('folderfolio-post-folders');
        wp_add_inline_style(
            'folderfolio-post-folders',
            '.folderfolio-post-folders__list{margin:0 0 8px;padding:0;list-style:none;max-height:260px;overflow:auto}'
            . '.folderfolio-post-folders__list li{margin:0 0 6px}'
            . '.folderfolio-post-folders__note{margin:0 0 10px;color:#757575}'
            . '.folderfolio-post-folders__manage{margin:0}'
        );
    }

    /**
     * The post type being edited, when it has folders this person can reach.
     */
    private static function editorType(): ?string
    {
        if (!function_exists('get_current_screen')) {
            return null;
        }

        $screen = get_current_screen();

        if (!$screen || 'post' !== $screen->base) {
            return null;
        }

        $type = (string) $screen->post_type;

        if ('' === $type || PostTypes::MEDIA === $type || !Capabilities::canUseFolders($type)) {
            return null;
        }

        return $type;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(string $type): array
    {
        return [
            'objectType' => $type,
            'canAssign' => Capabilities::can('assign', $type),
            'manageUrl' => esc_url_raw(
                PostTypes::MEDIA === $type ? admin_url('upload.php') : add_query_arg('post_type', $type, admin_url('edit.php'))
            ),
            'i18n' => [
                'panel' => __('Folders', 'folderfolio'),
                'inNoFolder' => __('Not in any folder.', 'folderfolio'),
                'inOneFolder' => __('In 1 folder.', 'folderfolio'),
                /* translators: %s: a number of folders, 2 or more. */
                'inFolders' => __('In %s folders.', 'folderfolio'),
                'noFolders' => __('There are no folders here yet.', 'folderfolio'),
                'manage' => __('Make one on the list screen', 'folderfolio'),
                'manageAll' => __('Manage folders', 'folderfolio'),
                'loadFailed' => __('The folders could not be loaded.', 'folderfolio'),
                'saveFailed' => __('The folder could not be changed.', 'folderfolio'),
            ],
        ];
    }

    /**
     * File the post *Add New* just made into the folder its link named.
     *
     * Only the auto-draft `post-new.php` inserts, only on that screen, only
     * with the parameter present, and only through `assignAttachments()` — so
     * the folder has to be this type's, the person has to be allowed to file
     * into it and to edit this post, and a lock or a stale id fails quietly
     * rather than stopping a new post from opening.
     *
     * @param int   $postId
     * @param mixed $post
     * @param bool  $update
     */
    public function fileNewPost($postId, $post, $update): void
    {
        global $pagenow;

        if ($update || 'post-new.php' !== $pagenow || !$post instanceof \WP_Post || 'auto-draft' !== $post->post_status) {
            return;
        }

        // A GET parameter on a screen the person opened; the capability
        // checks inside assignAttachments() are what matter, not a nonce.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw = $_GET[MediaLibraryFilter::QUERY_VAR] ?? null;
        $folderId = MediaLibraryFilter::normalizeFolderId($raw);

        if (null === $folderId || $folderId <= 0) {
            return;
        }

        $folder = $this->folders->get($folderId);

        if (null === $folder || $folder->objectType !== $post->post_type || !Capabilities::can('assign', $post->post_type)) {
            return;
        }

        $this->folders->assignAttachments($folderId, [(int) $postId]);
    }
}
