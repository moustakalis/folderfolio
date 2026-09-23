<?php

declare(strict_types=1);

namespace FolderFolio\Domain;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tree assembly and count roll-up.
 *
 * Free of WordPress by design: rows in, arrays out, no $wpdb, no functions.
 * Everything here is unit-testable without a WordPress bootstrap.
 *
 * ## On counts
 *
 * Every competitor examined (FileBird, Real Media Library, Folders, CatFolders
 * — see docs/research/) shows **direct** counts by default, so a parent holding
 * a hundred files in its children reads `0`. That is the single most common
 * complaint-shaped defect in this category, and we default to the other
 * behaviour.
 *
 * `rollUp()` sums each subtree in one O(n) pass over the tree we have already
 * built, with no extra query and no self-join.
 *
 * Its one inaccuracy is deliberate and documented: because a file may live in
 * several folders, a file filed in two folders within the same subtree is
 * counted twice by the sum. The badge is therefore directionally right and
 * cheap. Where an exact number matters — the header above the grid for the
 * selected folder — `AttachmentFolderRepository::subtreeCount()` runs a single
 * `COUNT(DISTINCT attachment_id)`, and that is the number that wins.
 */
final class FolderTree
{
    /**
     * Assemble flat rows into a nested tree.
     *
     * One pass to bucket by parent, then a walk. O(n), no recursion into the
     * database.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function fromRows(array $rows): array
    {
        /** @var array<int, list<array<string, mixed>>> $byParent */
        $byParent = [];

        foreach ($rows as $row) {
            $row['id'] = (int) $row['id'];
            $row['parent_id'] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
            $row['depth'] = isset($row['depth']) ? (int) $row['depth'] : 0;
            // Cast for the same reason id and depth are: this row goes
            // straight onto the wire and $wpdb hands every column back as a
            // string, so a client ordering by it would put "10" before "9".
            $row['sort_order'] = isset($row['sort_order']) ? (int) $row['sort_order'] : 0;
            $row['children'] = [];

            $byParent[$row['parent_id'] ?? 0][] = $row;
        }

