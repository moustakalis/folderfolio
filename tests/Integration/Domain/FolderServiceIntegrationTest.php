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

        // Ensure tables exist
        (new Schema())->migrate();
        $this->service = new FolderService();

        // Clear test data
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
        $this->assertNull($tree[0]['parent_id']);
    }

    /**
     * @test
     */
    public function creates_nested_folders(): void
    {
        $parentId = $this->service->create(['name' => 'Parent']);
        $childId = $this->service->create([
            'name' => 'Child',
            'parent_id' => $parentId,
        ]);

        $this->assertIsInt($parentId);
        $this->assertIsInt($childId);

        $tree = $this->service->tree();
        $this->assertCount(1, $tree);
        $this->assertSame('Parent', $tree[0]['name']);
        $this->assertCount(1, $tree[0]['children']);
        $this->assertSame('Child', $tree[0]['children'][0]['name']);
    }

    /**
     * @test
     */
    public function rejects_empty_folder_name(): void
    {
        $result = $this->service->create(['name' => '']);

        $this->assertWPError($result);
        $this->assertSame('folderfolio_name_required', $result->get_error_code());
    }

    /**
     * @test
     */
    public function rejects_nonexistent_parent(): void
    {
        $result = $this->service->create([
            'name' => 'Orphan',
            'parent_id' => 999999,
        ]);

        $this->assertWPError($result);
        $this->assertSame('folderfolio_invalid_parent', $result->get_error_code());
    }

    /**
     * @test
     */
    public function updates_folder_name_and_color(): void
    {
        $folderId = $this->service->create(['name' => 'Original']);
        $result = $this->service->update($folderId, [
            'name' => 'Updated',
            'color' => '#3047A8',
        ]);

        $this->assertTrue($result);

        $tree = $this->service->tree();
        $this->assertSame('Updated', $tree[0]['name']);
        $this->assertSame('#3047A8', $tree[0]['color']);
    }

    /**
     * @test
     */
    public function prevents_folder_from_being_its_own_parent(): void
    {
        $folderId = $this->service->create(['name' => 'Folder']);
        $result = $this->service->move($folderId, $folderId);

        $this->assertWPError($result);
        $this->assertSame('folderfolio_circular_parent', $result->get_error_code());
    }

    /**
     * @test
     */
    public function prevents_moving_parent_into_its_descendant(): void
    {
        $parentId = $this->service->create(['name' => 'Parent']);
        $childId = $this->service->create([
            'name' => 'Child',
            'parent_id' => $parentId,
        ]);
        $grandchildId = $this->service->create([
            'name' => 'Grandchild',
            'parent_id' => $childId,
        ]);

        $result = $this->service->move($parentId, $grandchildId);

        $this->assertWPError($result);
        $this->assertSame('folderfolio_circular_parent', $result->get_error_code());
    }

    /**
     * @test
     */
    public function deletes_folder_but_preserves_attachments(): void
    {
        $folderId = $this->service->create(['name' => 'Temporary']);
        $attachmentId = $this->factory->attachment->create_upload_object(
            dirname(__DIR__, 2) . '/fixtures/test-image.jpg'
        );

        // If fixture is unavailable, create generic attachment post
        if (!$attachmentId) {
            $attachmentId = $this->factory->post->create(['post_type' => 'attachment']);
        }

        $this->service->assignAttachments($folderId, [$attachmentId]);
        $result = $this->service->delete($folderId);

        $this->assertTrue($result);
        $this->assertEmpty($this->service->tree());
        $this->assertSame('attachment', get_post_type($attachmentId));
    }

    /**
     * @test
     */
    public function reassigns_attachments_when_deleting_folder(): void
    {
        $sourceId = $this->service->create(['name' => 'Source']);
        $destinationId = $this->service->create(['name' => 'Destination']);
        $attachmentId = $this->factory->post->create(['post_type' => 'attachment']);

        $this->service->assignAttachments($sourceId, [$attachmentId]);
        $result = $this->service->delete($sourceId, $destinationId);

        $this->assertTrue($result);
        $attachmentIds = $this->service->attachmentIds($destinationId);
        $this->assertContains($attachmentId, $attachmentIds);
    }

    /**
     * @test
     */
    public function assigns_attachment_to_multiple_folders(): void
    {
        $folderA = $this->service->create(['name' => 'Folder A']);
        $folderB = $this->service->create(['name' => 'Folder B']);
        $attachmentId = $this->factory->post->create(['post_type' => 'attachment']);

        $this->assertSame(1, $this->service->assignAttachments($folderA, [$attachmentId]));
        $this->assertSame(1, $this->service->assignAttachments($folderB, [$attachmentId]));

        $this->assertContains($attachmentId, $this->service->attachmentIds($folderA));
        $this->assertContains($attachmentId, $this->service->attachmentIds($folderB));
    }

    /**
     * @test
     */
    public function moves_attachment_between_folders(): void
    {
        $sourceId = $this->service->create(['name' => 'Source']);
        $destinationId = $this->service->create(['name' => 'Destination']);
        $attachmentId = $this->factory->post->create(['post_type' => 'attachment']);

        $this->service->assignAttachments($sourceId, [$attachmentId]);
        $result = $this->service->moveAttachments($sourceId, $destinationId, [$attachmentId]);

        $this->assertSame(1, $result);
        $this->assertNotContains($attachmentId, $this->service->attachmentIds($sourceId));
        $this->assertContains($attachmentId, $this->service->attachmentIds($destinationId));
    }
}
