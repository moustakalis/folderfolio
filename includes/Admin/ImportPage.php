<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Support\ClientConfig;
use FolderFolio\Support\Plurals;

/**
 * The Import tab of the settings screen — screen 07's four-step wizard.
 *
 * All this class does now is print a mount node and enqueue the app. Screen 07
 * is four states driven by what the server says about a run, so it is a React
 * app, where the Settings and Status tabs beside it are plain PHP forms — the
 * difference being that a form is posted once and a wizard watches something
 * happen.
 *
 * What was here before was v0.2.0's: a jQuery table that called
 * `POST /import/{key}`, which detected, planned and wrote in one unreviewable
 * request, reported success through `window.alert()` and reloaded the page.
 * That route is gone as well as that table. Running CatFolders' equivalent
 * over a hand-built tree — no confirmation, no preview, no progress, no undo —
 * created five duplicate top-level folders and emptied three of ours, which is
 * the whole reason this screen has four steps rather than one button.
 */
class ImportPage
{
    public function register(): void
    {
        // No admin_enqueue_scripts hook of its own, and no hook suffix to
        // remember. SettingsPage is the screen now: it knows which tab is
        // showing, and these assets have nothing to do on the other two.
    }

    /**
     * The wizard's bundle, its dependencies and the labels it reads.
     *
     * Called by SettingsPage only when the Import tab is the one being drawn.
     */
    public function enqueueTabAssets(): void
    {
        $manifest = FOLDERFOLIO_PLUGIN_DIR . 'assets/build/apps/import.asset.php';

        if (!file_exists($manifest)) {
            return;
        }

        /** @var array{dependencies: list<string>, version: string} $asset */
        $asset = require $manifest;

        wp_enqueue_script(
            'folderfolio-import-app',
            FOLDERFOLIO_PLUGIN_URL . 'assets/build/apps/import.js',
            array_merge($asset['dependencies'], ['wp-api-fetch']),
            $asset['version'],
            ['in_footer' => true, 'strategy' => 'defer']
        );

        wp_add_inline_script(
            'folderfolio-import-app',
            ClientConfig::script($this->config()),
            'before'
        );
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
            <h1><?php echo esc_html__('Import folders', 'folderfolio'); ?></h1>
            <?php $this->renderTab(); ?>
        </div>
        <?php
    }