        return self::attach($byParent, 0);
    }

    /**
     * @param array<int, list<array<string, mixed>>> $byParent
     * @return list<array<string, mixed>>
     */
    private static function attach(array $byParent, int $parentId): array
    {
        $nodes = $byParent[$parentId] ?? [];

        foreach ($nodes as &$node) {
            $node['children'] = self::attach($byParent, (int) $node['id']);
        }

        unset($node);

        return $nodes;
    }

    /**
     * Lock and pin, on every node — tier 2 item 10.
     *
     * `locked` is the folder's **own** mark; `locked_by` is the folder whose
     * lock covers it — itself, or the topmost locked ancestor, because a lock
     * covers the whole subtree and that is the folder somebody would have to
     * unlock. The server works it out once, from the tree it already has,
     * so every renderer reads one answer rather than each walking upwards.
     * `FolderLocks::guard()` asks the same question of a path for writes.
     *
     * @param list<array<string, mixed>> $nodes
     * @param array<int, true> $locked
     * @param array<int, true> $pinned
     * @return list<array<string, mixed>>
     */
    public static function withMarks(array $nodes, array $locked, array $pinned, ?int $lockedBy = null): array
    {
        foreach ($nodes as &$node) {
            $id = (int) $node['id'];
            $by = $lockedBy ?? (isset($locked[$id]) ? $id : null);

            $node['locked'] = isset($locked[$id]);
            $node['locked_by'] = $by;
            $node['pinned'] = isset($pinned[$id]);
            $node['children'] = self::withMarks($node['children'] ?? [], $locked, $pinned, $by);
        }

        unset($node);

        return $nodes;
    }

    /**
     * The per-folder orders, onto every node.
     *
     * Every node gets both keys whether or not it has a row, because null
     * here means something — *follow whatever the person is looking at* — and
     * a key that is sometimes absent is a key the client has to guess about.
     *
     * @param list<array<string, mixed>> $nodes
     * @param array<int, array{folders: ?string, files: ?string}> $sorts
     * @return list<array<string, mixed>>
     */
    public static function withSorts(array $nodes, array $sorts): array
    {
        foreach ($nodes as &$node) {
            $own = $sorts[(int) $node['id']] ?? ['folders' => null, 'files' => null];

            $node['sort_folders'] = $own['folders'];
            $node['sort_files'] = $own['files'];
            $node['children'] = self::withSorts($node['children'] ?? [], $sorts);
        }

        unset($node);

        return $nodes;
    }

    /**
     * Attach counts to every node.
     *
     * `count` is always the folder's own attachments. `total_count` is the
     * number of **different** files in the subtree when $inherited is true,
     * and equal to `count` when it is false — so the client reads one key
     * either way and the setting is a server-side concern.
     *
     * Different files, not assignments. Membership is many-to-many, so a file
     * filed in two sibling folders used to count twice in their parent: the
     * roll-up summed its children, while the number above the grid
     * (`subtreeCount()`, a `COUNT(DISTINCT)`) counted it once. A folder copied
     * *with files* beside its source doubled its parent's badge while the
     * parent filtered to the same files. `$overcount` — from `overcount()`
     * below — is how many times each folder's plain sum counted a file it had
     * already counted, and it is taken off here.
     *
     * The sum is still the sum: the correction only ever touches the ancestors
     * of files filed more than once, so a library filed one file to one folder
     * pays nothing for it.
     *
     * @param list<array<string, mixed>> $nodes
     * @param array<int, int>            $directCounts folder id => attachment count
     * `only_here` is the folder's own files that are filed **nowhere else** —
     * what becomes Unassigned if the folder is deleted with its subfolders
     * kept, which is the only delete the rail and the picker offer. The undo
     * toast says that number and no other: before 23 Sep it said the folder's
     * whole count had "moved to Unassigned", which was untrue of every file
     * that was also in another folder.
     *
     * @param array<int, int>            $overcount    folder id => files counted twice or more
     * @param array<int, int>            $shared       folder id => its files also filed elsewhere
     * @return list<array<string, mixed>>
     */
    public static function withCounts(
        array $nodes,
        array $directCounts,
        bool $inherited = true,
        array $overcount = [],
        array $shared = []
    ): array {
        return self::countLevel($nodes, $directCounts, $inherited, $overcount, $shared)[0];
    }

    /**
     * For each folder, how many of its own files are also filed in another.
     *
     * Every folder a multi-filed file sits in holds one such file: the file's
     * other folder is, by definition, somewhere else.
     *
     * @param array<int, list<int>> $foldersByAttachment attachment id => its folders; only files filed 2+ times
     * @return array<int, int> folder id => shared files
     */
    public static function shared(array $foldersByAttachment): array
    {
        $shared = [];

        foreach ($foldersByAttachment as $folderIds) {
            $distinct = array_unique($folderIds);

            if (count($distinct) < 2) {
                continue;
            }

            foreach ($distinct as $folderId) {
                $shared[$folderId] = ($shared[$folderId] ?? 0) + 1;
            }
        }

        return $shared;
    }

    /**
     * How many times each folder's plain roll-up counts a file more than once.
     *
     * For every file filed in two or more folders, walk the chain of each of
     * its folders — root to folder, which the path already is — and count how
     * many of its folders sit under each ancestor. An ancestor above k of them
     * summed the file k times and should have counted it once: k − 1 over.
     *
     * Two sibling folders both holding a file: 1 over on their parent, and on
     * every folder above it. A file in a folder and in that folder's own
     * child: 1 over on the folder, because its own count and its child's both
     * include the file. A file in two unrelated roots: nothing, because no
     * folder is above both.
     *
     * @param array<int, list<int>> $foldersByAttachment attachment id => its folders; only files filed 2+ times
     * @param array<int, string>    $paths               folder id => materialised path
     * @return array<int, int> folder id => overcount
     */
    public static function overcount(array $foldersByAttachment, array $paths): array
    {
        $over = [];

        foreach ($foldersByAttachment as $folderIds) {
            $under = [];

            foreach (array_unique($folderIds) as $folderId) {
                // A folder of another object type, or one deleted since the
                // assignment was written: not in this tree, so it is in no
                // total here either.
                if (!isset($paths[$folderId])) {
                    continue;
                }

                foreach (FolderPath::ids($paths[$folderId]) as $ancestorId) {
                    $under[$ancestorId] = ($under[$ancestorId] ?? 0) + 1;
                }
            }

            foreach ($under as $ancestorId => $times) {
                if ($times > 1) {
                    $over[$ancestorId] = ($over[$ancestorId] ?? 0) + $times - 1;
                }
            }
        }

        return $over;
    }

    /**
     * One level of the roll-up, and that level's plain sum.
     *
     * The plain sum travels up separately from the corrected `total_count`,
     * because each folder's correction is relative to its own plain sum:
     * adding children's already-corrected totals and then subtracting again
     * would take a doubly-filed file off twice.
     *
     * @param list<array<string, mixed>> $nodes
     * @param array<int, int>            $directCounts
     * @param array<int, int>            $overcount
     * @param array<int, int>            $shared
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private static function countLevel(
        array $nodes,
        array $directCounts,
        bool $inherited,
        array $overcount,
        array $shared
    ): array {
        $levelSum = 0;

        foreach ($nodes as &$node) {
            $id = (int) $node['id'];
            $own = $directCounts[$id] ?? 0;

            [$node['children'], $below] = self::countLevel(
                $node['children'] ?? [],
                $directCounts,
                $inherited,
                $overcount,
                $shared
            );

            $sum = $own + $below;

            $node['count'] = $own;
            $node['only_here'] = max(0, $own - ($shared[$id] ?? 0));
            $node['total_count'] = $inherited ? $sum - ($overcount[$id] ?? 0) : $own;

            $levelSum += $sum;
        }

        unset($node);

        return [$nodes, $levelSum];
    }

    /**
     * Walk every node depth-first, parents before children.
     *
     * @param list<array<string, mixed>> $nodes
     * @param callable(array<string, mixed>, int): void $visitor
     */
    public static function walk(array $nodes, callable $visitor, int $depth = 0): void
    {
        foreach ($nodes as $node) {
            $visitor($node, $depth);

            self::walk($node['children'] ?? [], $visitor, $depth + 1);
        }
    }

    /**
     * Flatten a tree back to a list, preserving display order.
     *
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    public static function flatten(array $nodes): array
    {
        $flat = [];

        self::walk($nodes, static function (array $node, int $depth) use (&$flat): void {
            unset($node['children']);
            $node['depth'] = $depth;
            $flat[] = $node;
        });

        return $flat;
    }
}
