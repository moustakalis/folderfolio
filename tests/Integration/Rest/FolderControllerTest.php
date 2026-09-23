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
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_attachment_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folders");

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

    /**
     * Take one ability away from an otherwise capable editor.
     *
     * Through the plugin's own filter rather than the roles matrix, so each
     * negative control below removes exactly one ability and nothing else —
     * the only way a 403 can be pinned on the ability under test rather than
     * on `upload_files`, which every role without these abilities also lacks.
     */
    private function editorWithout(string ...$abilities): void
    {
        wp_set_current_user($this->factory->user->create(['role' => 'editor']));

        add_filter(
            'folderfolio_user_can',
            static fn (bool $allowed, string $ability): bool => in_array($ability, $abilities, true) ? false : $allowed,
            10,
            2
        );
    }

    private function folder(string $name, ?int $parentId = null): int
    {
        $request = new WP_REST_Request('POST', '/folderfolio/v1/folders');
        $request->set_param('name', $name);

        if ($parentId !== null) {
            $request->set_param('parent_id', $parentId);
        }

        return (int) rest_do_request($request)->get_data()['data']['id'];
    }

    /** @param array<string, mixed> $params */
    private function post(string $route, array $params): int
    {
        $request = new WP_REST_Request('POST', '/folderfolio/v1' . $route);

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_do_request($request)->get_status();
    }

    /**
     * @test
     *
     * The capability negative controls owed since reordering shipped: each
     * route refuses a person missing its one ability, and the positive control
     * beside it proves the same request is otherwise well-formed.
     */
    public function reorder_and_sort_need_the_organise_ability(): void
    {
        $id = $this->folder('Alpha');

        $this->editorWithout('rename');
        $this->assertSame(403, $this->post('/folders/reorder', ['ids' => [$id]]));
        $this->assertSame(403, $this->post("/folders/{$id}/sort", ['scope' => 'folders', 'order' => 'name-desc']));

        remove_all_filters('folderfolio_user_can');
        $this->assertSame(200, $this->post('/folders/reorder', ['ids' => [$id]]));
        $this->assertSame(200, $this->post("/folders/{$id}/sort", ['scope' => 'folders', 'order' => 'name-desc']));
    }

    /**
     * @test
     */
    public function bulk_create_needs_the_create_ability(): void
    {
        $this->editorWithout('create');
        $this->assertSame(403, $this->post('/folders/bulk/plan', ['text' => "One\nTwo"]));
        $this->assertSame(403, $this->post('/folders/bulk', ['text' => "One\nTwo"]));

        remove_all_filters('folderfolio_user_can');
        $this->assertSame(200, $this->post('/folders/bulk/plan', ['text' => "One\nTwo"]));
    }

    /**
     * @test
     *
     * Copy + paste is create AND organise; with files it is also assign. Each
     * half is denied on its own, so a guard that checked only one of them
     * fails here by name.
     */
    public function pasting_a_copy_needs_create_and_organise_and_assign_for_files(): void
    {
        $id = $this->folder('Brand');

        $this->editorWithout('create');
        $this->assertSame(403, $this->post("/folders/{$id}/duplicate", []));

        remove_all_filters('folderfolio_user_can');
        $this->editorWithout('rename');
        $this->assertSame(403, $this->post("/folders/{$id}/duplicate", []));

        remove_all_filters('folderfolio_user_can');
        $this->editorWithout('assign');
        $this->assertSame(403, $this->post("/folders/{$id}/duplicate", ['with_files' => true]));

        // The same person may still copy the structure: assign is asked only
        // when files are asked for.
        $this->assertSame(201, $this->post("/folders/{$id}/duplicate", ['with_files' => false]));
    }

    /**
     * @test
     *
     * Star is everyone's who can see folders — an Author, with no Organise
     * and no Delete, stars — and it changes one id on the server's list,
     * never the list a bundle happens to hold. The media picker's copy was
     * never seeded, and sending it replaced every star with the one pressed.
     */
    public function star_is_one_folder_at_a_time_and_needs_no_ability(): void
    {
        $a = $this->folder('Alpha');
        $b = $this->folder('Bravo');

        wp_set_current_user($this->factory->user->create(['role' => 'author']));

        $star = function (int $id, bool $on): array {
            $request = new WP_REST_Request('POST', "/folderfolio/v1/folders/{$id}/star");
            $request->set_param('starred', $on);
            $response = rest_do_request($request);

            return [$response->get_status(), $response->get_data()['data']['stars'] ?? null];
        };

        $this->assertSame([200, [$a]], $star($a, true));
        $this->assertSame([200, [$a, $b]], $star($b, true));
        // Again is no duplicate.
        $this->assertSame([200, [$a, $b]], $star($b, true));
        $this->assertSame([200, [$b]], $star($a, false));

        // A folder that is not there cannot be starred, and can be unstarred —
        // which is how a stale star leaves the list.
        $this->assertSame(400, $star(999999, true)[0]);
        $this->assertSame([200, [$b]], $star(999999, false));

        // Someone who cannot see folders at all cannot star.
        wp_set_current_user($this->factory->user->create(['role' => 'subscriber']));
        $this->assertSame(403, $star($a, true)[0]);
    }
}
