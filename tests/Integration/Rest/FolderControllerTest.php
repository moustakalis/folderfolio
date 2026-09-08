<?php

namespace FolderFolio\Tests\Integration\Rest;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Integration tests for Folder REST API endpoints.
 *
 * These tests require WordPress test suite and database.
 */
class FolderControllerTest extends WP_UnitTestCase
{
    private int $adminUserId;

    public function setUp(): void
    {
        parent::setUp();

        // Create admin user for authenticated requests
        $this->adminUserId = $this->factory->user->create(['role' => 'administrator']);
        wp_set_current_user($this->adminUserId);
    }

    /**
     * @test
     */
    public function get_tree_returns_empty_array_when_no_folders(): void
    {
        $request = new WP_REST_Request('GET', '/folderfolio/v1/tree');
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());
        $data = $response->get_data();

        $this->assertTrue($data['success']);
        $this->assertIsArray($data['data']);
        $this->assertEmpty($data['data']);
    }

    /**
     * @test
     */
    public function create_folder_requires_name(): void
    {
        $request = new WP_REST_Request('POST', '/folderfolio/v1/folders');
        $request->set_param('name', '');

        $response = rest_do_request($request);

        $this->assertEquals(400, $response->get_status());
        $data = $response->get_data();

        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
    }

    /**
     * @test
     */
    public function create_folder_returns_created_id(): void
    {
        $request = new WP_REST_Request('POST', '/folderfolio/v1/folders');
        $request->set_param('name', 'Test Folder');

        $response = rest_do_request($request);

        $this->assertEquals(201, $response->get_status());
        $data = $response->get_data();

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('id', $data['data']);
        $this->assertIsInt($data['data']['id']);
    }

    /**
     * @test
     */
    public function delete_folder_reassigns_attachments(): void
    {
        $this->markTestIncomplete('Delete with reassignment tests to be implemented');
    }

    /**
     * @test
     */
    public function move_folder_prevents_circular_reference(): void
    {
        $this->markTestIncomplete('Circular reference prevention tests to be implemented');
    }
}
