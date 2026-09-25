<?php

namespace FolderFolio\Tests\Integration\Rest;

use FolderFolio\Database\Schema;
use FolderFolio\Domain\FolderService;
use FolderFolio\Domain\SmartFolders;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Review L3: counts and id lists say only what the person may read, and the
 * versions are for the person who runs the site.
 */
class ReadableTest extends WP_UnitTestCase
{
    private int $admin;

    public function setUp(): void
    {
        parent::setUp();
        (new Schema())->migrate();

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_attachment_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folders");

        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
    }

    /**
     * @test
     */
    public function a_smart_count_leaves_out_private_files_you_cannot_read(): void
    {
        self::factory()->attachment->create_object([
            'file' => 'secret.png',
            'post_mime_type' => 'image/png',
            'post_status' => 'private',
            'post_author' => $this->admin,
            'post_title' => 'Merger plans',
        ]);
        $rules = [['field' => 'name', 'op' => 'contains', 'value' => 'Merger']];

        $this->assertSame(1, (new SmartFolders())->count($rules));

        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
        $this->assertSame(0, (new SmartFolders())->count($rules));
        $this->assertSame([], (new SmartFolders())->ids($rules));
    }

    /**
     * @test
     */
    public function a_folders_ids_are_the_ones_you_may_read(): void
    {
        $service = new FolderService();
        $folder = $service->create(['name' => 'News', 'object_type' => 'post']);

        $contributor = self::factory()->user->create(['role' => 'contributor']);
        $theirs = self::factory()->post->create(['post_status' => 'draft', 'post_author' => $this->admin]);
        $mine = self::factory()->post->create(['post_status' => 'draft', 'post_author' => $contributor]);
        $service->assignAttachments($folder->id, [$theirs, $mine]);

        $ids = function () use ($folder): array {
            $response = rest_do_request(new WP_REST_Request('GET', "/folderfolio/v1/folders/{$folder->id}/attachments"));

            return $response->get_data()['data']['attachment_ids'];
        };

        $this->assertEqualsCanonicalizing([$theirs, $mine], $ids());

        wp_set_current_user($contributor);
        $this->assertSame([$mine], $ids());
    }

    /**
     * @test
     */
    public function health_is_for_administrators(): void
    {
        $this->assertSame(200, rest_do_request(new WP_REST_Request('GET', '/folderfolio/v1/health'))->get_status());

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->assertSame(403, rest_do_request(new WP_REST_Request('GET', '/folderfolio/v1/health'))->get_status());
    }
}
