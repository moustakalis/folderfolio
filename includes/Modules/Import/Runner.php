<?php

declare(strict_types=1);

namespace FolderFolio\Modules\Import;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Domain\AttachmentFolderRepository;
use FolderFolio\Domain\FolderRepository;
use FolderFolio\Domain\FolderService;
use FolderFolio\Domain\FolderKinds;
use FolderFolio\Domain\FolderSorts;
use WP_Error;

/**
 * Runs an import, a batch of folders at a time.
 *
 * ## Add, never move
 *
 * The one rule the whole module is built around. Our assignment table permits
 * a file in many folders and, unlike every competitor examined, so does the
 * code — `assign()` is an insert, not a delete-then-insert. So an import never
 * has to take a file out of a folder the user made, and the entire failure
 * class that CatFolders' importer demonstrated live (three folders emptied,
 * Uncategorized from 14 files to 5) simply cannot happen here.
 *
 * ## Batched, and resumable because the cursor is on the server
 *
 * The cursor is an index into the source's folders, in the order SourceTree
 * puts them: parents before children. Each call to `step()` does one batch and
 * saves. Closing the tab loses nothing, and the same call resumes it — which
 * is what lets screen 07 say "you can leave this page".
 *
 * A batch is folders, not files, because a folder is the unit that has to
 * complete: a half-created folder is a folder that does not exist, but a
 * folder with half its files is a folder with half its files, and the next
 * batch adds the rest. Assignment is idempotent, so a batch that runs twice
 * after a timeout costs a few queries and changes nothing.
 *
 * ## Provenance is written as each folder lands
 *
 * Not at the end. A run that dies in the middle has still recorded where every
 * folder it made came from, so re-running reconciles those instead of
 * duplicating them — which is the case a per-source "already imported" flag
 * gets exactly wrong.
 */
final class Runner
{
    /**
     * Folders per call.
     *
     * Deliberately small. The slow part is one query per folder for its
     * attachment ids plus one insert per file, and the thing being protected
     * is the request timeout on shared hosting, which is where a media library
     * big enough to need this lives.
     */
    public const BATCH = 25;

    /**
     * Folders per call when the source is an export file — and a time limit
     * on them.
     *
     * A plugin source re-reads its tree with one query. A file source
     * re-reads, decodes and re-validates the whole document every batch:
     * measured on 23 Sep at the 20,000-folder ceiling, that was ~200ms of
     * every ~380ms batch — 160s of a 307s run spent reading the same file 800
     * times. Four times the folders is a quarter of the re-reads.
     *
     * Four times the folders could also be four times the files, and the
     * request timeout is still the thing being protected. So a file batch
     * also stops once it has spent FILE_BATCH_SECONDS, whatever its count; the
     * next call carries on from the cursor like any other. Filterable
     * (`folderfolio_import_file_batch_seconds`) for a host with a shorter
     * timeout than most.
     */
    public const FILE_BATCH = 100;

    public const FILE_BATCH_SECONDS = 5.0;

    /**
     * How many skipped attachment ids the run record keeps.
     *
     * The report names them — "attachments 118 and 204 no longer exist" is
     * actionable where a bare count is not — but it names a handful, and the
     * run lives in an option.
     */
    public const SKIPPED_KEPT = 100;

    /**
     * How many unmoved steps `toEnd()` waits through — half a second each,
     * two minutes in all — before it calls the run stalled.
     */
    private const PATIENCE = 240;

    public function __construct(
        private readonly RunStore $store = new RunStore(),
        private readonly FolderService $service = new FolderService(),
        private readonly FolderRepository $folders = new FolderRepository(),
        private readonly AttachmentFolderRepository $assignments = new AttachmentFolderRepository(),
        private readonly Provenance $provenance = new Provenance(),
        private readonly FolderSorts $sorts = new FolderSorts()
    ) {
    }

