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
     * subtree sum when $inherited is true, and equal to `count` when it is
     * false — so the client reads one key either way and the setting is a
     * server-side concern.
     *
     * @param list<array<string, mixed>> $nodes
     * @param array<int, int>            $directCounts folder id => attachment count
     * @return list<array<string, mixed>>
     */
    public static function withCounts(array $nodes, array $directCounts, bool $inherited = true): array
    {
        foreach ($nodes as &$node) {
            $id = (int) $node['id'];
            $own = $directCounts[$id] ?? 0;

            $node['children'] = self::withCounts($node['children'] ?? [], $directCounts, $inherited);
            $node['count'] = $own;

            if (!$inherited) {
                $node['total_count'] = $own;
                continue;
            }

            $subtree = $own;

            foreach ($node['children'] as $child) {
                $subtree += (int) $child['total_count'];
            }

            $node['total_count'] = $subtree;
        }

        unset($node);

        return $nodes;
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
