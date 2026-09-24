<?php

namespace FolderFolio\Tests\Integration\Modules\Import;

use FolderFolio\Database\Schema;
use FolderFolio\Domain\FolderExport;
use FolderFolio\Domain\FolderService;
use FolderFolio\Modules\Import\Catalog;
use FolderFolio\Modules\Import\JsonSource;
use FolderFolio\Modules\Import\Run;
use FolderFolio\Modules\Import\Runner;
use FolderFolio\Modules\Import\RunStore;
use WP_Error;
use WP_UnitTestCase;

/**
 * `wp folderfolio import` — 24 Sep. The command is a thin layer over two
 * pieces tested here: `Catalog::fromArgument()`, which turns what was typed
 * into a source, and `Runner::toEnd()`, the loop the wizard runs from the
 * browser. The command itself was run end to end with WP-CLI 2.12 in the rig
 * (record `…-24e-import-command`).
 */
class CommandLineTest extends WP_UnitTestCase
{
    /** @var list<string> */
    private array $files = [];

    public function setUp(): void
    {
        parent::setUp();
        (new Schema())->migrate();

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_attachment_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folder_meta");
        delete_option(JsonSource::OPTION);
        (new RunStore())->clear();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function file(string $contents): string
    {
        $path = get_temp_dir() . 'folderfolio-cli-' . wp_generate_password(8, false) . '.json';
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function document(int $folders): array
    {
        $rows = [];

        for ($i = 0; $i < $folders; ++$i) {
            $rows[] = ['id' => 100 + $i, 'parent_id' => null, 'name' => sprintf('Bulk %03d', $i), 'sort_order' => $i];
        }

        return ['folderfolio' => FolderExport::FORMAT, 'site' => home_url(), 'folders' => $rows, 'assignments' => []];
    }

    /** @test */
    public function a_key_a_file_and_every_refusal_say_what_they_are(): void
    {
        $unknown = Catalog::fromArgument('filebirb');
        $this->assertInstanceOf(WP_Error::class, $unknown);
        $this->assertSame('folderfolio_import_unknown_source', $unknown->get_error_code());

        $empty = Catalog::fromArgument('filebird');
        $this->assertInstanceOf(WP_Error::class, $empty);
        $this->assertSame('folderfolio_import_no_data', $empty->get_error_code());
        $this->assertStringContainsString('FileBird', $empty->get_error_message());

        $junk = Catalog::fromArgument($this->file('not json'));
        $this->assertSame('folderfolio_import_file_unreadable', $junk->get_error_code());

        $source = Catalog::fromArgument($this->file((string) wp_json_encode($this->document(3))));
        $this->assertInstanceOf(JsonSource::class, $source);
        $this->assertNotFalse(get_option(JsonSource::OPTION, false), 'stored where every batch finds it');
        $this->assertSame($source->key(), Catalog::find($source->key())?->key());
    }

    /** @test */
    public function a_file_is_not_swapped_under_a_running_import(): void
    {
        $first = Catalog::fromArgument($this->file((string) wp_json_encode($this->document(150))));
        $this->assertInstanceOf(JsonSource::class, $first);
        (new Runner())->start($first);

        $second = Catalog::fromArgument($this->file((string) wp_json_encode($this->document(2))));
        $this->assertInstanceOf(WP_Error::class, $second);
        $this->assertSame('folderfolio_import_in_progress', $second->get_error_code());
        $this->assertSame($first->key(), JsonSource::stored()?->key(), 'the running import still reads its own file');
    }

    /**
     * The no-JavaScript notice names this command — it named one that did not
     * exist until 24 Sep. Held to the registration and the subcommands.
     *
     * @test
     */
    public function the_notice_names_a_command_that_is_registered(): void
    {
        ob_start();
        (new \FolderFolio\Admin\ImportPage())->renderTab();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('wp folderfolio import', $html);

        $plugin = (string) file_get_contents(dirname(__DIR__, 4) . '/includes/Plugin.php');
        $this->assertStringContainsString("add_command('folderfolio import', ImportCommand::class)", $plugin);

        foreach (['list_', 'preview', 'run', 'resume', 'status', 'stop', 'undo'] as $method) {
            $this->assertTrue(method_exists(\FolderFolio\Cli\ImportCommand::class, $method), $method);
        }
    }

    /** @test */
    public function to_end_carries_a_run_through_every_batch(): void
    {
        $source = Catalog::fromArgument($this->file((string) wp_json_encode($this->document(150))));
        $this->assertInstanceOf(JsonSource::class, $source);

        $runner = new Runner();
        $runner->start($source);

        $ticks = [];
        $run = $runner->toEnd(static function (Run $now) use (&$ticks): void {
            $ticks[] = $now->cursor;
        });

        $this->assertInstanceOf(Run::class, $run);
        $this->assertSame(Run::DONE, $run->status);
        $this->assertSame(150, $run->foldersCreated);
        $this->assertSame([100], $ticks, 'one tick between the two batches of a 150-folder file');
        $this->assertCount(150, (new FolderService())->tree());

        // A finished run is handed back as it is — `resume` refuses one before
        // it gets here, and nothing is written twice.
        $again = (new Runner())->toEnd(static function () use (&$ticks): void {
            $ticks[] = -1;
        });
        $this->assertInstanceOf(Run::class, $again);
        $this->assertSame(150, $again->foldersCreated);
        $this->assertSame([100], $ticks);
    }
}