    /**
     * Begin a run, replacing any finished one.
     *
     * @return Run|WP_Error
     */
    public function start(Source $source): Run|WP_Error
    {
        $current = $this->store->current();

        if (null !== $current && !$current->isFinished()) {
            return new WP_Error(
                'folderfolio_import_in_progress',
                __('An import is already running. Wait for it to finish, or stop it first.', 'folderfolio'),
                ['status' => 409]
            );
        }

        $tree = SourceTree::of($source->folders());

        // A stop asked of a run that is over means nothing to the next one.
        $this->store->clearStop();

        $run = new Run(
            // Bounded, because it is written into `import_run VARCHAR(32)` on
            // every row the run files and undo matches on it exactly: a key
            // long enough to be truncated by the column would be a marker that
            // silently matches nothing. Ten digits of timestamp, a hyphen, and
            // at most twenty of the key — the longest shipped key is
            // 'wp-media-library-folders', and a site can filter in anything.
            id: time() . '-' . substr($source->key(), 0, 20),
            sourceKey: $source->key(),
            sourceLabel: $source->label(),
            status: Run::RUNNING,
            total: count($tree->entries),
            unreachable: $tree->unreachable,
            // To the second, and never rewritten: undo finds this run's
            // assignments by the window between these two timestamps.
            startedAt: current_time('mysql', true)
        );

        $this->store->save($run);

        return $run;
    }

    /**
     * Do one batch and save. Call it until the run reports it is finished.
     *
     * @return Run|WP_Error
     */
    public function step(): Run|WP_Error
    {
        // One batch at a time — review M5. Two tabs, or a tab and the CLI,
        // each read the run record, did a batch and wrote the whole record
        // back, so each overwrote the other's progress and the ids of the
        // folders it had made. A named database lock, held for the batch: it
        // goes with the connection if the request dies, so there is nothing
        // to go stale. A step that cannot have it changes nothing and says
        // where the run is.
        if (!$this->lock()) {
            $run = $this->store->current();

            return $run ?? new WP_Error(
                'folderfolio_import_not_started',
                __('There is no import to continue.', 'folderfolio'),
                ['status' => 404]
            );
        }

        try {
            return $this->batch();
        } finally {
            $this->unlock();
        }
    }

    /**
     * @return Run|WP_Error
     */
    private function batch(): Run|WP_Error
    {
        // Read inside the lock, so it is the record the last batch saved.
        $run = $this->store->current();

        if (null === $run) {
            return new WP_Error(
                'folderfolio_import_not_started',
                __('There is no import to continue.', 'folderfolio'),
                ['status' => 404]
            );
        }

        if ($run->isFinished()) {
            return $run;
        }

        $source = Catalog::find($run->sourceKey);

        if (null === $source) {
            $run->status = Run::DONE;
            $run->error = __('The plugin this import was reading from is no longer available.', 'folderfolio');
            $run->finishedAt = current_time('mysql', true);
            $this->store->save($run);

            do_action('folderfolio_import_finished', $run->toArray());

            return $run;
        }

        // Stop is its own option, which only Stop writes (M5).
        if (Run::STOPPING === $run->status || $this->store->stopRequested($run->id)) {
            return $this->finish($run);
        }

        $entries = SourceTree::of($source->folders())->entries;

        // The source is re-read every batch rather than cached in the run.
        // It is one query, the tree cannot be large, and the alternative —
        // serialising the whole tree into an option — is a copy that goes
        // stale the moment somebody adds a folder in the old plugin mid-run.
        $targets = $this->targetsSoFar($run, $source->key());

        // Carry on after the folder the last batch ended on, found by its id
        // (review L5). The cursor is a position in a list rebuilt every batch,
        // so a folder deleted in the old plugin before it shifted every later
        // folder back one, and the one now at the cursor was never imported.
        // When that folder is itself gone, one step back: redoing a folder is
        // idempotent, skipping one is not.
        $start = $this->resume($run, $entries);

        $isFile = $source instanceof JsonSource;
        $end = min($start + ($isFile ? self::FILE_BATCH : self::BATCH), count($entries));
        $began = microtime(true);
        $budget = $isFile
            ? (float) apply_filters('folderfolio_import_file_batch_seconds', self::FILE_BATCH_SECONDS)
            : INF;

        for ($i = $start; $i < $end; ++$i) {
            // Checked before a folder, never inside one: at least one folder
            // lands per call, so a slow site still gets through.
            if ($i > $start && microtime(true) - $began > $budget) {
                break;
            }

            // Stop takes effect before the next folder, not only between
            // batches: "Stop after this file" should not mean 24 more.
            if ($i > $start && $this->store->stopRequested($run->id)) {
                break;
            }

            $entry = $entries[$i] ?? null;

            if (null === $entry) {
                break;
            }

            $result = $this->importFolder($run, $source, $entry['folder'], $targets);

            if (is_wp_error($result)) {
                // One folder, not the import (review M8): it is named in the
                // report with its reason, and the run goes on. Its subfolders
                // land at the top level, which keeps their files.
                ++$run->foldersSkipped;
                $run->warn(sprintf(
                    /* translators: 1: folder name in the plugin being imported from, 2: the reason. */
                    __('“%1$s” was not imported: %2$s', 'folderfolio'),
                    $entry['folder']->folderName(),
                    $result->get_error_message()
                ));
            } else {
                $targets[$entry['folder']->id] = $result;
            }

            $run->cursor = $i + 1;
            $run->lastSourceId = $entry['folder']->id;
        }

        if ($run->cursor >= count($entries) || $this->store->stopRequested($run->id)) {
            return $this->finish($run);
        }

        $this->store->save($run);

        return $run;
    }

