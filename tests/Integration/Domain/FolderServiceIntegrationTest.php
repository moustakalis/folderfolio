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
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}folderfolio_attachment_folders");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}folderfolio_folders");
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
}
