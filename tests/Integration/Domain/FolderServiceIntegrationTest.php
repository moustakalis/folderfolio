<?php

namespace FolderFolio\Tests\Integration\Domain;

use FolderFolio\Domain\FolderService;
use FolderFolio\Database\Schema;
use WP_UnitTestCase;

/**
 * Integration tests for FolderService with real WordPress database.
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
        $this->assertIsInt($result);
        $this->assertGreaterThan(0, $result);
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
        $id = $this->service->create(['name' => 'Original', 'color' => '#FFFFFF']);
        $this->assertIsInt($id);
        $updated = $this->service->update($id, ['name' => 'Updated']);
        $this->assertTrue($updated);
    }

    /**
     * @test
     */
    public function prevents_circular_parent(): void
    {
        $id = $this->service->create(['name' => 'Folder']);
        $result = $this->service->move($id, $id);
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
        $child = $this->service->create(['name' => 'Child', 'parent_id' => $parent]);
        $grandchild = $this->service->create(['name' => 'Grandchild', 'parent_id' => $child]);

        $result = $this->service->move($parent, $grandchild);

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
