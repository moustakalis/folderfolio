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
}
