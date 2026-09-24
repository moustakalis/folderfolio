<?php

declare(strict_types=1);

namespace FolderFolio\Modules\Import;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Error;

/**
 * Every plugin FolderFolio can import from.
 *
 * The list is CatFolders' own — its `ImportController` hardcodes nine sources
 * with their detection keys, which is the market's answer to "what do people
 * migrate from" and a better list than one assembled from install counts.
 * Every schema below was read from the source plugin's own `CREATE TABLE` or
 * `register_taxonomy()` call, in a WordPress with all four of the big ones
 * installed, because the version of this importer that was written from
 * documentation was aimed at tables FileBird has never had.
 *
 * ## The one that is missing
 *
 * WP Media Library Folders stores folders as posts of type
 * `mgmlp_media_folder` — a third storage shape, and the only one here with no
 * reader. Its schema has not been read from its source, and the lesson of the
 * FileBird correction is that a reader written against a guessed schema is
 * worse than no reader: it detects nothing, reports zero, and looks like a
 * working feature. It goes in when its plugin can be installed and read.
 *
 * ## Keys are permanent
 *
 * A key appears in a REST path, in a run record, and in the provenance meta on
 * every folder an import creates. Changing one orphans the provenance that
 * makes a second run reconcile instead of duplicate.
 */
final class Catalog
{
    /**
     * @return list<Source>
     */
    public static function all(): array
    {
        $sources = [
            // ---- Custom tables -------------------------------------------
            new TableSource(
                key: 'filebird',
                label: 'FileBird',
                pluginFile: 'filebird/filebird.php',
                folderTable: 'fbv',
                assignmentTable: 'fbv_attachment_folder',
                nameColumn: 'name',
                rootMarker: 0,
                // `type` 0 is a folder, 1 is a collection. Collections are a
                // different feature and are not a folder tree.
                scopeValue: 0,
                scopeColumn: 'type',
                folderIdColumn: 'folder_id',
                attachmentColumn: 'attachment_id',
            ),
            new TableSource(
                key: 'real-media-library',
                label: 'Real Media Library',
                pluginFile: 'real-media-library-lite/index.php',
                folderTable: 'realmedialibrary',
                assignmentTable: 'realmedialibrary_posts',
                nameColumn: 'name',
                // The third root convention, and the one that would silently
                // create a phantom parent if it were assumed to be 0.
                rootMarker: -1,
                scopeValue: '0',
                scopeColumn: 'type',
                folderIdColumn: 'fid',
                attachmentColumn: 'attachment',
            ),
            new TableSource(
                key: 'catfolders',
                label: 'CatFolders',
                pluginFile: 'catfolders/catfolders.php',
                folderTable: 'catfolders',
                assignmentTable: 'catfolders_posts',
                nameColumn: 'title',
                rootMarker: 0,
                scopeValue: 'attachment',
                scopeColumn: 'type',
                folderIdColumn: 'folder_id',
                attachmentColumn: 'post_id',
            ),

            // ---- Taxonomy terms ------------------------------------------
            new TaxonomySource('folders', 'Folders', 'folders/folders.php', 'media_folder'),
            new TaxonomySource(
                'wicked-folders',
                'Wicked Folders',
                'wicked-folders/wicked-folders.php',
                'wf_attachment_folders'
            ),
            new TaxonomySource(
                'enhanced-media-library',
                'Enhanced Media Library',
                'enhanced-media-library/enhanced-media-library.php',
                'media_category'
            ),
            new TaxonomySource(
                'media-library-assistant',
                'Media Library Assistant',
                'media-library-assistant/index.php',
                'attachment_category'
            ),
            new TaxonomySource(
                'wp-media-folder',
                'WP Media Folder',
                'wp-media-folder/wp-media-folder.php',
                'wpmf-category'
            ),
            new TaxonomySource('happyfiles', 'HappyFiles', 'happyfiles/happyfiles.php', 'happyfiles_category'),
        ];

        /**
         * Filters the sources the import wizard offers.
         *
         * A site with a folder plugin nobody has heard of can add a Source of
         * its own here rather than waiting for one to ship.
         *
         * @param list<Source> $sources
         */
        $filtered = apply_filters('folderfolio_import_sources', $sources);

        return array_values(array_filter(
            is_array($filtered) ? $filtered : $sources,
            static fn ($source): bool => $source instanceof Source
        ));
    }

