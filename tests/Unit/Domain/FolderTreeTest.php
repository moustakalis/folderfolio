<?php

declare(strict_types=1);

namespace FolderFolio\Tests\Unit\Domain;

use FolderFolio\Domain\FolderTree;
use PHPUnit\Framework\TestCase;

final class FolderTreeTest extends TestCase
{
    /**
     * Brand ─ Logos ─ Primary
     * Archive ─ 2019
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(): array
    {
        return [
            ['id' => 1,  'parent_id' => null, 'name' => 'Brand',   'path' => '/1/',     'depth' => 0],
            ['id' => 7,  'parent_id' => 1,    'name' => 'Logos',   'path' => '/1/7/',   'depth' => 1],
            ['id' => 12, 'parent_id' => 7,    'name' => 'Primary', 'path' => '/1/7/12/', 'depth' => 2],
            ['id' => 20, 'parent_id' => null, 'name' => 'Archive', 'path' => '/20/',    'depth' => 0],
            ['id' => 21, 'parent_id' => 20,   'name' => '2019',    'path' => '/20/21/', 'depth' => 1],
        ];
    }

    public function test_rows_become_a_nested_tree(): void
    {
        $tree = FolderTree::fromRows(self::rows());

        self::assertCount(2, $tree);
        self::assertSame('Brand', $tree[0]['name']);
        self::assertSame('Logos', $tree[0]['children'][0]['name']);
        self::assertSame('Primary', $tree[0]['children'][0]['children'][0]['name']);
        self::assertSame([], $tree[0]['children'][0]['children'][0]['children']);
    }

    public function test_orphans_whose_parent_is_missing_are_not_silently_dropped_into_root(): void
    {
        $tree = FolderTree::fromRows([
            ['id' => 5, 'parent_id' => 999, 'name' => 'Orphan', 'path' => '/999/5/', 'depth' => 1],
        ]);

        // Its parent does not exist, so it is not reachable from root. It is
        // absent from the tree rather than promoted — the repair tooling finds
        // it, the UI does not invent a home for it.
        self::assertSame([], $tree);
    }

    /**
     * The defect every competitor ships.
     *
     * Brand itself holds nothing; its grandchild holds five files. Direct
     * counting makes Brand read `0`, which anyone would take to mean empty.
     */
    public function test_inherited_counts_sum_the_subtree(): void
    {
        $tree = FolderTree::withCounts(
            FolderTree::fromRows(self::rows()),
            [12 => 5, 21 => 3],
        );

        self::assertSame(0, $tree[0]['count'], 'Brand holds no files of its own');
        self::assertSame(5, $tree[0]['total_count'], 'but its subtree holds five');
        self::assertSame(5, $tree[0]['children'][0]['total_count']);
        self::assertSame(5, $tree[0]['children'][0]['children'][0]['total_count']);
        self::assertSame(3, $tree[1]['total_count']);
    }

    public function test_direct_mode_reports_only_a_folders_own_files(): void
    {
        $tree = FolderTree::withCounts(
            FolderTree::fromRows(self::rows()),
            [12 => 5, 21 => 3],
            false
        );

        self::assertSame(0, $tree[0]['total_count'], 'Brand reads 0 in direct mode');
        self::assertSame(5, $tree[0]['children'][0]['children'][0]['total_count']);
    }

    /**
     * The known, deliberate inaccuracy of the roll-up.
     *
     * A file filed in two folders inside one subtree is summed twice, because
     * the roll-up adds numbers rather than de-duplicating ids. That keeps the
     * tree badge to zero extra queries. Where the exact figure matters — the
     * header above the grid — AttachmentFolderRepository::subtreeCount() runs
     * a COUNT(DISTINCT) and that number wins.
     *
     * This test exists so the behaviour is a decision on record rather than a
     * surprise in a bug report.
     */
    public function test_a_file_in_two_folders_of_one_subtree_is_counted_twice(): void
    {
        $tree = FolderTree::withCounts(
            FolderTree::fromRows(self::rows()),
            [7 => 1, 12 => 1],
        );

        self::assertSame(
            2,
            $tree[0]['total_count'],
            'the same file in Logos and Primary sums to 2; subtreeCount() would say 1'
        );
    }

