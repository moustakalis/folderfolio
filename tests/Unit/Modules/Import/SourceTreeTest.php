<?php

declare(strict_types=1);

namespace FolderFolio\Tests\Unit\Modules\Import;

use FolderFolio\Modules\Import\SourceFolder;
use FolderFolio\Modules\Import\SourceTree;
use PHPUnit\Framework\TestCase;

/**
 * The walk that turns a competitor's folder rows into something importable.
 *
 * Pure, and the right place for the three cases that are impossible to
 * arrange in a real source plugin and inevitable in its data: a parent that
 * has been deleted, a cycle, and a tree deeper than the column can hold.
 */
final class SourceTreeTest extends TestCase
{
    /**
     * @param list<array{folder: SourceFolder, trail: list<string>}> $entries
     *
     * @return list<string>
     */
    private static function paths(array $entries): array
    {
        return array_map(
            static fn (array $entry): string => implode(' / ', $entry['trail']),
            $entries
        );
    }

    public function test_parents_come_before_their_children(): void
    {
        // Deliberately out of order, and with the child first: a source that
        // returns rows in id order gives exactly this whenever a folder has
        // been moved.
        $tree = SourceTree::of([
            new SourceFolder(3, 2, 'Primary'),
            new SourceFolder(1, null, 'Brand'),
            new SourceFolder(2, 1, 'Logos'),
        ]);

        $this->assertSame(
            ['Brand', 'Brand / Logos', 'Brand / Logos / Primary'],
            self::paths($tree->entries)
        );
        $this->assertSame([], $tree->unreachable);
    }

    public function test_siblings_keep_the_order_the_source_gave_them(): void
    {
        $tree = SourceTree::of([
            new SourceFolder(1, null, 'Second', 20),
            new SourceFolder(2, null, 'First', 10),
            new SourceFolder(3, null, 'Third', 30),
        ]);

        $this->assertSame(['First', 'Second', 'Third'], self::paths($tree->entries));
    }

    public function test_a_folder_whose_parent_is_gone_is_promoted_rather_than_dropped(): void
    {
        // The single most common shape of broken source data: the parent row
        // was deleted and nothing cascaded. Dropping the folder would lose
        // every file in it silently, which is the outcome worth avoiding.
        $tree = SourceTree::of([
            new SourceFolder(1, null, 'Brand'),
            new SourceFolder(9, 404, 'Orphan'),
        ]);

        $this->assertSame(['Brand', 'Orphan'], self::paths($tree->entries));
        $this->assertSame([], $tree->unreachable, 'A promoted folder is imported, so it is not unreachable.');
    }

    public function test_a_two_folder_cycle_is_reported_and_not_walked(): void
    {
        // Neither is ever a root, so a recursive walk would never start here
        // and both folders would vanish without a word.
        $tree = SourceTree::of([
            new SourceFolder(1, 2, 'A'),
            new SourceFolder(2, 1, 'B'),
        ]);

        $this->assertSame([], $tree->entries);
        $this->assertSame(
            ['A', 'B'],
            array_column($tree->unreachable, 'name')
        );
        // `stranded`, not `cycle`: neither was ever reached, so neither was
        // ever caught looping. The walk knows only that it could not place
        // them, and says that rather than guessing.
        $this->assertSame(['stranded', 'stranded'], array_column($tree->unreachable, 'reason'));
    }

    public function test_a_cycle_hanging_off_a_real_root_does_not_hang_the_walk(): void
    {
        $tree = SourceTree::of([
            new SourceFolder(1, null, 'Root'),
            new SourceFolder(2, 3, 'Loop A'),
            new SourceFolder(3, 2, 'Loop B'),
        ]);

        $this->assertSame(['Root'], self::paths($tree->entries));
        $this->assertCount(2, $tree->unreachable);
    }

    public function test_a_branch_deeper_than_the_column_allows_is_cut_and_reported(): void
    {
        $folders = [new SourceFolder(1, null, 'L1')];

        for ($i = 2; $i <= 25; ++$i) {
            $folders[] = new SourceFolder($i, $i - 1, 'L' . $i);
        }

        $tree = SourceTree::of($folders);

        // MAX_DEPTH is 20 levels, so the 21st is the first refused, and the
        // four below it are stranded rather than cut on their own account.
        $this->assertCount(20, $tree->entries);
        $this->assertSame(
            ['L21', 'L22', 'L23', 'L24', 'L25'],
            array_column($tree->unreachable, 'name')
        );
        $this->assertSame(
            ['too_deep', 'stranded', 'stranded', 'stranded', 'stranded'],
            array_column($tree->unreachable, 'reason')
        );
    }

    public function test_an_empty_source_is_an_empty_walk(): void
    {
        $tree = SourceTree::of([]);

        $this->assertSame([], $tree->entries);
        $this->assertSame([], $tree->unreachable);
    }

    public function test_a_name_with_a_slash_in_it_stays_one_folder(): void
    {
        // The reason the planner and the runner walk parent by parent instead
        // of going through getOrCreateByPath(), which splits on '/'.
        $tree = SourceTree::of([new SourceFolder(1, null, 'Q1/Q2')]);

        $this->assertSame(['Q1/Q2'], $tree->entries[0]['trail']);
    }
}