    public static function find(string $key): ?Source
    {
        foreach (self::all() as $source) {
            if ($source->key() === $key) {
                return $source;
            }
        }

        // The uploaded export file — found, never detected. See JsonSource
        // on why it is not in all(): it is not a plugin in this database, and
        // detection is a question about those. Finding it here is what lets
        // the planner, every batch of the runner and the run payload reach it
        // without knowing a file source exists.
        $file = JsonSource::stored();

        return $file !== null && $file->key() === $key ? $file : null;
    }

    /**
     * The source a command line names — `wp folderfolio import` (24 Sep).
     *
     * A source's key (`filebird`, `real-media-library`, …) or the path to a
     * FolderFolio export file. A file is read and stored exactly as the
     * wizard's *Choose file…* stores it, because every batch of the runner
     * finds its source again through `find()` — and refused while an import
     * is running, for the reason the wizard refuses it: the stored file is
     * what that run is reading.
     */
    public static function fromArgument(string $argument): Source|WP_Error
    {
        if (str_ends_with(strtolower($argument), '.json') || is_file($argument)) {
            $current = (new RunStore())->current();

            if (null !== $current && !$current->isFinished()) {
                return new WP_Error(
                    'folderfolio_import_in_progress',
                    __('An import is already running. Wait for it to finish, or stop it first.', 'folderfolio')
                );
            }

            $raw = is_readable($argument) ? file_get_contents($argument) : false;
            $document = is_string($raw) ? json_decode($raw, true) : null;

            if (!is_array($document)) {
                return new WP_Error(
                    'folderfolio_import_file_unreadable',
                    __('This file is not an export — it could not be read as JSON.', 'folderfolio')
                );
            }

            $source = JsonSource::fromDocument($document);

            if ($source instanceof WP_Error) {
                return $source;
            }

            if (!JsonSource::store($document)) {
                return new WP_Error('folderfolio_import_file_too_large', JsonSource::refusal($document));
            }

            return $source;
        }

        $source = self::find($argument);

        if (null === $source) {
            return new WP_Error(
                'folderfolio_import_unknown_source',
                __('That plugin is not one FolderFolio can import from.', 'folderfolio')
            );
        }

        if (!$source->hasData()) {
            return new WP_Error(
                'folderfolio_import_no_data',
                sprintf(
                    /* translators: %s is a plugin name, e.g. FileBird. */
                    __('There is no %s data on this site to import.', 'folderfolio'),
                    $source->label()
                )
            );
        }

        return $source;
    }

    /**
     * What the wizard's first step lists.
     *
     * Every source, including the ones with nothing in them: "Real Media
     * Library — no data found" is information, and a list that silently omits
     * the plugin somebody is looking for reads as a plugin that cannot import
     * it.
     *
     * @return list<array{
     *     key: string,
     *     label: string,
     *     has_data: bool,
     *     plugin_active: bool,
     *     folders: int,
     *     assignments: int,
     *     imported_at: string|null
     * }>
     */
    public static function detect(): array
    {
        $detected = [];

        foreach (self::all() as $source) {
            $hasData = $source->hasData();

            $detected[] = [
                'key' => $source->key(),
                'label' => $source->label(),
                'has_data' => $hasData,
                'plugin_active' => $source->isPluginActive(),
                // Counting an empty source is a wasted query on every load of
                // the wizard, and there are nine of them.
                'folders' => $hasData ? $source->folderCount() : 0,
                'assignments' => $hasData ? $source->assignmentCount() : 0,
                'imported_at' => null,
            ];
        }

        return $detected;
    }
}