    public function test_counts_default_to_zero_for_empty_folders(): void
    {
        $tree = FolderTree::withCounts(FolderTree::fromRows(self::rows()), []);

        self::assertSame(0, $tree[0]['count']);
        self::assertSame(0, $tree[0]['total_count']);
    }

    public function test_flatten_preserves_display_order_and_records_depth(): void
    {
        $flat = FolderTree::flatten(FolderTree::fromRows(self::rows()));

        self::assertSame(
            ['Brand', 'Logos', 'Primary', 'Archive', '2019'],
            array_column($flat, 'name')
        );
        self::assertSame([0, 1, 2, 0, 1], array_column($flat, 'depth'));
        self::assertArrayNotHasKey('children', $flat[0]);
    }

    public function test_walk_visits_parents_before_children(): void
    {
        $seen = [];

        FolderTree::walk(
            FolderTree::fromRows(self::rows()),
            static function (array $node) use (&$seen): void {
                $seen[] = $node['id'];
            }
        );

        self::assertSame([1, 7, 12, 20, 21], $seen);
    }

    /**
     * Both keys on every node, whether or not the folder has a row.
     *
     * Null means *follow whatever the person is looking at*, which is a
     * value. A key that is sometimes absent is a key the client has to guess
     * about, and it would be absent for exactly the folders nobody has
     * touched — the common case.
     */
    public function test_every_node_carries_both_sort_keys(): void
    {
        $tree = FolderTree::withSorts(FolderTree::fromRows(self::rows()), [
            1 => ['folders' => 'custom', 'files' => null],
            12 => ['folders' => null, 'files' => 'oldest'],
        ]);

        self::assertSame('custom', $tree[0]['sort_folders']);
        self::assertNull($tree[0]['sort_files']);

        // Three levels down, which is the recursion doing its job.
        self::assertSame('oldest', $tree[0]['children'][0]['children'][0]['sort_files']);
        self::assertNull($tree[0]['children'][0]['children'][0]['sort_folders']);

        // And a folder nobody has given an order still answers both.
        self::assertArrayHasKey('sort_folders', $tree[1]);
        self::assertArrayHasKey('sort_files', $tree[1]);
        self::assertNull($tree[1]['sort_folders']);
        self::assertNull($tree[1]['sort_files']);
    }

    /**
     * An order set on one folder must not reach its sibling.
     *
     * The bug this guards is the one a naive per-node implementation makes:
     * carrying the parent's order down as a default and then writing it onto
     * every node it passes.
     */
    public function test_a_folders_order_does_not_leak_to_its_sibling(): void
    {
        $tree = FolderTree::withSorts(FolderTree::fromRows(self::rows()), [
            1 => ['folders' => 'name-desc', 'files' => 'newest'],
        ]);

        self::assertSame('name-desc', $tree[0]['sort_folders']);
        self::assertNull($tree[1]['sort_folders']);
        self::assertNull($tree[0]['children'][0]['sort_folders']);
    }

    /**
     * Brand ─ Logos ─ Primary
     *       └ Print
     * Archive
     *
     * @return array<int, string> folder id => path
     */
    private static function paths(): array
    {
        return [1 => '/1/', 7 => '/1/7/', 12 => '/1/7/12/', 8 => '/1/8/', 20 => '/20/'];
    }

    /** @return list<array<string, mixed>> */
    private static function branchRows(): array
    {
        return [
            ['id' => 1,  'parent_id' => null, 'name' => 'Brand',   'path' => '/1/',      'depth' => 0],
            ['id' => 7,  'parent_id' => 1,    'name' => 'Logos',   'path' => '/1/7/',    'depth' => 1],
            ['id' => 12, 'parent_id' => 7,    'name' => 'Primary', 'path' => '/1/7/12/', 'depth' => 2],
            ['id' => 8,  'parent_id' => 1,    'name' => 'Print',   'path' => '/1/8/',    'depth' => 1],
            ['id' => 20, 'parent_id' => null, 'name' => 'Archive', 'path' => '/20/',     'depth' => 0],
        ];
    }

