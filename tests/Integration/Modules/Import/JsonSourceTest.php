<?php

namespace FolderFolio\Tests\Integration\Modules\Import;

use FolderFolio\Database\Schema;
use FolderFolio\Domain\FolderExport;
use FolderFolio\Domain\FolderService;
use FolderFolio\Domain\FolderKinds;
use FolderFolio\Domain\FolderSorts;
use FolderFolio\Modules\Import\Catalog;
use FolderFolio\Modules\Import\JsonSource;
use FolderFolio\Modules\Import\Runner;
use WP_Error;
use WP_UnitTestCase;

/**
 * An export file read back in — tier 1 item 6b.
 */
class JsonSourceTest extends WP_UnitTestCase
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
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folder_meta");
        delete_option(JsonSource::OPTION);
    }

    /** @return array<string, mixed> */
    private function document(string $site): array
    {
        return [
            'folderfolio' => FolderExport::FORMAT,
            'site' => $site,
            'folders' => [
                ['id' => 10, 'parent_id' => null, 'name' => 'Brand', 'color' => 'red', 'sort_order' => 0,
                 'sort_folders' => null, 'sort_files' => null],
                ['id' => 11, 'parent_id' => 10, 'name' => 'Logos', 'color' => 'moss', 'sort_order' => 2,
                 'sort_folders' => null, 'sort_files' => 'name-desc', 'kind' => 'gallery'],
            ],
            'assignments' => [['folder' => 11, 'attachments' => [999999]]],
        ];
    }

    /**
     * @test
     */
    public function refuses_what_is_not_a_format_1_export(): void
    {
        $this->assertWPError(JsonSource::fromDocument(['hello' => 'world']));
        $this->assertWPError(JsonSource::fromDocument(['folderfolio' => 2, 'folders' => []]));
        $this->assertWPError(JsonSource::fromDocument(['folderfolio' => 1, 'folders' => []]));

        $repeated = $this->document(home_url());
        $repeated['folders'][1]['id'] = 10;
        $this->assertWPError(JsonSource::fromDocument($repeated));
    }

    /**
     * @test
     */
    public function applies_assignments_only_from_this_site(): void
    {
        $here = JsonSource::fromDocument($this->document(home_url() . '/'));
        $there = JsonSource::fromDocument($this->document('https://elsewhere.example'));

        $this->assertInstanceOf(JsonSource::class, $here);
        $this->assertInstanceOf(JsonSource::class, $there);
        $this->assertSame([999999], $here->attachmentIdsFor(11));
        $this->assertSame([], $there->attachmentIdsFor(11));
        $this->assertSame(1, $there->facts()['assignments_in_file']);
        $this->assertNotSame($here->key(), $there->key(), 'two sites must never share provenance');
    }

    /**
     * @test
     */
    public function is_found_but_never_detected(): void
    {
        $document = $this->document(home_url());
        JsonSource::store($document);
        $source = JsonSource::fromDocument($document);

        $this->assertNotNull(Catalog::find($source->key()));
        $this->assertNotContains(
            $source->key(),
            array_column(Catalog::detect(), 'key')
        );
    }

    /**
     * @test
     */
    public function a_run_recreates_colours_and_orders_and_lets_go_of_the_file(): void
    {
        $document = $this->document('https://elsewhere.example');
        JsonSource::store($document);
        $source = JsonSource::fromDocument($document);

        $runner = new Runner();
        $runner->start($source);

        do {
            $run = $runner->step();
        } while (!$run->isFinished());

        $brand = $this->service->findByPath('Brand');
        $logos = $this->service->findByPath('Brand/Logos');

        $this->assertSame('red', $brand->color);
        $this->assertSame('moss', $logos->color);
        $this->assertSame('name-desc', (new FolderSorts())->for($logos->id)['files']);
        // Tier 3 item 14: a gallery comes back a gallery, and keeps the file
        // order the file carried rather than taking a new gallery's Custom.
        $this->assertTrue((new FolderKinds())->isGallery($logos->id));
        $this->assertFalse((new FolderKinds())->isGallery($brand->id));
        $this->assertFalse(get_option(JsonSource::OPTION, false), 'the stored file goes once the run is over');
    }

    /**
     * Makes the database refuse the next write of the stored file, the way a
     * `max_allowed_packet` smaller than the file does.
     */
    private function refuseTheWrite(): void
    {
        global $wpdb;

        $wpdb->suppress_errors(true);

        add_filter('query', static function (string $query): string {
            $write = preg_match('/^\s*(INSERT|UPDATE)/i', $query) === 1;

            return $write && str_contains($query, JsonSource::OPTION)
                ? 'INSERT INTO folderfolio_no_such_table VALUES (1)'
                : $query;
        });
    }

    /**
     * @test
     */
    public function store_says_whether_the_file_was_kept(): void
    {
        $document = $this->document(home_url());

        $this->assertTrue(JsonSource::store($document), 'a new file');
        // update_option() is false for an unchanged value; that is not a refusal.
        $this->assertTrue(JsonSource::store($document), 'the same file again');

        delete_option(JsonSource::OPTION);
        $this->refuseTheWrite();

        $this->assertFalse(JsonSource::store($document), 'a write the database refused');
        $this->assertNull(JsonSource::stored());
    }

    /**
     * @test
     */
    public function the_route_says_when_the_database_would_not_keep_the_file(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        do_action('rest_api_init');
        $this->refuseTheWrite();

        $request = new \WP_REST_Request('POST', '/folderfolio/v1/import/file');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode(['document' => $this->document(home_url())]));

        $response = rest_do_request($request);

        // Not a 200 with a source the next step cannot find — the bug.
        $this->assertSame(507, $response->get_status());
        $this->assertSame('folderfolio_import_file_too_large', $response->get_data()['error']['code']);
        $this->assertStringContainsString('would not keep it', (string) $response->get_data()['error']['message']);
        $this->assertFalse(get_option(JsonSource::OPTION, false));
    }

    /**
     * @param int $count
     * @return array<string, mixed>
     */
    private function wide(int $count): array
    {
        $folders = [];

        for ($i = 1; $i <= $count; $i++) {
            $folders[] = ['id' => $i, 'parent_id' => null, 'name' => "Wide $i"];
        }

        return ['folderfolio' => FolderExport::FORMAT, 'site' => home_url(), 'folders' => $folders, 'assignments' => []];
    }

    /**
     * A file re-reads the whole document every batch, so its batches are four
     * times a plugin's: 60 folders is one call, where 25 a call would be three.
     *
     * @test
     */
    public function a_file_imports_a_hundred_folders_a_call(): void
    {
        $document = $this->wide(60);
        JsonSource::store($document);
        $source = JsonSource::fromDocument($document);

        $runner = new Runner();
        $runner->start($source);
        $run = $runner->step();

        $this->assertTrue($run->isFinished(), 'one call');
        $this->assertSame(60, $run->foldersCreated);
    }

    /**
     * …and a call stops when its time is spent, whatever its count, with at
     * least one folder landed so a slow site still gets through.
     *
     * @test
     */
    public function a_file_batch_stops_when_its_time_is_spent(): void
    {
        add_filter('folderfolio_import_file_batch_seconds', static fn (): float => 0.0);

        $document = $this->wide(3);
        JsonSource::store($document);
        $source = JsonSource::fromDocument($document);

        $runner = new Runner();
        $runner->start($source);

        $calls = 0;

        do {
            $run = $runner->step();
            ++$calls;
        } while (!$run->isFinished() && $calls < 10);

        $this->assertSame(3, $calls, 'one folder a call');
        $this->assertSame(3, $run->foldersCreated);
    }
}
