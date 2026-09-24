<?php

namespace FolderFolio\Tests\Integration\Rest;

use FolderFolio\Database\Schema;
use FolderFolio\Domain\Folder;
use FolderFolio\Support\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * The routes the 24 Sep alignment audit added or reshaped: /settings and
 * /repair (items A and C), /assignments/move in place of the aliases, and
 * the import routes refusing in `{code, message}` like every other.
 */
class SettingsRoutesTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        (new Schema())->migrate();

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_attachment_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folders");
        delete_option(Settings::OPTION);

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function tearDown(): void
    {
        delete_option(Settings::OPTION);

        parent::tearDown();
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function call(string $method, string $route, ?array $body = null): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/folderfolio/v1' . $route);

        if ($body !== null) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode($body));
        }

        return rest_do_request($request);
    }

    /** @test */
    public function settings_read_and_change_for_an_administrator_only(): void
    {
        $read = $this->call('GET', '/settings');
        $this->assertSame(200, $read->get_status());
        $this->assertSame(5, $read->get_data()['data']['settings']['undo_window']);

        $changed = $this->call('POST', '/settings', ['undo_window' => 20]);
        $this->assertSame(200, $changed->get_status());
        $this->assertSame(20, $changed->get_data()['data']['settings']['undo_window']);
        $this->assertSame('inherited', $changed->get_data()['data']['settings']['count_mode'], 'the rest kept');

        $refused = $this->call('POST', '/settings', ['undo_window' => 90]);
        $this->assertSame(400, $refused->get_status());
        $this->assertSame('folderfolio_setting_invalid', $refused->get_data()['error']['code']);
        $this->assertStringContainsString('from 3 to 60', $refused->get_data()['error']['message']);

        $this->assertSame('folderfolio_setting_none', $this->call('POST', '/settings', [])->get_data()['error']['code']);

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->assertSame(403, $this->call('GET', '/settings')->get_status());
        $this->assertSame(403, $this->call('POST', '/settings', ['undo_window' => 30])->get_status());
        $this->assertSame(403, $this->call('POST', '/repair', ['tool' => 'remove-orphans'])->get_status());
        $this->assertSame(20, Settings::get()['undo_window']);
    }

    /** @test */
    public function repair_runs_the_status_tools(): void
    {
        $folder = \FolderFolio::createFolder('Kit');
        $this->assertInstanceOf(Folder::class, $folder);

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'folderfolio_attachment_folders', ['attachment_id' => 987654, 'folder_id' => $folder->id]);

        $orphans = $this->call('POST', '/repair', ['tool' => 'remove-orphans']);
        $this->assertSame(['tool' => 'remove-orphans', 'fixed' => 1], $orphans->get_data()['data']);

        $paths = $this->call('POST', '/repair', ['tool' => 'rebuild-paths']);
        $this->assertSame(0, $paths->get_data()['data']['fixed']);

        $this->assertSame(400, $this->call('POST', '/repair', ['tool' => 'defrag'])->get_status());
    }

    /** @test */
    public function assignments_move_takes_files_out_of_one_folder_only(): void
    {
        $from = \FolderFolio::createFolder('From');
        $to = \FolderFolio::createFolder('To');
        $also = \FolderFolio::createFolder('Also');
        $file = self::factory()->attachment->create(['post_mime_type' => 'image/png']);
        \FolderFolio::assign([$file], $from->id);
        \FolderFolio::assign([$file], $also->id);

        $moved = $this->call('POST', '/assignments/move', [
            'attachment_ids' => [$file],
            'source_folder_id' => $from->id,
            'folder_id' => $to->id,
        ]);

        $this->assertSame(200, $moved->get_status());
        $this->assertSame(1, $moved->get_data()['data']['moved']);
        $in = array_map(static fn (Folder $f): string => $f->name, \FolderFolio::getFoldersOf($file));
        sort($in);
        $this->assertSame(['Also', 'To'], $in);

        foreach (['/attachments/assign', '/attachments/unassign', '/attachments/bulk-move'] as $gone) {
            $this->assertSame(404, $this->call('POST', $gone, ['attachment_ids' => [$file]])->get_status(), $gone);
        }

        $this->assertSame(404, $this->call('GET', '/tree')->get_status());
    }

    /** @test */
    public function import_routes_refuse_with_a_code(): void
    {
        $unknown = $this->call('GET', '/import/filebirb/plan');
        $this->assertSame(404, $unknown->get_status());
        $this->assertSame(
            ['code' => 'folderfolio_import_unknown_source', 'message' => 'That plugin is not one FolderFolio can import from.'],
            $unknown->get_data()['error']
        );

        $empty = $this->call('GET', '/import/filebird/plan');
        $this->assertSame('folderfolio_import_no_data', $empty->get_data()['error']['code']);

        (new \FolderFolio\Modules\Import\RunStore())->clear();
        $nothing = $this->call('POST', '/import/undo');
        $this->assertSame(404, $nothing->get_status());
        $this->assertSame('folderfolio_import_nothing_to_undo', $nothing->get_data()['error']['code']);

        $junk = $this->call('POST', '/import/file', ['document' => ['not' => 'an export']]);
        $this->assertSame(400, $junk->get_status());
        $this->assertIsString($junk->get_data()['error']['code']);
        $this->assertNotSame('', $junk->get_data()['error']['message']);
    }
}
