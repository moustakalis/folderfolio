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
}
