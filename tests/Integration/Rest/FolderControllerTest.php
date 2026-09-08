<?php

namespace FolderFolio\Tests\Integration\Rest;

use FolderFolio\Database\Schema;
use WP_REST_Request;
use WP_UnitTestCase;

class FolderControllerTest extends WP_UnitTestCase
{
    private int $adminUserId;

    public function setUp(): void
    {
        parent::setUp();
        (new Schema())->migrate();

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}folderfolio_attachment_folders");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}folderfolio_folders");

        $this->adminUserId = $this->factory->user->create(['role' => 'administrator']);
        wp_set_current_user($this->adminUserId);
    }

    /**
     * @test
     */
    public function tree_returns_empty_array(): void
    {
        $response = rest_do_request(new WP_REST_Request('GET', '/folderfolio/v1/tree'));
        $this->assertSame(200, $response->get_status());
        $this->assertTrue($response->get_data()['success']);
    }

    /**
     * @test
     */
    public function create_folder_requires_name(): void
    {
        $request = new WP_REST_Request('POST', '/folderfolio/v1/folders');
        $request->set_param('name', '');
        $response = rest_do_request($request);
        $this->assertSame(400, $response->get_status());
    }

    /**
     * @test
     */
    public function admin_can_create_folder(): void
    {
        $request = new WP_REST_Request('POST', '/folderfolio/v1/folders');
        $request->set_param('name', 'Test');
        $response = rest_do_request($request);
        $this->assertSame(201, $response->get_status());
    }

    /**
     * @test
     */
    public function health_endpoint_works(): void
    {
        $response = rest_do_request(new WP_REST_Request('GET', '/folderfolio/v1/health'));
        $this->assertSame(200, $response->get_status());
        $this->assertTrue($response->get_data()['success']);
    }
}
