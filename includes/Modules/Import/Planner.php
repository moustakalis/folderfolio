<?php

declare(strict_types=1);

namespace FolderFolio\Modules\Import;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Domain\AttachmentFolderRepository;
use FolderFolio\Domain\FolderRepository;

/**
 * Works out what an import would do, and writes nothing.
 *
 * Read-only is not a nicety here: the preview is the screen that earns the
 * user's consent, and a preview with side effects would make "nothing has been
 * written yet" a lie in the one place it matters most.
 *
 * ## Four outcomes per folder, not two
 *
 * - **reconcile** — a previous run of this source already made it. Matched on
 *   provenance, so it holds even if the user renamed the folder afterwards.
 * - **merge** — a folder of that name already exists in that place. Files are
 *   added to it, and nothing is taken out of it.
 * - **duplicate** — the *source* has two folders of the same name in the same
 *   place. They become one here.
 * - **create** — none of the above. A new folder.
 *
 * The third was not in the design and was not predicted. It appeared the first
 * time a preview was compared against the run that followed it: 35 folders
 * previewed as created, and the run reported 26 created and 9 merged. Nothing
 * was wrong with the run — the source really did hold nine same-named siblings
 * — but a preview that says 35 and delivers 26 is the exact failure a preview
 * exists to prevent, so the planner models the collision the run will hit.
 *
 * ## Why not `getOrCreateByPath()`
 *
 * Because it splits on `/`, and a source folder is perfectly entitled to be
 * called "Q1/Q2". Routing an imported name through a path string would turn
 * one folder into two, with no way to tell afterwards. The walk below goes
 * parent by parent through `findByName()`; paths appear only in the preview,
 * as presentation.
 *
 * ## Cost
 *
 * One query per source folder for its attachment ids, one per existing target
 * folder for what is filed there already, and one per 500 attachment ids to
 * check they still exist (`Media`, shared with the runner so the two cannot
 * disagree about what is importable). Folder counts are in the hundreds even on large
 * sites, and this runs when a human presses Preview: being exact is worth more
 * than the queries, because every number here is one somebody is about to make
 * a decision on.
 */
final class Planner
{
    public function __construct(
        private readonly FolderRepository $folders = new FolderRepository(),
        private readonly AttachmentFolderRepository $assignments = new AttachmentFolderRepository(),
        private readonly Provenance $provenance = new Provenance()
    ) {
    }

    public function plan(Source $source): Plan
    {
        $tree = SourceTree::of($source->folders());
        $known = $this->provenance->mapFor($source->key());

        /** @var array<int, int|null> $target source folder id => our folder id, null when it would be created */
        $target = [];

        /**
         * A stable name for the folder each source folder ends up being.
         *
         * `db:12` for one that exists, `new:3` for one this import would make.
         * Two source folders that land in the same place share an identity,
         * which is what lets their files be counted once rather than twice.
         *
         * @var array<int, string> $identity
         */
        $identity = [];

        /** @var array<string, array{identity: string, target: int|null}> $placed parent identity + name */
        $placed = [];

        $create = [];
        $merge = [];
        $reconcile = [];
        $duplicate = [];

        /** @var array<string, array{target: int|null, ids: array<int, true>}> $work by identity */
        $work = [];

        /** @var array<int, true> $named every attachment id the source mentions */
        $named = [];

        $sequence = 0;

        foreach ($tree->entries as $entry) {
            $folder = $entry['folder'];
            $row = ['source_id' => $folder->id, 'path' => implode(' / ', $entry['trail'])];

            $parentIdentity = null === $folder->parentId
                ? 'root'
                : ($identity[$folder->parentId] ?? 'root');

            // Lowercased because findByName is case-insensitive under every
            // collation WordPress ships with, so "Logos" and "logos" in the
            // same place are one folder here whatever the source thought.
            $key = $parentIdentity . '|' . mb_strtolower($folder->name);

            if (isset($known[$folder->id])) {
                $ours = $known[$folder->id];
                $identity[$folder->id] = 'db:' . $ours;
                $reconcile[] = $row;
            } elseif (isset($placed[$key])) {
                $ours = $placed[$key]['target'];
                $identity[$folder->id] = $placed[$key]['identity'];
                $duplicate[] = $row;
            } else {
                $ours = $this->resolve($folder, $target);
                $identity[$folder->id] = null === $ours ? 'new:' . (++$sequence) : 'db:' . $ours;
                $placed[$key] = ['identity' => $identity[$folder->id], 'target' => $ours];

                if (null === $ours) {
                    $create[] = $row;
                } else {
                    $merge[] = $row;
                }
            }

            $target[$folder->id] = $ours;

            $ids = $source->attachmentIdsFor($folder->id);

            foreach ($ids as $id) {
                $named[$id] = true;
            }

            $slot = $identity[$folder->id];

            if (!isset($work[$slot])) {
                $work[$slot] = ['target' => $ours, 'ids' => []];
            }

            foreach ($ids as $id) {
                $work[$slot]['ids'][$id] = true;
            }
        }

        $existing = array_flip(Media::existing(array_keys($named)));

        $toAdd = 0;
        $already = 0;

        foreach ($work as $item) {
            $ours = $item['target'];

            $filed = null === $ours
                ? []
                : array_flip($this->assignments->attachmentIdsForFolder($ours));

            foreach (array_keys($item['ids']) as $id) {
                if (!isset($existing[$id])) {
                    // Counted once, in `skipped`, however many folders name it.
                    continue;
                }

                if (isset($filed[$id])) {
                    ++$already;

                    continue;
                }

                ++$toAdd;
            }
        }

        $skipped = array_values(array_diff(array_keys($named), array_keys($existing)));
        sort($skipped);

        return new Plan(
            $source->key(),
            $source->label(),
            $create,
            $merge,
            $reconcile,
            $duplicate,
            $toAdd,
            $already,
            $skipped,
            $tree->unreachable
        );
    }

    /**
     * Our folder id for one source folder, or null when it would be created.
     *
     * Only asked for a folder that is not already known and not a same-named
     * sibling of one already placed, so this is purely "does it exist here
     * already".
     *
     * @param array<int, int|null> $target
     */
    private function resolve(SourceFolder $folder, array $target): ?int
    {
        $parentSourceId = $folder->parentId;

        if (null === $parentSourceId) {
            $row = $this->folders->findByName($folder->name, null);

            return null === $row ? null : (int) $row['id'];
        }

        // SourceTree guarantees parents come first. If that ever stops being
        // true, treating the folder as new is the outcome that loses nothing.
        $parentOurs = $target[$parentSourceId] ?? null;

        // A parent that will be created has no children here yet, so neither
        // does this folder.
        if (null === $parentOurs) {
            return null;
        }

        $row = $this->folders->findByName($folder->name, $parentOurs);

        return null === $row ? null : (int) $row['id'];
    }
}