    public function test_a_file_in_two_sibling_folders_counts_once_in_their_parent(): void
    {
        // File 500 is in Logos and in Print.
        $over = FolderTree::overcount([500 => [7, 8]], self::paths());

        self::assertSame([1 => 1], $over);

        $tree = FolderTree::withCounts(
            FolderTree::fromRows(self::branchRows()),
            [7 => 1, 8 => 1],
            true,
            $over
        );

        self::assertSame(1, $tree[0]['total_count'], 'Brand holds one file, not two');
        self::assertSame(1, $tree[0]['children'][0]['total_count']);
    }

    public function test_a_file_in_a_folder_and_its_own_child_counts_once_in_both_above(): void
    {
        // File 500 is in Logos and in Primary, which is inside Logos.
        $over = FolderTree::overcount([500 => [7, 12]], self::paths());

        self::assertSame([1 => 1, 7 => 1], $over);

        $tree = FolderTree::withCounts(
            FolderTree::fromRows(self::branchRows()),
            [7 => 1, 12 => 1],
            true,
            $over
        );

        self::assertSame(1, $tree[0]['total_count']);
        self::assertSame(1, $tree[0]['children'][0]['total_count']);
        self::assertSame(1, $tree[0]['children'][0]['children'][0]['total_count']);
    }

    /**
     * The negative control for the one way to get this wrong: correcting each
     * folder against its children's *corrected* totals takes the same file off
     * once per level it overlaps at. Two siblings two levels down overlap at
     * Logos and again at Brand — Brand would read 0 here, and 1 is right.
     * (Proved on 23 Sep: this reversion fails this test and the one above.)
     */
    public function test_the_correction_is_taken_once_however_deep_the_overlap_sits(): void
    {
        $rows = self::branchRows();
        $rows[] = ['id' => 13, 'parent_id' => 7, 'name' => 'Secondary', 'path' => '/1/7/13/', 'depth' => 2];
        $paths = self::paths() + [13 => '/1/7/13/'];

        // File 500 is in Primary and in Secondary, both inside Logos.
        $over = FolderTree::overcount([500 => [12, 13]], $paths);

        self::assertSame([1 => 1, 7 => 1], $over);

        $tree = FolderTree::withCounts(FolderTree::fromRows($rows), [12 => 1, 13 => 1], true, $over);

        self::assertSame(1, $tree[0]['total_count'], 'Brand holds one file');
        self::assertSame(1, $tree[0]['children'][0]['total_count'], 'Logos holds one file');
    }

    public function test_a_file_in_two_unrelated_roots_is_not_corrected_anywhere(): void
    {
        self::assertSame([], FolderTree::overcount([500 => [7, 20]], self::paths()));
    }

    public function test_a_folder_outside_this_tree_is_ignored(): void
    {
        self::assertSame([], FolderTree::overcount([500 => [7, 999]], self::paths()));
    }

    public function test_direct_mode_ignores_the_correction(): void
    {
        $tree = FolderTree::withCounts(
            FolderTree::fromRows(self::branchRows()),
            [7 => 1, 8 => 1],
            false,
            [1 => 1]
        );

        self::assertSame(0, $tree[0]['total_count']);
        self::assertSame(1, $tree[0]['children'][0]['total_count']);
    }

    public function test_only_here_is_a_folders_files_that_are_filed_nowhere_else(): void
    {
        // Logos holds 500 (also in Print) and 501 (only here); Print holds 500.
        $shared = FolderTree::shared([500 => [7, 8]]);

        self::assertSame([7 => 1, 8 => 1], $shared);

        $tree = FolderTree::withCounts(
            FolderTree::fromRows(self::branchRows()),
            [7 => 2, 8 => 1],
            false,
            [],
            $shared
        );

        self::assertSame(1, $tree[0]['children'][0]['only_here'], 'Deleting Logos unassigns 501 alone');
        self::assertSame(0, $tree[0]['children'][1]['only_here'], 'Deleting Print unassigns nothing');
        self::assertSame(0, $tree[0]['only_here']);
    }
}
