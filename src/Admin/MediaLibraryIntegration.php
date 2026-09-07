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
        add_action('admin_head-upload.php', [$this, 'enqueueAssets']);
        add_action('media_upload_filters', [$this, 'renderFolderFilter'], 10, 1);
    }

    public function enqueueAssets(): void
    {
        add_action('admin_print_scripts-upload.php', [$this, 'printIntegrationScript'], 20);
    }

    public function renderFolderFilter(array $filters): array
    {
        $filters['folderfolio_all'] = __('All Folders', 'folderfolio');
        return $filters;
    }

    public function printIntegrationScript(): void
    {
        ?>
        <script>
        (function($) {
            'use strict';

            // Signal that media library is ready
            $(document).on('wp-media-grid-initialized', function() {
                window.dispatchEvent(new CustomEvent('folderfolio:media-ready'));
            });

            // Listen for folder selection events
            $(document).on('folderfolio:folder-selected', function(e, data) {
                var folderId = data.detail.folderId;
                console.log('FolderFolio: Folder selected', folderId);

                // Filter Media Library grid by folder
                if (typeof wp !== 'undefined' && wp.media) {
                    wp.media.frame.trigger('folderfolio:filter', { folderId: folderId });
                }
            });

            // Add folder info bar above media grid
            $(document).on('folderfolio:folder-selected', function(e, data) {
                var folderId = data.detail.folderId;
                var $infoBar = $('#folderfolio-current-folder-bar');

                if ($infoBar.length === 0) {
                    $infoBar = $('<div id="folderfolio-current-folder-bar" class="media-folder-filter"></div>');
                    $('.wp-filter').first().after($infoBar);
                }

                $infoBar.html('Filtering by folder #' + folderId + ' <button type="button" class="button" id="folderfolio-clear-filter">Clear filter</button>');
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
