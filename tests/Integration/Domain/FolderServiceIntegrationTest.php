<?php

namespace FolderFolio\Tests\Integration\Domain;

use FolderFolio\Domain\Folder;
use FolderFolio\Domain\FolderService;
use FolderFolio\Database\Schema;
use WP_UnitTestCase;

/**
 * FolderService against a real WordPress database.
 *
 * These were written against v0.2.0, where `create()`, `update()` and `move()`
 * returned an int id, an int and a bool. They return `Folder|WP_Error` now —
 * since the domain rewrite — and nothing noticed for months, because the
 * integration suite ran nowhere but CI and CI had not run it since. The
 * handful of assertions here are the same assertions; they just read the
 * object instead of the id.
 */
class FolderServiceIntegrationTest extends WP_UnitTestCase
{
    private FolderService $service;

    public function setUp(): void
    {
        parent::setUp();
        (new Schema())->migrate();
        $this->service = new FolderService();

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_attachment_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folders");
    }

    /**
     * @test
     */
    public function creates_root_folder(): void
    {
        $result = $this->service->create(['name' => 'Test Folder']);
        $this->assertInstanceOf(Folder::class, $result);
        $this->assertGreaterThan(0, $result->id);
        $tree = $this->service->tree();
        $this->assertCount(1, $tree);
        $this->assertSame('Test Folder', $tree[0]['name']);
    }

    /**
     * @test
     */
    public function rejects_empty_folder_name(): void
    {
        $result = $this->service->create(['name' => '']);
        $this->assertWPError($result);
    }

    /**
     * @test
     */
    public function creates_and_updates_folder(): void
    {
        $folder = $this->service->create(['name' => 'Original', 'color' => '#FFFFFF']);
        $this->assertInstanceOf(Folder::class, $folder);

        $updated = $this->service->update($folder->id, ['name' => 'Updated']);

        // The updated folder comes back, not a bool: every caller that renames
        // one needs the new slug and path, and a second read to get them is a
        // read that can disagree with the write.
        $this->assertInstanceOf(Folder::class, $updated);
        $this->assertSame('Updated', $updated->name);
    }

    /**
     * @test
     */
    public function prevents_circular_parent(): void
    {
        $folder = $this->service->create(['name' => 'Folder']);
        $result = $this->service->move($folder->id, $folder->id);
        $this->assertWPError($result);
    }

    /**
     * @test
     */
    public function rejects_folder_name_over_191_chars(): void
    {
        $result = $this->service->create(['name' => str_repeat('a', 192)]);
        $this->assertWPError($result);
        $this->assertSame('folderfolio_name_too_long', $result->get_error_code());
    }

    /**
     * @test
     */
    public function prevents_moving_a_folder_into_its_own_descendant(): void
    {
        $parent = $this->service->create(['name' => 'Parent']);
        $child = $this->service->create(['name' => 'Child', 'parent_id' => $parent->id]);
        $grandchild = $this->service->create(['name' => 'Grandchild', 'parent_id' => $child->id]);

        $result = $this->service->move($parent->id, $grandchild->id);

        $this->assertWPError($result);
        $this->assertSame('folderfolio_circular_parent', $result->get_error_code());
    }

    /**
     * @test
     */
    public function rejects_an_invalid_folder_colour(): void
    {
        $result = $this->service->create(['name' => 'Folder', 'color' => 'not-a-colour']);
        $this->assertWPError($result);
    }

    /**
     * @test
     */
    public function reorders_siblings_and_persists_the_order(): void
    {
        $a = $this->service->create(['name' => 'Alpha']);
        $b = $this->service->create(['name' => 'Bravo']);
        $c = $this->service->create(['name' => 'Charlie']);

        $written = $this->service->reorder(null, [$c->id, $a->id, $b->id]);

        $this->assertSame(3, $written);

        $names = array_map(
            static fn (array $node): string => (string) $node['name'],
            $this->service->tree()
        );

        // Not alphabetical, which is what proves it: the tree query orders by
        // sort_order first and name only as a tie-break.
        $this->assertSame(['Charlie', 'Alpha', 'Bravo'], $names);
    }

    /**
     * @test
     */
    public function reorder_rejects_a_list_that_does_not_describe_the_whole_level(): void
    {
        $a = $this->service->create(['name' => 'Alpha']);
        $b = $this->service->create(['name' => 'Bravo']);
        $this->service->create(['name' => 'Charlie']);

        $result = $this->service->reorder(null, [$b->id, $a->id]);

        $this->assertWPError($result);
        $this->assertSame('folderfolio_reorder_stale', $result->get_error_code());
    }

    /**
     * @test
     */
    public function reorder_rejects_a_repeated_folder(): void
    {
        $a = $this->service->create(['name' => 'Alpha']);

        $result = $this->service->reorder(null, [$a->id, $a->id]);

        $this->assertWPError($result);
        $this->assertSame('folderfolio_reorder_duplicate', $result->get_error_code());
    }

    /**
     * @test
     */
    public function reorder_moves_a_folder_between_parents_in_one_call(): void
    {
        $left = $this->service->create(['name' => 'Left']);
        $right = $this->service->create(['name' => 'Right']);
        $moving = $this->service->create(['name' => 'Moving', 'parent_id' => $left->id]);
        $settled = $this->service->create(['name' => 'Settled', 'parent_id' => $right->id]);

        $written = $this->service->reorder($right->id, [$settled->id, $moving->id]);

        $this->assertSame(2, $written);

        $moved = $this->service->get($moving->id);
        $this->assertSame($right->id, $moved->parentId);

        $names = array_map(
            static fn (Folder $f): string => $f->name,
            $this->service->children($right->id)
        );
        $this->assertSame(['Settled', 'Moving'], $names);
        $this->assertSame([], $this->service->children($left->id));
    }

    /**
     * @test
     */
    public function reorder_refuses_to_put_a_folder_inside_itself(): void
    {
        $a = $this->service->create(['name' => 'Alpha']);

        $result = $this->service->reorder($a->id, [$a->id]);

        $this->assertWPError($result);
        $this->assertSame('folderfolio_circular_parent', $result->get_error_code());
    }

    /**
     * @test
     *
     * The all-or-nothing proof. The move is attempted first and fails on the
     * cycle check, so the order must not be written either — a half-applied
     * drag would leave the folder where it was, at a position nobody chose.
     */
    public function reorder_writes_nothing_when_the_move_inside_it_fails(): void
    {
        $parent = $this->service->create(['name' => 'Parent']);
        $child = $this->service->create(['name' => 'Child', 'parent_id' => $parent->id]);
        $before = $this->service->get($parent->id)->sortOrder;

        $result = $this->service->reorder($child->id, [$parent->id]);

        $this->assertWPError($result);
        $this->assertSame('folderfolio_circular_parent', $result->get_error_code());

        $after = $this->service->get($parent->id);
        $this->assertNull($after->parentId, 'the parent should still be at the top level');
        $this->assertSame($before, $after->sortOrder, 'no order should have been written');
    }
}
