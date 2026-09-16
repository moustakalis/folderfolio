<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

/**
 * Import page admin UI.
 */
class ImportPage
{

    public function __construct()
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
    }

    public function addMenuPage(): void
    {
        add_submenu_page(
            'upload.php',
            __('Import Folders', 'folderfolio'),
            __('Import', 'folderfolio'),
            'manage_options',
            'folderfolio-import',
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Import Folders', 'folderfolio'); ?></h1>

            <div id="folderfolio-import-app"></div>

            <script>
            (function($) {
                'use strict';

                const importApp = {
                    importers: [],

                    init: function() {
                        this.loadImporters();
                        this.bindEvents();
                    },

                    loadImporters: async function() {
                        try {
                            const response = await wp.apiFetch({ path: '/folderfolio/v1/import/detect' });
                            this.importers = response.data.importers;
                            this.render();
                        } catch (error) {
                            console.error('Failed to load importers', error);
                            $('#folderfolio-import-app').html('<p>Failed to load importers</p>');
                        }
                    },

                    render: function() {
                        const $container = $('#folderfolio-import-app');
                        let html = '<table class="wp-list-table widefat fixed striped">';
                        html += '<thead><tr>';
                        html += '<th>Plugin</th>';
                        html += '<th>Status</th>';
                        html += '<th>Folders</th>';
                        html += '<th>Attachments</th>';
                        html += '<th>Action</th>';
                        html += '</tr></thead><tbody>';

                        this.importers.forEach(function(importer) {
                            html += '<tr>';
                            html += '<td>' + importer.name + '</td>';
                            html += '<td>' + (importer.installed ? '<span style="color: #00a32a;">Installed</span>' : '<span style="color: #b32d2e;">Not installed</span>') + '</td>';
                            html += '<td>' + (importer.installed ? importer.folder_count : '-') + '</td>';
                            html += '<td>' + (importer.installed ? importer.attachment_count : '-') + '</td>';
                            html += '<td>';
                            if (importer.installed) {
                                html += '<button type="button" class="button button-primary" data-importer="' + importer.key + '">Import</button>';
                            } else {
                                html += '<button type="button" class="button" disabled>Not installed</button>';
                            }
                            html += '</td>';
                            html += '</tr>';
                        });

                        html += '</tbody></table>';
                        $container.html(html);
                    },

                    bindEvents: function() {
                        $(document).on('click', '[data-importer]', async function(e) {
                            const $btn = $(e.currentTarget);
                            const importerKey = $btn.data('importer');
                            const importer = importApp.importers.find(i => i.key === importerKey);

                            if (!importer || !importer.installed) {
                                return;
                            }

                            const confirmed = confirm('Import ' + importer.folder_count + ' folders and ' + importer.attachment_count + ' attachments from ' + importer.name + '?');
                            if (!confirmed) {
                                return;
                            }

                            $btn.prop('disabled', true).text('Importing...');

                            try {
                                const response = await wp.apiFetch({
                                    path: '/folderfolio/v1/import/' + importerKey,
                                    method: 'POST',
                                });

                                if (response.success) {
                                    alert('Import complete!\n\nFolders: ' + response.data.imported_folders + '\nAssignments: ' + response.data.imported_assignments);
                                    location.reload();
                                } else {
                                    alert('Import failed: ' + response.error);
                                    $btn.prop('disabled', false).text('Import');
                                }
                            } catch (error) {
                                console.error('Import failed', error);
                                alert('Import failed');
                                $btn.prop('disabled', false).text('Import');
                            }
                        });
                    }
                };

                $(document).ready(function() {
                    importApp.init();
                });

            })(jQuery);
            </script>
        </div>
        <?php
    }
}
