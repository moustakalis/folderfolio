<?php

declare(strict_types=1);

namespace FolderFolio\Modules\Import;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Domain\AttachmentFolderRepository;
use FolderFolio\Domain\FolderRepository;
use FolderFolio\Domain\FolderService;
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
     * How many skipped attachment ids the run record keeps.
     *
     * The report names them — "attachments 118 and 204 no longer exist" is
     * actionable where a bare count is not — but it names a handful, and the
     * run lives in an option.
     */
    public const SKIPPED_KEPT = 100;

    public function __construct(
        private readonly RunStore $store = new RunStore(),
        private readonly FolderService $service = new FolderService(),
        private readonly FolderRepository $folders = new FolderRepository(),
        private readonly AttachmentFolderRepository $assignments = new AttachmentFolderRepository(),
        private readonly Provenance $provenance = new Provenance()
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

            return $run;
        }

        if (Run::STOPPING === $run->status) {
            return $this->finish($run);
        }

        $entries = SourceTree::of($source->folders())->entries;

        // The source is re-read every batch rather than cached in the run.
        // It is one query, the tree cannot be large, and the alternative —
        // serialising the whole tree into an option — is a copy that goes
        // stale the moment somebody adds a folder in the old plugin mid-run.
        $targets = $this->targetsSoFar($run, $source->key());

        $end = min($run->cursor + self::BATCH, count($entries));

        for ($i = $run->cursor; $i < $end; ++$i) {
            $entry = $entries[$i] ?? null;

            if (null === $entry) {
                break;
            }

            $result = $this->importFolder($run, $source, $entry['folder'], $targets);

            if (is_wp_error($result)) {
                $run->error = $result->get_error_message();
                $run->cursor = $i + 1;
                $this->store->save($run);

                return $this->finish($run);
            }

            $targets[$entry['folder']->id] = $result;
            $run->cursor = $i + 1;
        }

        if ($run->cursor >= count($entries)) {
            return $this->finish($run);
        }

        $this->store->save($run);

        return $run;
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
    public function stop(): Run|WP_Error
    {
        $run = $this->store->current();

        if (null === $run) {
            return new WP_Error('folderfolio_import_not_started', __('There is no import to stop.', 'folderfolio'), ['status' => 404]);
        }

        if (!$run->isFinished()) {
            $run->status = Run::STOPPING;
            $this->store->save($run);
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
        $this->assignments->deleteAssignedByRun($run->id);

        // Deepest first. `delete()` reparents children rather than cascading,
        // so removing a parent first would leave its children at the top level
        // and then fail to find them.
        $created = $this->deepestFirst($run->createdFolderIds);

        foreach ($created as $folderId) {
            if ([] !== $this->assignments->attachmentIdsForFolder($folderId)) {
                // Somebody filed something here after the import. Keeping it
                // is the conservative half of an undo.
                continue;
            }

            $this->provenance->forget($folderId);
            $this->service->delete($folderId, FolderService::CHILDREN_REPARENT);
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

        if (null !== $known) {
            $folderId = $known;
        } else {
            $existing = $this->folders->findByName($folder->name, $parentId);

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
                $created = $this->service->create([
                    'name' => $folder->name,
                    'parent_id' => $parentId,
                    'sort_order' => $folder->sortOrder,
                ]);

                if (is_wp_error($created)) {
                    return $created;
                }

                $folderId = $created->id;
                $run->createdFolderIds[] = $folderId;
                ++$run->foldersCreated;
            }

            $this->provenance->record($folderId, $source->key(), $folder->id);
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
            // where they were, and the report says what went wrong.
            $run->error = $added->get_error_message();

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
        $run->status = Run::DONE;
        $run->finishedAt = current_time('mysql', true);

        $this->store->save($run);

        return $run;
    }
}
