<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Modules\Import\Elsewhere;

/**
 * One line under our own row on the plugins screen.
 *
 * The other half of the first-run answer. The rail's empty state catches the
 * moment of *confusion* — somebody opens the media library and wonders where
 * their folders went. This catches the moment of *activation*, which is the
 * screen they are already looking at when they turn FolderFolio on, and the
 * only moment at which "you have folders elsewhere" is news rather than a
 * reminder.
 *
 * ## Why this shape and not a notice
 *
 * `after_plugin_row` appends to our own table row. It is core's own device —
 * the same one an available update uses — so it reads as part of the screen
 * rather than as something added to it. And it needs **no dismissal**: leaving
 * the page is the dismissal, which means no user meta, no AJAX handler, and
 * nothing that can persist wrongly. Of the four rival plugins whose notices
 * were read on 21 Sep, **not one dismisses permanently**: two return after 30
 * days, one after 365, and one cannot be dismissed at all because its AJAX
 * action is unreachable dead code.
 *
 * ## Why it disappears
 *
 * It asks `Elsewhere::waiting()`, which answers null the moment this library
 * has a folder of ours — so the line is gone as soon as the user has done
 * anything at all, including running the import. It is a first-run line, not a
 * standing advertisement for our own importer.
 */
final class PluginsRow
{
    public function register(): void
    {
        add_action(
            'after_plugin_row_' . plugin_basename(FOLDERFOLIO_PLUGIN_DIR . 'folderfolio.php'),
            [$this, 'render'],
            10,
            0
        );
    }

    public function render(): void
    {
        if (!current_user_can('upload_files')) {
            return;
        }

        $found = Elsewhere::waiting();

        if (null === $found) {
            return;
        }

        $mayImport = current_user_can('manage_options');

        /*
         * Core's own markup for an appended row, and the class that makes the
         * row above it lose its bottom border so the two read as one block.
         *
         * The column count comes from the list table core has already built
         * for this screen. Hard-coding 4 is right until somebody adds a column
         * — which plugins do — and then the cell stops short of the row above
         * it, which is exactly the kind of thing nobody notices in review.
         */
        $columns = isset($GLOBALS['wp_list_table']) && is_object($GLOBALS['wp_list_table'])
            && method_exists($GLOBALS['wp_list_table'], 'get_column_count')
                ? (int) $GLOBALS['wp_list_table']->get_column_count()
                : 4;

        $sentence = sprintf(
            /* translators: 1: another plugin's name, 2: a folder count, already phrased, 3: a file count, already phrased. */
            __('%1$s has %2$s holding %3$s.', 'folderfolio'),
            $found['label'],
            sprintf(
                /* translators: %s is a number of folders. */
                _n('%s folder', '%s folders', $found['folders'], 'folderfolio'),
                number_format_i18n($found['folders'])
            ),
            sprintf(
                /* translators: %s is a number of files. */
                _n('%s file', '%s files', $found['files'], 'folderfolio'),
                number_format_i18n($found['files'])
            )
        );

        ?>
        <tr class="plugin-update-tr active folderfolio-plugin-row">
            <td colspan="<?php echo esc_attr((string) $columns); ?>" class="plugin-update colspanchange">
                <div class="update-message notice inline notice-info notice-alt">
                    <p>
                        <?php echo esc_html($sentence); ?>
                        <?php
                        echo esc_html__(
                            'FolderFolio can copy them across — added, never moved, and nothing is removed from where it is now.',
                            'folderfolio'
                        );
                        ?>
                        <?php if ($mayImport) : ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=folderfolio&tab=import')); ?>">
                                <?php esc_html_e('Review the import', 'folderfolio'); ?>
                            </a>
                        <?php endif; ?>
                    </p>
                </div>
            </td>
        </tr>
        <?php
    }
}
