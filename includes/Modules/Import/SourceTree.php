<?php

declare(strict_types=1);

namespace FolderFolio\Modules\Import;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Domain\FolderPath;

/**
 * A source's folders, put in an order that can be walked.
 *
 * Both the planner and the runner need the same three things, and neither can
 * get them from a source that hands back rows in id order:
 *
 * - **Parents before children**, because a folder cannot be created inside one
 *   that does not exist yet.
 * - **The trail of names**, because that is what decides whether a folder
 *   already exists here and it is what the preview shows a human.
 * - **The rows that cannot be walked at all**, separated out rather than
 *   crashing the import: a cycle, a branch deeper than FolderPath::MAX_DEPTH,
 *   and whatever is stranded below either of those. A folder whose parent id
 *   is simply missing is *not* one of these — it is promoted to the top level
 *   and imported, because dropping it would lose its files silently.
 *
 * None of those three is hypothetical. Every source examined lets a folder's
 * parent be deleted without touching its children, and `Doctor` exists in this
 * plugin because the same thing happens here.
 */
final class SourceTree
{
    /**
     * @param list<array{folder: SourceFolder, trail: list<string>}> $entries
     * @param list<array{id: int, name: string, reason: string}>     $unreachable
     */
    private function __construct(
        public readonly array $entries,
        public readonly array $unreachable
    ) {
    }

    /**
     * @param list<SourceFolder> $folders
     */
    public static function of(array $folders): self
    {
        /** @var array<int, SourceFolder> $byId */
        $byId = [];

        foreach ($folders as $folder) {
            $byId[$folder->id] = $folder;
        }

        /** @var array<int, list<SourceFolder>> $children */
        $children = [];
        $roots = [];

        foreach ($folders as $folder) {
            $parent = $folder->parentId;

            // A parent that is not in the set is not a parent. Rather than
            // dropping the folder — which loses files silently — it is
            // promoted to the top level and reported, which is the same choice
            // `Doctor` describes for our own missing parents.
            if (null === $parent || !isset($byId[$parent])) {
                $roots[] = $folder;

                continue;
            }

            $children[$parent][] = $folder;
        }

        $entries = [];
        $unreachable = [];
        $seen = [];

        // Iterative, not recursive: a source with a cycle would otherwise
        // recurse until PHP gave up, and a source 10,000 folders deep would
        // blow the stack on a tree that is merely silly rather than broken.
        $stack = [];

        foreach (array_reverse(self::sorted($roots)) as $root) {
            $stack[] = ['folder' => $root, 'trail' => []];
        }

        while ([] !== $stack) {
            /** @var array{folder: SourceFolder, trail: list<string>} $frame */
            $frame = array_pop($stack);
            $folder = $frame['folder'];

            if (isset($seen[$folder->id])) {
                // Reached twice: the only way that happens is a cycle, since
                // every folder has exactly one parent.
                $unreachable[] = [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'reason' => 'cycle',
                ];

                continue;
            }

            $seen[$folder->id] = true;

            $trail = [...$frame['trail'], $folder->name];

            if (count($trail) > FolderPath::MAX_DEPTH) {
                $unreachable[] = [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'reason' => 'too_deep',
                ];

                continue;
            }

            $entries[] = ['folder' => $folder, 'trail' => $trail];

            foreach (array_reverse(self::sorted($children[$folder->id] ?? [])) as $child) {
                $stack[] = ['folder' => $child, 'trail' => $trail];
            }
        }

        // Anything never reached has an unplaceable ancestor. Two ways that
        // happens: a cycle with no root at all — A's parent is B and B's
        // parent is A, so neither is ever a root and the walk never starts
        // there — or a branch cut for depth, whose children the walk stopped
        // descending into.
        //
        // Reported as `stranded` rather than guessed at. Telling somebody
        // their folder is in a loop when it is really four levels below one
        // that was too deep sends them looking in the wrong place.
        foreach ($folders as $folder) {
            if (!isset($seen[$folder->id])) {
                $unreachable[] = [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'reason' => 'stranded',
                ];
            }
        }

        return new self($entries, $unreachable);
    }

    /**
     * @param list<SourceFolder> $folders
     *
     * @return list<SourceFolder>
     */
    private static function sorted(array $folders): array
    {
        usort(
            $folders,
            static fn (SourceFolder $a, SourceFolder $b): int => [$a->sortOrder, $a->id] <=> [$b->sortOrder, $b->id]
        );

        return $folders;
    }
}