    /**
     * The mount node, and what stands in its place with scripts off.
     *
     * The fallback is a sentence rather than a form. Every other control this
     * plugin prints degrades to something that works without JavaScript,
     * because every other control is one request; an import is a sequence of
     * them with a preview in the middle, and a no-script version would be a
     * second implementation of the one screen where being wrong is expensive.
     */
    public function renderTab(): void
    {
        ?>
        <div id="folderfolio-import-app" class="folderfolio">
            <noscript>
                <p class="folderfolio-wizard__lede">
                    <?php esc_html_e('The import wizard needs JavaScript. Switch it on in your browser, or import from the command line with wp folderfolio import.', 'folderfolio'); ?>
                </p>
            </noscript>
        </div>
        <?php
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'restUrl' => esc_url_raw(rest_url('folderfolio/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'version' => FOLDERFOLIO_VERSION,
            'uploadUrl' => esc_url_raw(admin_url('upload.php')),
            // For the report's last line, which offers to retire the plugin
            // the import just read from.
            'pluginsUrl' => esc_url_raw(admin_url('plugins.php')),
            'i18n' => [
                /* translators: %s is the plugin the import read from. */
                'importRetire' => __(
                    '%s is still switched on. Its folders are here now, and nothing in FolderFolio needs it running — so you can deactivate it whenever you like.',
                    'folderfolio'
                ),
                'importRetireLink' => __('Open Plugins', 'folderfolio'),
                // Step labels.
                'importStepDetect' => __('Choose a source', 'folderfolio'),
                'importStepPreview' => __('Preview', 'folderfolio'),
                'importStepRun' => __('Import', 'folderfolio'),
                'importStepReport' => __('Finished', 'folderfolio'),

                // Step 1.
                'importTitle' => __('Import from another plugin', 'folderfolio'),
                'importLede' => __('FolderFolio reads the data these plugins left behind. Nothing is written until you have read the preview, and nothing is ever removed from a folder you made.', 'folderfolio'),
                'importDetecting' => __('Looking for folders to import…', 'folderfolio'),
                'importNothing' => __('No folder data from another plugin was found on this site.', 'folderfolio'),
                'importPreview' => __('Preview import', 'folderfolio'),
                'importNoData' => __('No data found', 'folderfolio'),

                // An export file, read back in — tier 1 item 6b.
                'importFileName' => __('FolderFolio export file', 'folderfolio'),
                'importFileDetail' => __('A .json file saved with Export folder structure — from this site, or from another one.', 'folderfolio'),
                'importFileChoose' => __('Choose file…', 'folderfolio'),
                'importFileReading' => __('Reading the file…', 'folderfolio'),
                'importFileNotJson' => __('This file is not an export — it could not be read as JSON.', 'folderfolio'),
                'importFileSame' => __('An export of this site', 'folderfolio'),
                'importFileSameDetail' => __('Colours, galleries and orders come with the folders it creates; folders that already exist keep their own.', 'folderfolio'),
                'importFileOther' => __('An export of another site', 'folderfolio'),
                /* translators: 1: number of file assignments. 2: the other site's address. */
                'importFileOtherFile' => Plurals::forms(_n_noop(
                    'Only the folders are imported. Its %1$s file assignment names a file on %2$s by number, and that number is a different file here.',
                    'Only the folders are imported. Its %1$s file assignments name files on %2$s by number, and those numbers are different files here.',
                    'folderfolio'
                )),
                'importFileOtherNone' => __('Only the folders are imported — it carries no file assignments. Colours, galleries and orders come with the folders it creates.', 'folderfolio'),
                'actionFailed' => __('That could not be done.', 'folderfolio'),
                'importNothingToImport' => __('Nothing to import', 'folderfolio'),
                'importActive' => __('plugin active', 'folderfolio'),
                'importInactive' => __('plugin deactivated, data still present', 'folderfolio'),
                'importTwoQuestions' => __('Plugins you have deactivated are listed too: their folders stay in the database after they are switched off, and that is what FolderFolio reads.', 'folderfolio'),
                /* translators: %s is a number of folders. */
                'importFolderOne' => Plurals::forms(_n_noop('%s folder', '%s folders', 'folderfolio')),
                /* translators: %s is a number of file assignments. */
                'importFileOne' => Plurals::forms(_n_noop('%s file assignment', '%s file assignments', 'folderfolio')),

                // Step 2.
                /* translators: %s is a plugin name, e.g. FileBird. */
                'importPreviewTitle' => __('Preview — %s', 'folderfolio'),
                'importNothingWritten' => __('Nothing has been written yet', 'folderfolio'),
                'importPreviewLede' => __('This is what pressing Import would do.', 'folderfolio'),
                'importPlanning' => __('Reading the folders…', 'folderfolio'),
                /* translators: %s is a number of folders. */
                'importCreateOne' => Plurals::forms(_n_noop('%s folder created', '%s folders created', 'folderfolio')),
                /* translators: %s is a number of folders. */
                'importMergeOne' => Plurals::forms(_n_noop(
                    '%s folder merged by name',
                    '%s folders merged by name',
                    'folderfolio'
                )),
                /* translators: %s is a list of folder names. */
                'importMergeDetail' => __('%s already exist — files are added to yours, not duplicated.', 'folderfolio'),
                /* translators: %s is a number of duplicate folder names. */
                'importDupOne' => Plurals::forms(_n_noop(
                    '%s duplicate name collapsed',
                    '%s duplicate names collapsed',
                    'folderfolio'
                )),
                /* translators: 1: a plugin name. 2: a list of folder paths. */
                'importDupDetail' => __('%1$s has more than one folder called %2$s in the same place. They become one folder here, holding both sets of files.', 'folderfolio'),
                /* translators: %s is a number of folders. */
                'importAgainOne' => Plurals::forms(_n_noop(
                    '%s folder already imported',
                    '%s folders already imported',
                    'folderfolio'
                )),
                'importAgainDetail' => __('A previous import made these. They are matched by where they came from, so renaming one did not break the link.', 'folderfolio'),
                /* translators: %s is a number of files. */
                'importFilesOne' => Plurals::forms(_n_noop(
                    '%s file added to folders',
                    '%s files added to folders',
                    'folderfolio'
                )),
                'importFilesDetail' => __('Added, never moved: nothing leaves a folder you made.', 'folderfolio'),
                /* translators: %s is a number of files already in the destination folder. */
                'importFilesDetailAlready' => __('Added, never moved: nothing leaves a folder you made. %s are already filed where this would put them.', 'folderfolio'),
                /* translators: %s is a number of files. */
                'importSkipOne' => Plurals::forms(_n_noop('%s file skipped', '%s files skipped', 'folderfolio')),
                /* translators: %s is a list of attachment ids. */
                'importSkipDetail' => __('Attachments %s are referenced but no longer exist in the media library.', 'folderfolio'),
                /* translators: %s is a number of folders. */
                'importStrandedOne' => Plurals::forms(_n_noop(
                    '%s folder could not be placed',
                    '%s folders could not be placed',
                    'folderfolio'
                )),
                /* translators: 1: a plugin name. 2: a list of folder names. */
                'importStrandedDetail' => __('Their parent is missing in %1$s, or they sit in a loop. %2$s', 'folderfolio'),
                /* translators: %s is a number of folders. */
                'importRunOne' => Plurals::forms(_n_noop('Import %s folder', 'Import %s folders', 'folderfolio')),
                'importRunFiles' => __('Import', 'folderfolio'),
                'importCancel' => __('Cancel', 'folderfolio'),
                'importNoAccount' => __('No account, no licence, no telemetry.', 'folderfolio'),

                // Step 3.
                'importRunning' => __('Importing', 'folderfolio'),
                'importLeaveLede' => __('You can leave this page — the import continues and picks up where it left off.', 'folderfolio'),
                'importProgressLabel' => __('Import progress', 'folderfolio'),
                /* translators: 1: folders done. 2: folders in total. */
                'importProgressOf' => __('Folders — %1$s of %2$s', 'folderfolio'),
                /* translators: %s is a number of folders. */
                'importCreatedOne' => Plurals::forms(_n_noop('%s folder created', '%s folders created', 'folderfolio')),
                /* translators: %s is a number of folders. */
                'importMergedOne' => Plurals::forms(_n_noop(
                    '%s folder merged into an existing name',
                    '%s folders merged into existing names',
                    'folderfolio'
                )),
                /* translators: %s is a number of files. */
                'importAddedOne' => Plurals::forms(_n_noop('%s file added', '%s files added', 'folderfolio')),
                /* translators: %s is a number of duplicate folder names. */
                'importDupCollapsedOne' => Plurals::forms(_n_noop(
                    '%s duplicate name collapsed',
                    '%s duplicate names collapsed',
                    'folderfolio'
                )),
                'importStop' => __('Stop after this folder', 'folderfolio'),
                'importStopping' => __('Stopping…', 'folderfolio'),

                // Step 4.
                'importDoneTitle' => __('Import finished', 'folderfolio'),
                /* translators: %s is a list such as "26 folders created, 36 files added". */
                'importDoneSentence' => __('%s. No file left a folder you had already made.', 'folderfolio'),
                'importDoneNothing' => __('Nothing needed importing — everything was already here.', 'folderfolio'),
                /* translators: %s is a number of folders. */
                'importDoneCreatedOne' => Plurals::forms(_n_noop(
                    '%s folder created',
                    '%s folders created',
                    'folderfolio'
                )),
                /* translators: %s is a number of folders. */
                'importDoneMergedOne' => Plurals::forms(_n_noop(
                    '%s merged by name',
                    '%s merged by name',
                    'folderfolio'
                )),
                /* translators: %s is a number of files. */
                'importDoneAddedOne' => Plurals::forms(_n_noop('%s file added', '%s files added', 'folderfolio')),
                /* translators: 1: a plugin name. 2: a number of duplicate names. */
                'importDupNote' => __('%1$s had %2$s duplicate folder names in the same place. Each set became one folder here, holding all of their files.', 'folderfolio'),
                'importUndoneTitle' => __('Import undone', 'folderfolio'),
                'importUndoneLede' => __('The folders this import created are gone, and so are the files it filed. Anything you had already made was left alone.', 'folderfolio'),
                /* translators: 1: a list of attachment ids. 2: a plugin name. */
                'importSkippedIds' => __('Attachments %1$s are referenced by %2$s but no longer exist in the media library.', 'folderfolio'),
                /* translators: %s is a list of folder names. */
                'importStrandedNames' => __('Their parent is missing, or they sit in a loop: %s. They were brought over at the top level rather than dropped.', 'folderfolio'),
                'importOpenLibrary' => __('Open Media Library', 'folderfolio'),
                'importUndo' => __('Undo this import', 'folderfolio'),
                'importBack' => __('Back to the sources', 'folderfolio'),
                'importUndoNote' => __('Undo removes the folders this import created and the files it filed. A folder you have added your own files to since is kept.', 'folderfolio'),
                'importProvenanceNote' => __('Every folder records where it came from, so a second run reconciles instead of duplicating.', 'folderfolio'),
                'importFailed' => __('The import could not continue.', 'folderfolio'),

                // Export. Shipped on 22 Sep reading from t()'s English
                // fallbacks, which is how a string stays untranslatable
                // without anything looking broken.
                'exportEyebrow' => __('Take it with you', 'folderfolio'),
                'exportLede' => __('Download the media library’s folders as a file — names, nesting, colours, galleries and the order everything sits in. Keep it before a big reorganisation, move it to another site, or just have it.', 'folderfolio'),
                'exportWithAssignments' => __('Include which files are in which folder', 'folderfolio'),
                'exportWithAssignmentsHint' => __('Makes a much larger file on a big library.', 'folderfolio'),
                'exportButton' => __('Export folder structure', 'folderfolio'),
                'exportBusy' => __('Preparing…', 'folderfolio'),
                'exportFailed' => __('The export could not be produced.', 'folderfolio'),

                // Bulk create.
                'bulkEyebrow' => __('Many at once', 'folderfolio'),
                'bulkLede' => __('Paste a list and get the folders, in the media library. One per line, and a slash nests — Brand/Logos makes both. Nothing is created until you have seen what would be.', 'folderfolio'),
                'bulkLabel' => __('Folders, one per line', 'folderfolio'),
                'bulkPreview' => __('Preview', 'folderfolio'),
                'bulkPreviewing' => __('Reading…', 'folderfolio'),
                'bulkCreating' => __('Creating…', 'folderfolio'),
                /* translators: %s is a number of folders. */
                'bulkCreateOne' => Plurals::forms(_n_noop('Create %s folder', 'Create %s folders', 'folderfolio')),
                /* translators: %s is a number of folders. */
                'bulkFolderOne' => Plurals::forms(_n_noop('%s folder', '%s folders', 'folderfolio')),
                /* translators: %s is a number of lines that could not be used. */
                'bulkPlanErrorsOne' => Plurals::forms(_n_noop(
                    'Nothing has been created. %s line cannot be used — it is marked below.',
                    'Nothing has been created. %s of these lines cannot be used — they are marked below.',
                    'folderfolio'
                )),
                'bulkPlanNothing' => __('Every folder on this list is already there. Nothing to create.', 'folderfolio'),
                /* translators: 1: a phrase such as "12 folders". 2: a number of lines already present. */
                'bulkPlanSomeOne' => Plurals::forms(_n_noop(
                    'Nothing has been created yet. This would add %1$s; %2$s line is already there.',
                    'Nothing has been created yet. This would add %1$s; %2$s lines are already there.',
                    'folderfolio'
                )),
                /* translators: %s is a phrase such as "12 folders". */
                'bulkPlanAll' => __('Nothing has been created yet. This would add %s.', 'folderfolio'),
                'bulkRunNothing' => __('Every folder on this list was already there. Nothing was created.', 'folderfolio'),
                /* translators: %s is a number of folders. */
                'bulkRunOne' => Plurals::forms(_n_noop('%s folder created.', '%s folders created.', 'folderfolio')),
                'bulkRowNew' => __('new', 'folderfolio'),
                'bulkRowCreated' => __('created', 'folderfolio'),
                'bulkRowExists' => __('already there', 'folderfolio'),
                'bulkPreviewFailed' => __('The list could not be read.', 'folderfolio'),
                'bulkCreateFailed' => __('The folders could not be created.', 'folderfolio'),
            ],
        ];
    }
}
