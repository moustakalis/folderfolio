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

    /**
     * @test
     *
     * Brand ─ Logos (red, files Z–A) ─ Primary
     *       └ Print
     */
    public function duplicate_copies_the_whole_subtree_with_its_properties(): void
    {
        $brand = $this->service->create(['name' => 'Brand']);
        $logos = $this->service->create(['name' => 'Logos', 'parent_id' => $brand->id, 'color' => 'red', 'sort_order' => 3]);
        $this->service->create(['name' => 'Primary', 'parent_id' => $logos->id]);
        $this->service->create(['name' => 'Print', 'parent_id' => $brand->id, 'sort_order' => 1]);
        (new \FolderFolio\Domain\FolderSorts())->set($logos->id, 'files', 'name-desc');

        $copy = $this->service->duplicate($brand->id, null);

        $this->assertInstanceOf(Folder::class, $copy);
        $this->assertSame('Brand copy', $copy->name);
        $this->assertNull($copy->parentId);

        $children = $this->service->children($copy->id);
        $byName = [];
        foreach ($children as $child) {
            $byName[$child->name] = $child;
        }

        $names = array_keys($byName);
        sort($names);
        $this->assertSame(['Logos', 'Print'], $names);
        $this->assertSame('red', $byName['Logos']->color);
        $this->assertSame(3, $byName['Logos']->sortOrder);
        $this->assertSame(1, $byName['Print']->sortOrder);
        $this->assertSame('name-desc', (new \FolderFolio\Domain\FolderSorts())->for($byName['Logos']->id)['files']);
        $this->assertSame(['Primary'], array_map(static fn (Folder $f): string => $f->name, $this->service->children($byName['Logos']->id)));
        $this->assertNotSame($logos->id, $byName['Logos']->id);

        // The source is untouched.
        $this->assertCount(2, $this->service->children($brand->id));
    }

    /**
     * @test
     */
    public function a_second_paste_numbers_the_copy_and_a_paste_elsewhere_keeps_the_name(): void
    {
        $brand = $this->service->create(['name' => 'Brand']);
        $clients = $this->service->create(['name' => 'Clients']);

        $this->assertSame('Brand copy', $this->service->duplicate($brand->id, null)->name);
        $this->assertSame('Brand copy 2', $this->service->duplicate($brand->id, null)->name);
        $this->assertSame('Brand', $this->service->duplicate($brand->id, $clients->id)->name);
    }

    /**
     * @test
     */
    public function duplicate_refuses_to_paste_a_folder_inside_itself_and_writes_nothing(): void
    {
        $brand = $this->service->create(['name' => 'Brand']);
        $logos = $this->service->create(['name' => 'Logos', 'parent_id' => $brand->id]);
        $before = count(\FolderFolio\Domain\FolderTree::flatten($this->service->tree()));

        foreach ([$brand->id, $logos->id] as $target) {
            $result = $this->service->duplicate($brand->id, $target);
            $this->assertWPError($result);
            $this->assertSame('folderfolio_paste_into_itself', $result->get_error_code());
        }

        $this->assertSame($before, count(\FolderFolio\Domain\FolderTree::flatten($this->service->tree())));
    }

    /**
     * @test
     *
     * Checked against the whole subtree before the transaction opens, so a
     * paste that would put the deepest folder past the limit creates nothing —
     * not the top three levels and then a rollback.
     */
    public function duplicate_checks_the_depth_of_the_whole_subtree_first(): void
    {
        add_filter('folderfolio_max_depth', static fn (): int => 3);

        $a = $this->service->create(['name' => 'A']);
        $b = $this->service->create(['name' => 'B', 'parent_id' => $a->id]);
        $this->service->create(['name' => 'C', 'parent_id' => $b->id]);
        $host = $this->service->create(['name' => 'Host']);
        $before = count(\FolderFolio\Domain\FolderTree::flatten($this->service->tree()));

        $result = $this->service->duplicate($a->id, $host->id);

        $this->assertWPError($result);
        $this->assertSame('folderfolio_max_depth_exceeded', $result->get_error_code());
        $this->assertSame($before, count(\FolderFolio\Domain\FolderTree::flatten($this->service->tree())));
    }

    /**
     * @test
     */
    public function duplicate_with_files_files_the_same_media_and_without_files_files_none(): void
    {
        $brand = $this->service->create(['name' => 'Brand']);
        $logos = $this->service->create(['name' => 'Logos', 'parent_id' => $brand->id]);
        $attachment = self::factory()->attachment->create(['post_mime_type' => 'image/jpeg']);
        $this->service->assignAttachments($logos->id, [$attachment]);

        $bare = $this->service->duplicate($brand->id, null, false);
        $full = $this->service->duplicate($brand->id, null, true);

        $this->assertSame(0, $this->service->countAttachments($bare->id));
        $this->assertSame(1, $this->service->countAttachments($full->id));
        $this->assertSame([$attachment], $this->service->attachmentIds($full->id, true));

        // Filed twice, not moved: the source still holds it.
        $this->assertSame([$attachment], $this->service->attachmentIds($logos->id));
    }

    /**
     * @test
     */
    public function duplicate_places_the_copy_where_the_order_puts_it(): void
    {
        $a = $this->service->create(['name' => 'A']);
        $b = $this->service->create(['name' => 'B']);

        $copy = $this->service->duplicate($a->id, null, false, [$a->id, 0, $b->id]);

        $order = array_map(
            static fn (array $node): int => (int) $node['sort_order'],
            $this->service->tree()
        );

        $this->assertSame([0, 1, 2], $order);
        $this->assertSame(1, $this->service->get($copy->id)->sortOrder);
    }
}