    /**
     * Where this batch starts, given the list as it is now.
     *
     * @param list<array{folder: SourceFolder, trail: list<string>}> $entries
     */
    private function resume(Run $run, array $entries): int
    {
        if (null === $run->lastSourceId || 0 === $run->cursor) {
            return $run->cursor;
        }

        foreach ($entries as $index => $entry) {
            if ($entry['folder']->id === $run->lastSourceId) {
                return $index + 1;
            }
        }

        return max(0, min($run->cursor, count($entries)) - 1);
    }

    /**
     * The per-site name of the one-batch-at-a-time lock. MySQL allows 64
     * characters; the prefix is the site's table prefix.
     */
    private function lockName(): string
    {
        global $wpdb;

        return substr('folderfolio_import_' . $wpdb->prefix, 0, 64);
    }

    private function lock(): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a named lock, not a data read; nothing to cache.
        return '1' === (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $this->lockName()));
    }

    private function unlock(): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releasing a named lock, not a data read; nothing to cache.
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->lockName()));
    }

    /**
     * Ask for the run to stop after the batch in flight.
     *
     * Not a kill: screen 07's button says "Stop after this file", because a
     * run torn down mid-folder is the only way this module could leave a
     * half-made tree.
     *
     * @return Run|WP_Error
     */
    /**
     * Carry the current run to its end — the command line's loop, where the
     * wizard polls `step()` from the browser.
     *
     * `$tick` is told after every batch, for a progress bar. A batch always
     * lands at least one folder, so this ends; the guard is for a store that
     * stops saving, which would otherwise hand back the same cursor for ever.
     *
     * @param (callable(Run): void)|null $tick
     */
    public function toEnd(?callable $tick = null): Run|WP_Error
    {
        $still = 0;
        $last = -1;

        while (true) {
            $run = $this->step();

            if ($run instanceof WP_Error || $run->isFinished()) {
                return $run;
            }

            if (null !== $tick) {
                $tick($run);
            }

            $still = $run->cursor === $last ? $still + 1 : 0;
            $last = $run->cursor;

            // A cursor that did not move is usually another request's batch
            // holding the lock (a tab left open). Wait for it rather than
            // calling that a stall: half a second at a time, for as long as
            // one batch may reasonably take.
            if ($still > 0 && $still < self::PATIENCE) {
                usleep(500000);

                continue;
            }

            if ($still >= self::PATIENCE) {
                return new WP_Error(
                    'folderfolio_import_stalled',
                    __('The import stopped moving forward. Run it again to continue from where it stopped.', 'folderfolio')
                );
            }
        }
    }

    public function stop(): Run|WP_Error
    {
        $run = $this->store->current();

        if (null === $run) {
            return new WP_Error('folderfolio_import_not_started', __('There is no import to stop.', 'folderfolio'), ['status' => 404]);
        }

        if (!$run->isFinished()) {
            // Not by writing the record, which the batch in flight would
            // overwrite (M5): the batch reads this and stops.
            $this->store->requestStop($run->id);
            $run->status = Run::STOPPING;
        }

        return $run;
    }

    /**
     * Undo a finished run.
     *
     * Two halves, in this order:
     *
     * 1. Assignments this run made to folders that already existed. Found by
     *    `assigned_at` inside the run's window rather than from a stored list,
     *    which is what keeps a hundred thousand of them out of an option.
     * 2. The folders it created, deepest first so a parent is never removed
     *    before its children.
     *
     * A folder the user has since put their own files in is **kept**. Undoing
     * an import is not a licence to delete work that was done afterwards, and
     * a folder that is no longer empty is evidence of exactly that.
     *
     * @return Run|WP_Error
     */
    public function undo(): Run|WP_Error
    {
        $run = $this->store->current();

        if (null === $run || Run::DONE !== $run->status) {
            return new WP_Error(
                'folderfolio_import_nothing_to_undo',
                __('There is no finished import to undo.', 'folderfolio'),
                ['status' => 404]
            );
        }

        // Every row this run filed carries its id; nothing else does. One
        // indexed delete, and a file somebody filed by hand — before the run,
        // or in the middle of it — is not in scope by construction.
        //
        // A run that finished before DB_VERSION 4 has no marked rows at all,
        // so undoing it now removes folders and leaves files where they are.
        // That is the conservative direction, and the only honest one: the
        // rows it filed are no longer distinguishable from anybody else's.
        $unfiled = $this->assignments->assignedByRun($run->id);
        $this->assignments->deleteAssignedByRun($run->id);

        // The same hook every other unfiling fires (review L6), once per
        // folder the undo took files out of.
        foreach ($unfiled as $folderId => $attachmentIds) {
            do_action('folderfolio_attachments_unassigned', $attachmentIds, $folderId);
        }

        // Deepest first. `delete()` reparents children rather than cascading,
        // so removing a parent first would leave its children at the top level
        // and then fail to find them.
        //
        // The run's own list, and every folder marked as made by this run —
        // the list is saved once per batch, so a batch that was interrupted
        // left folders it had made off it (review M6).
        $created = $this->deepestFirst(array_values(array_unique([
            ...$run->createdFolderIds,
            ...$this->provenance->createdBy($run->id),
        ])));

        $run->warnings = [];

        foreach ($created as $folderId) {
            $name = (string) ($this->folders->find($folderId)['name'] ?? '');

            if ([] !== $this->assignments->attachmentIdsForFolder($folderId)) {
                // Somebody filed something here after the import. Keeping it
                // is the conservative half of an undo — and it is said.
                $run->warn(sprintf(
                    /* translators: %s: folder name. */
                    __('“%s” was kept: files were put in it after the import.', 'folderfolio'),
                    $name
                ));

                continue;
            }

            $deleted = $this->service->delete($folderId, FolderService::CHILDREN_REPARENT);

            if (is_wp_error($deleted)) {
                // A lock put on it since, most often. Kept, and said (L6).
                $run->warn(sprintf(
                    /* translators: 1: folder name, 2: the reason. */
                    __('“%1$s” was kept: %2$s', 'folderfolio'),
                    $name,
                    $deleted->get_error_message()
                ));

                continue;
            }

            $this->provenance->forget($folderId);
        }

        // Provenance goes for every folder the run touched, not only the ones
        // it created. After an undo the honest state is "this import never
        // happened" — leaving the link on a folder that was merged into would
        // mean a later run reconciled into it under whatever name it has by
        // then, which is a surprise nobody signed up for.
        foreach ($run->touchedFolderIds as $folderId) {
            $this->provenance->forget($folderId);
        }

        $run->status = Run::UNDONE;
        $run->finishedAt = current_time('mysql', true);
        $this->store->save($run);

        do_action('folderfolio_import_undone', $run->toArray());

        return $run;
    }

    /**
     * Create or find one folder, file its attachments, record where it came
     * from.
     *
     * @param array<int, int> $targets source folder id => our folder id
     *
     * @return int|WP_Error our folder id
     */
    private function importFolder(Run $run, Source $source, SourceFolder $folder, array $targets): int|WP_Error
    {
        $parentId = null;

        if (null !== $folder->parentId) {
            // SourceTree guarantees the parent came first in this or an
            // earlier batch. If it is missing the folder lands at the top
            // level, which keeps its files rather than losing them.
            $parentId = $targets[$folder->parentId] ?? null;
        }

        $known = $this->provenance->folderIdFor($source->key(), $folder->id);
        $madeHere = false;

        if (null !== $known) {
            $folderId = $known;
        } else {
            $existing = $this->folders->findByName($folder->folderName(), $parentId);

            if (null !== $existing) {
                $folderId = (int) $existing['id'];

                // Merged into something that was here before the run, or
                // collapsed onto a folder this same run just made because the
                // source holds two of that name in one place. The planner
                // counts the two separately and so must this, or the report
                // contradicts the preview the user agreed to.
                if (in_array($folderId, $run->createdFolderIds, true)) {
                    ++$run->duplicatesCollapsed;
                } else {
                    ++$run->foldersMerged;
                }
            } else {
                // Colour and icon only when the source carries them — a
                // FolderFolio export does, no plugin does — and only here, on
                // a folder this run creates. A merged folder is somebody's own
                // and keeps what they gave it.
                $data = [
                    'name' => $folder->folderName(),
                    'parent_id' => $parentId,
                    'sort_order' => $folder->sortOrder,
                ];

                if ($folder->color !== null) {
                    $data['color'] = $folder->color;
                }

                if ($folder->icon !== null) {
                    $data['icon'] = $folder->icon;
                }

                $created = $this->service->create($data);

                if (is_wp_error($created)) {
                    return $created;
                }

                $folderId = $created->id;

                // The two per-folder orders, the same way. An order this
                // version does not know was dropped when the file was read,
                // and a failed write here costs an order, not the folder — so
                // it is not a reason to stop the run.
                foreach (['folders' => $folder->sortFolders, 'files' => $folder->sortFiles] as $scope => $order) {
                    if ($order !== null) {
                        $this->sorts->set($folderId, $scope, $order);
                    }
                }

                // A gallery comes back a gallery, before its files are
                // filed, so the files meet its rule. Like an order, a kind
                // that cannot be written costs the kind and not the folder.
                if ($folder->gallery) {
                    $this->service->setKind($folderId, FolderKinds::GALLERY);
                }
                $run->createdFolderIds[] = $folderId;
                ++$run->foldersCreated;
                $madeHere = true;
            }

            // Marked with this run's id when this run made it (M6).
            $this->provenance->record($folderId, $source->key(), $folder->id, $madeHere ? $run->id : null);
        }

        if (!in_array($folderId, $run->touchedFolderIds, true)) {
            $run->touchedFolderIds[] = $folderId;
        }

        $ids = array_values(array_unique($source->attachmentIdsFor($folder->id)));

        if ([] === $ids) {
            return $folderId;
        }

        // Filtered before assigning, not after, and for two reasons.
        //
        // `FolderService::assignAttachments()` rejects the **whole batch** if
        // one id is not an attachment, so a single file deleted years ago
        // would take every real file in its folder with it — and every source
        // names files like that, which is why screen 07 has a "skipped" line
        // at all.
        //
        // And it counts every id it processes, including ones already filed
        // there. Diffing first is what makes `files_added` in the report the
        // same number the preview promised on a second run, instead of
        // counting the same thirty-six files again.
        $real = Media::existing($ids);
        $missing = array_values(array_diff($ids, $real));

        if ([] !== $missing) {
            $run->skippedTotal += count($missing);

            // Bounded: a source naming five thousand dead ids should not put
            // five thousand integers in an option. The report shows the first
            // few and the count carries the rest.
            $run->skipped = array_values(array_slice(
                array_unique([...$run->skipped, ...$missing]),
                0,
                self::SKIPPED_KEPT
            ));
        }

        $filed = $this->assignments->attachmentIdsForFolder($folderId);
        $new = array_values(array_diff($real, $filed));

        // A gallery takes images, and refuses a whole batch with one file that
        // is not (review L4): the images are filed, the rest stay where they
        // were and are counted, as the preview counted them.
        if ([] !== $new && (new FolderKinds())->isGallery($folderId)) {
            $notImages = FolderKinds::notImages($new);

            if ([] !== $notImages) {
                $new = array_values(array_diff($new, $notImages));
                $run->filesNotImages += count($notImages);
                $run->warn(sprintf(
                    /* translators: 1: number of files, 2: gallery name. */
                    _n(
                        '%1$s file is not an image and was not filed into the gallery “%2$s”.',
                        '%1$s files are not images and were not filed into the gallery “%2$s”.',
                        count($notImages),
                        'folderfolio'
                    ),
                    number_format_i18n(count($notImages)),
                    $folder->folderName()
                ));
            }
        }

        if ([] === $new) {
            return $folderId;
        }

        // Marked with the run id: this is what undo deletes by, and it is the
        // only thing that can tell a row this import made from one somebody
        // filed by hand a second — or ten minutes — earlier.
        $added = $this->service->assignAttachments(
            $folderId,
            $new,
            FolderService::MODE_ADD,
            $run->id
        );

        if (is_wp_error($added)) {
            // Not fatal to the migration: the folder is made, the files stay
            // where they were, and the report says what went wrong — as a
            // warning, not as the run's error, which says it stopped (L4).
            $run->warn(sprintf(
                /* translators: 1: folder name, 2: the reason. */
                __('Files for “%1$s” were not filed: %2$s', 'folderfolio'),
                $folder->folderName(),
                $added->get_error_message()
            ));

            return $folderId;
        }

        $run->filesAdded += $added;

        return $folderId;
    }

    /**
     * Rebuild the source-id → our-id map for folders already done.
     *
     * Read from provenance rather than carried in the run record: provenance
     * is written as each folder lands, so it is correct after a crash, after a
     * resume in a different request, and after a second run.
     *
     * @return array<int, int>
     */
    private function targetsSoFar(Run $run, string $sourceKey): array
    {
        if (0 === $run->cursor) {
            return [];
        }

        return $this->provenance->mapFor($sourceKey);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function deepestFirst(array $ids): array
    {
        $withDepth = [];

        foreach ($ids as $id) {
            $row = $this->folders->find($id);

            if (null === $row) {
                continue;
            }

            $withDepth[] = ['id' => $id, 'depth' => (int) ($row['depth'] ?? 0)];
        }

        usort($withDepth, static fn (array $a, array $b): int => $b['depth'] <=> $a['depth']);

        return array_values(array_column($withDepth, 'id'));
    }

    private function finish(Run $run): Run
    {
        $this->store->clearStop();
        $run->status = Run::DONE;
        $run->finishedAt = current_time('mysql', true);

        $this->store->save($run);

        // An uploaded export file is not kept once nothing reads it.
        JsonSource::forget($run->sourceKey);

        // The run as the REST route and `import status` report it — an array,
        // because `Run` is not part of the public API. Once per run: a
        // finished run is handed back by step() without coming here again.
        do_action('folderfolio_import_finished', $run->toArray());

        return $run;
    }
}
