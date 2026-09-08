<?php

namespace FolderFolio\Tests\Integration\Rest;

use FolderFolio\Database\Schema;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Integration tests for the FolderFolio REST API.
 */
class FolderControllerTest extends WP_UnitTestCase
{
    private int $adminUserId;
    private int $subscriberUserId;

    public function setUp(): void
    {
        parent::setUp();
        (new Schema())->migrate();

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}folderfolio_attachment_folders");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}folderfolio_folders");

        $this->adminUserId = $this->factory->user->create(['role' => 'administrator']);
        $this->subscriberUserId = $this->factory->user->create(['role' => 'subscriber']);
        wp_set_current_user($this->adminUserId);
    }

    /**
     * @test
     */
    public function tree_endpoint_returns_empty_array_for_new_install(): void
    {
        $response = rest_do_request(new WP_REST_Request('GET', '/folderfolio/v1/tree'));

        $this->assertSame(200, $response->get_status());
        $payload = $response->get_data();
        $this->assertTrue($payload['success']);
        $this->assertSame([], $payload['data']);
    }

    /**
     * @test
     */
    public function unauthenticated_user_cannot_access_tree(): void
    {
        wp_set_current_user(0);
        $response = rest_do_request(new WP_REST_Request('GET', '/folderfolio/v1/tree'));

        $this->assertSame(401, $response->get_status());
    }

    /**
     * @test
     */
    public function subscriber_cannot_manage_folders(): void
    {
        wp_set_current_user($this->subscriberUserId);
        $request = new WP_REST_Request('POST', '/folderfolio/v1/folders');
        $request->set_param('name', 'Not allowed');
        $response = rest_do_request($request);

        $this->assertSame(403, $response->get_status());
    }

    /**
     * @test
     */
    public function admin_can_create_and_read_folder_tree(): void
    {
        $create = new WP_REST_Request('POST', '/folderfolio/v1/folders');
        $create->set_param('name', 'Campaign Assets');
        $create->set_param('color', '#3047A8');
        $created = rest_do_request($create);

        $this->assertSame(201, $created->get_status());
        $createdPayload = $created->get_data();
        $folderId = $createdPayload['data']['id'];

        $tree = rest_do_request(new WP_REST_Request('GET', '/folderfolio/v1/tree'));
        $treePayload = $tree->get_data();

        $this->assertCount(1, $treePayload['data']);
        $this->assertSame($folderId, $treePayload['data'][0]['id']);
        $this->assertSame('Campaign Assets', $treePayload['data'][0]['name']);
    }

    /**
     * @test
     */
    public function admin_can_update_folder(): void
    {
        $create = new WP_REST_Request('POST', '/folderfolio/v1/folders');
        $create->set_param('name', 'Before');
        $folderId = rest_do_request($create)->get_data()['data']['id'];

        $update = new WP_REST_Request('PATCH', '/folderfolio/v1/folders/' . $folderId);
        $update->set_param('name', 'After');
        $response = rest_do_request($update);

        $this->assertSame(200, $response->get_status());
        $tree = rest_do_request(new WP_REST_Request('GET', '/folderfolio/v1/tree'))->get_data();
        $this->assertSame('After', $tree['data'][0]['name']);
    }

    /**
     * @test
     */
    public function admin_can_assign_and_unassign_attachment(): void
    {
        $create = new WP_REST_Request('POST', '/folderfolio/v1/folders');
        $create->set_param('name', 'Images');
        $folderId = rest_do_request($create)->get_data()['data']['id'];
        $attachmentId = $this->factory->post->create(['post_type' => 'attachment']);

        $assign = new WP_REST_Request('POST', '/folderfolio/v1/attachments/assign');
        $assign->set_param('folder_id', $folderId);
        $assign->set_param('attachment_ids', [$attachmentId]);
        $this->assertSame(200, rest_do_request($assign)->get_status());

        $attachments = rest_do_request(new WP_REST_Request('GET', '/folderfolio/v1/folders/' . $folderId . '/attachments'));
        $this->assertContains($attachmentId, $attachments->get_data()['data']['attachment_ids']);

        $unassign = new WP_REST_Request('POST', '/folderfolio/v1/attachments/unassign');
        $unassign->set_param('folder_id', $folderId);
        $unassign->set_param('attachment_ids', [$attachmentId]);
        $this->assertSame(200, rest_do_request($unassign)->get_status());

        $attachments = rest_do_request(new WP_REST_Request('GET', '/folderfolio/v1/folders/' . $folderId . '/attachments'));
        $this->assertSame([], $attachments->get_data()['data']['attachment_ids']);
    }

    /**
     * @test
     */
    public function health_endpoint_returns_runtime_information(): void
    {
        $response = rest_do_request(new WP_REST_Request('GET', '/folderfolio/v1/health'));
        $payload = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($payload['success']);
        $this->assertSame('ok', $payload['data']['status']);
        $this->assertArrayHasKey('wordpress', $payload['data']);
        $this->assertArrayHasKey('php', $payload['data']);
    }
}
