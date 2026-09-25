<?php

namespace FolderFolio\Tests\Integration\Domain;

use FolderFolio\Database\Schema;
use FolderFolio\Domain\Folder;
use FolderFolio\Domain\FolderService;
use WP_UnitTestCase;

/**
 * What a delete leaves behind, and where it puts the files it was asked to
 * keep — review M3 and M13.
 */
class FolderDeleteTest extends WP_UnitTestCase
{
    private FolderService $service;

    public function setUp(): void
    {
        parent::setUp();
        (new Schema())->migrate();
        $this->service = new FolderService();

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_attachment_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folder_meta");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folders");

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function make(string $name, ?int $parent = null): Folder
    {
        $folder = $this->service->create(['name' => $name, 'parent_id' => $parent]);
        $this->assertInstanceOf(Folder::class, $folder);

        return $folder;
    }

    private function file(): int
    {
        return self::factory()->attachment->create_object([
            'file' => 'probe.png',
            'post_mime_type' => 'image/png',
        ]);
    }

    /**
     * @test
     *
     * M3: a cascade's destination inside the subtree being deleted would be
     * filled and then deleted with it.
     */
    public function a_cascade_cannot_reassign_into_its_own_subtree(): void
    {
        $a = $this->make('A');
        $inside = $this->make('Inside', $a->id);
        $file = $this->file();
        $this->service->assignAttachments($a->id, [$file]);

        $result = $this->service->delete($a->id, FolderService::CHILDREN_CASCADE, $inside->id);

        $this->assertWPError($result);
        $this->assertSame('folderfolio_invalid_reassignment', $result->get_error_code());
        $this->assertNotNull($this->service->get($a->id), 'nothing was deleted');
        $this->assertSame([$a->id], array_map(static fn ($f) => $f->id, $this->service->foldersOf($file)));
    }

    /**
     * @test
     *
     * The same destination under a reparent is fine: the child stays.
     */
    public function a_reparent_may_reassign_into_a_child(): void
    {
        $a = $this->make('A');
        $inside = $this->make('Inside', $a->id);
        $file = $this->file();
        $this->service->assignAttachments($a->id, [$file]);

        $result = $this->service->delete($a->id, FolderService::CHILDREN_REPARENT, $inside->id);

        $this->assertNotWPError($result);
        $this->assertSame([$inside->id], array_map(static fn ($f) => $f->id, $this->service->foldersOf($file)));
    }

    private function metaCount(int $folderId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}folderfolio_folder_meta WHERE folder_id = %d",
            $folderId
        ));
    }

    private function meta(int $folderId, string $key): void
    {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}folderfolio_folder_meta", [
            'folder_id' => $folderId,
            'meta_key' => $key,
            'meta_value' => '1',
        ]);
    }

    /**
     * @test
     *
     * M13: a delete takes the folder's meta rows with it, in both branches.
     */
    public function a_delete_takes_the_folders_meta_with_it(): void
    {
        $a = $this->make('A');
        $child = $this->make('Child', $a->id);
        $b = $this->make('B');
        $keep = $this->make('Keep');

        foreach ([$a->id, $child->id, $b->id, $keep->id] as $id) {
            $this->meta($id, 'pinned');
            $this->meta($id, 'kind');
        }

        $this->assertNotWPError($this->service->delete($a->id, FolderService::CHILDREN_CASCADE));
        $this->assertNotWPError($this->service->delete($b->id, FolderService::CHILDREN_REPARENT));

        $this->assertSame(0, $this->metaCount($a->id));
        $this->assertSame(0, $this->metaCount($child->id));
        $this->assertSame(0, $this->metaCount($b->id));
        $this->assertSame(2, $this->metaCount($keep->id), 'another folder keeps its own');
    }

    /**
     * @test
     *
     * Rows an older delete left behind are reported and forgotten.
     */
    public function orphaned_meta_is_reported_and_forgotten(): void
    {
        $this->meta(987654, 'locked');

        $codes = array_column((new \FolderFolio\Database\Doctor())->check(), 'code');
        $this->assertContains('orphaned_meta', $codes);

        $this->assertGreaterThanOrEqual(1, (new \FolderFolio\Domain\AttachmentFolderRepository())->deleteOrphans());
        $this->assertSame(0, $this->metaCount(987654));
    }

    /**
     * @test
     *
     * Review L8 (here for its fixture): a media export carries the media
     * tree's assignments and no other tree's.
     */
    public function an_export_carries_only_its_own_trees_assignments(): void
    {
        $media = $this->make('Media folder');
        $posts = $this->service->create(['name' => 'Post folder', 'object_type' => 'post']);
        $file = $this->file();
        $post = self::factory()->post->create();
        $this->service->assignAttachments($media->id, [$file]);
        $this->service->assignAttachments($posts->id, [$post]);

        $document = (new \FolderFolio\Domain\FolderExport())->document(true);

        $this->assertSame([['folder' => $media->id, 'attachments' => [$file]]], $document['assignments']);
    }

    /**
     * @test
     *
     * Review L9: for a folder whose parent is gone, the repair and Doctor
     * compute the same path, so a repair clears the finding.
     */
    public function repair_and_doctor_agree_about_an_orphans_path(): void
    {
        $orphan = $this->make('Orphan');
        $child = $this->make('Its child', $orphan->id);

        global $wpdb;
        $wpdb->update("{$wpdb->prefix}folderfolio_folders", ['parent_id' => 999999], ['id' => $orphan->id]);

        $codes = static fn (): array => array_column((new \FolderFolio\Database\Doctor())->check(), 'code');

        (new Schema())->backfillPaths(true);
        $this->assertNotContains('path_drift', $codes());
        $this->assertNotContains('depth_drift', $codes());
        $this->assertSame(0, (new Schema())->backfillPaths(true), 'a second repair has nothing to do');
        $this->assertSame("/{$orphan->id}/{$child->id}/", $this->service->get($child->id)->path);
    }
}
