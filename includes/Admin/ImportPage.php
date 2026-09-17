<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The Import tab of the settings screen — screen 08's second tab.
 *
 * It had a page of its own until the settings screen was built; it is a tab
 * now, and SettingsPage owns the chrome around it. What is left here is the
 * body and the two scripts it needs.
 *
 * The body itself is still v0.2.0's: a jQuery table with confirm() and
 * alert(). It is deliberately untouched at this step. Screen 07 is a
 * four-step wizard — detect, preview, run, report — and replacing a dialog
 * with a dialog on the way there would be two rewrites of the same code. The
 * wizard is the next step, and it lands in renderTab().
 */
class ImportPage
{
    public function register(): void
    {
        // No admin_enqueue_scripts hook of its own, and no hook suffix to
        // remember. SettingsPage is the screen now: it knows which tab is
        // showing, and these scripts have nothing to do on the other two.
    }

    /**
     * The body is rendered with an inline script that uses both jQuery and
     * wp.apiFetch; neither is enqueued by default on this screen.
     */
    public function enqueueTabAssets(): void
    {
        wp_enqueue_script('jquery');
        wp_enqueue_script('wp-api-fetch');
    }

    /**
     * The whole page, for anything still calling it directly.
     *
     * @deprecated The screen is a tab now: SettingsPage::renderPage() draws
     *             the card and calls renderTab().
     */
    public function renderPage(): void
    {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Import Folders', 'folderfolio'); ?></h1>
            <?php $this->renderTab(); ?>
        </div>
        <?php
    }

    public function renderTab(): void
    {
        ?>
        <div>
            <p class="folderfolio-lede">
                <?php esc_html_e('Folders from another plugin are copied, never moved: nothing is removed from the plugin you import from, and a folder you already have keeps the files it already has.', 'folderfolio'); ?>
            </p>

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
