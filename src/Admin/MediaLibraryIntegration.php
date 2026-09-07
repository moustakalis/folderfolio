<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

/**
 * Media Library integration - adds folder UI to wp-admin/upload.php.
 */
class MediaLibraryIntegration
{
    public function register(): void
    {
        // Add folder tree sidebar before media list
        add_action('admin_head-upload.php', [$this, 'enqueueAssets']);
        add_action('media_upload_filters', [$this, 'renderFolderFilter'], 10, 1);
    }

    public function enqueueAssets(): void
    {
        // Folder tree is already enqueued by Plugin.php
        // Add inline script to integrate with Media Library
        add_action('admin_print_scripts-upload.php', [$this, 'printIntegrationScript'], 20);
    }

    public function renderFolderFilter(array $filters): array
    {
        // Add "All Folders" filter option
        $filters['folderfolio_all'] = __('All Folders', 'folderfolio');
        return $filters;
    }

    public function printIntegrationScript(): void
    {
        ?>
        <script>
        (function($) {
            'use strict';

            // Listen for folder selection events
            $(document).on('folderfolio:folder-selected', function(e, data) {
                var folderId = data.detail.folderId;
                console.log('FolderFolio: Folder selected', folderId);

                // Filter Media Library grid by folder
                // This will be expanded in Phase 3.2 to actually filter attachments
                if (typeof wp !== 'undefined' && wp.media) {
                    // Trigger media library refresh with folder filter
                    wp.media.frame.trigger('folderfolio:filter', { folderId: folderId });
                }
            });

            // Add folder info bar above media grid
            $(document).on('folderfolio:folder-selected', function(e, data) {
                var folderId = data.detail.folderId;
                var $infoBar = $('#folderfolio-current-folder-bar');

                if ($infoBar.length === 0) {
                    $infoBar = $('<div id="folderfolio-current-folder-bar" class="media-folder-filter"></div>');
                    $('.wp-filter').first().before($infoBar);
                }

                $infoBar.html('Current folder: <span class="current-folder">Folder #' + folderId + '</span> <button type="button" class="button" id="folderfolio-clear-filter">Clear filter</button>');
            });

            $(document).on('click', '#folderfolio-clear-filter', function() {
                $(this).closest('#folderfolio-current-folder-bar').remove();
                if (typeof wp !== 'undefined' && wp.media) {
                    wp.media.frame.trigger('folderfolio:filter', { folderId: null });
                }
            });

        })(jQuery);
        </script>
        <?php
    }
}
