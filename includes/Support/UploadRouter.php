<?php

declare(strict_types=1);

namespace FolderFolio\Support;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Domain\FolderService;

/**
 * Files new uploads automatically, when something asks it to.
 *
 * Nothing happens by default: the filter returns null and the upload stays
 * unassigned. A site, a theme or another plugin decides.
 *
 * ```php
 * add_filter( 'folderfolio_default_folder_for_upload', function ( $folderId, $attachmentId ) {
 *     $folder = FolderFolio::getOrCreateByPath( 'Uploads/' . gmdate( 'Y/m' ) );
 *
 *     return is_wp_error( $folder ) ? $folderId : $folder->id;
 * }, 10, 2 );
 * ```
 *
 * Premio's Folders sells per-post-type default folders as a Pro feature. This
 * is one filter, and it is more flexible than a settings dropdown because the
 * rule can depend on the file.
 */
final class UploadRouter
{
    public function __construct(
        private readonly FolderService $folders = new FolderService()
    ) {
    }

    public function register(): void
    {
        add_action('add_attachment', [$this, 'route']);
    }

    public function route(int $attachmentId): void
    {
        /**
         * Which folder a newly uploaded attachment belongs in.
         *
         * @since 1.0.0
         *
         * @param int|null $folderId     Folder id, or null to leave it unfiled.
         * @param int      $attachmentId The attachment just created.
         */
        $folderId = apply_filters('folderfolio_default_folder_for_upload', null, $attachmentId);

        if ($folderId === null || $folderId === '' || $folderId === false) {
            return;
        }

        $folderId = (int) $folderId;

        if ($folderId <= 0) {
            return;
        }

        // Deliberately MODE_ADD. A filter that files uploads should never be
        // able to pull a file out of somewhere it already sits.
        $this->folders->assignAttachments($folderId, [$attachmentId], FolderService::MODE_ADD);
    }
}
