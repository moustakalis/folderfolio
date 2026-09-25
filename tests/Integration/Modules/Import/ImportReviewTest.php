<?php

namespace FolderFolio\Tests\Integration\Modules\Import;

use FolderFolio\Database\Schema;
use FolderFolio\Domain\FolderKinds;
use FolderFolio\Domain\FolderService;
use FolderFolio\Modules\Import\Planner;
use FolderFolio\Modules\Import\Provenance;
use FolderFolio\Modules\Import\Run;
use FolderFolio\Modules\Import\Runner;
use FolderFolio\Modules\Import\RunStore;
use FolderFolio\Modules\Import\Source;
use FolderFolio\Modules\Import\SourceFolder;
use FolderFolio\Modules\Import\TaxonomySource;
use RuntimeException;
use WP_UnitTestCase;

/**
 * A source whose folders a test can change between batches, and which can
 * run a callback as a folder's files are read — which is inside the batch.
 */
final class ScriptedSource extends Source
{
    /** @var list<SourceFolder> */
    public static array $folders = [];

    /** @var array<int, list<int>> */
    public static array $files = [];

    /** @var (callable(int): void)|null */
    public static $during = null;

    public function __construct()
    {
        parent::__construct('scripted', 'Scripted Folders', 'scripted/scripted.php');
    }

    public function hasData(): bool
    {
        return [] !== self::$folders;
    }

    public function folderCount(): int
    {
        return count(self::$folders);
    }

    public function assignmentCount(): int
    {
        return array_sum(array_map('count', self::$files));
    }

    public function folders(): array
    {
        return self::$folders;
    }

    public function attachmentIdsFor(int $sourceFolderId): array
    {
        if (null !== self::$during) {
            (self::$during)($sourceFolderId);
        }

        return self::$files[$sourceFolderId] ?? [];
    }
}

/**
 * The importer findings of the 1.0 review: M5, M6, M7, M8, L4, L5, L6.
 */
class ImportReviewTest extends WP_UnitTestCase
{
    private FolderService $service;

    public function setUp(): void
    {
        parent::setUp();
        (new Schema())->migrate();

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_attachment_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folder_meta");

        (new RunStore())->clear();
        $this->service = new FolderService();

        ScriptedSource::$folders = [];
        ScriptedSource::$files = [];
        ScriptedSource::$during = null;

        wp_set_current_user($this->factory->user->create(['role' => 'administrator']));
        add_filter('folderfolio_import_sources', [$this, 'addSource']);
    }

    public function tearDown(): void
    {
        remove_filter('folderfolio_import_sources', [$this, 'addSource']);
        remove_all_filters('folderfolio_user_can');
        remove_all_filters('folderfolio_max_depth');
        ScriptedSource::$during = null;
        (new RunStore())->clear();

        parent::tearDown();
    }

    /**
     * @param list<Source> $sources
     * @return list<Source>
     */
    public function addSource(array $sources): array
    {
        return [...$sources, new ScriptedSource()];
    }

    /** @return list<SourceFolder> */
    private function flat(int $count, int $from = 1): array
    {
        $folders = [];

        for ($i = $from; $i < $from + $count; $i++) {
            $folders[] = new SourceFolder($i, null, sprintf('Folder %03d', $i));
        }

        return $folders;
    }

    private function finish(Runner $runner): Run
    {
        $run = $runner->step();

        for ($guard = 0; $run instanceof Run && !$run->isFinished() && $guard < 50; $guard++) {
            $run = $runner->step();
        }

        $this->assertInstanceOf(Run::class, $run);

        return $run;
    }

    /** @return list<string> */
    private function names(?int $parent = null): array
    {
        $tree = $this->service->tree();
        $names = [];
        $walk = function (array $nodes, ?int $under) use (&$walk, &$names, $parent): void {
            foreach ($nodes as $node) {
                if ($under === $parent) {
                    $names[] = (string) $node['name'];
                }
                $walk($node['children'] ?? [], (int) $node['id']);
            }
        };
        $walk($tree, null);
        sort($names);

        return $names;
    }

    private function image(): int
    {
        return self::factory()->attachment->create_object(['file' => 'a.png', 'post_mime_type' => 'image/png']);
    }

    private function pdf(): int
    {
        return self::factory()->attachment->create_object(['file' => 'a.pdf', 'post_mime_type' => 'application/pdf']);
    }

    /**
     * @test
     *
     * M5: Stop pressed while a batch runs. The batch had read the record
     * before Stop was written and saved it back as running.
     */
    public function stop_during_a_batch_is_not_lost(): void
    {
        ScriptedSource::$folders = $this->flat(20);
        $runner = new Runner();
        $runner->start(new ScriptedSource());

        ScriptedSource::$during = static function (int $id) use ($runner): void {
            if (5 === $id) {
                $runner->stop();
            }
        };

        $run = $runner->step();

        $this->assertSame(Run::DONE, $run->status, 'the run stopped');
        $this->assertLessThan(20, $run->cursor, 'before the end of the batch');
        $this->assertSame(Run::DONE, (new RunStore())->current()?->status);
    }

    /**
     * @test
     *
     * M5: one batch at a time. A step that cannot have the lock changes
     * nothing.
     */
    public function a_second_request_waits_for_the_batch_in_flight(): void
    {
        ScriptedSource::$folders = $this->flat(5);
        $runner = new Runner();
        $runner->start(new ScriptedSource());

        global $wpdb;
        $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $name = substr('folderfolio_import_' . $wpdb->prefix, 0, 64);
        $this->assertSame('1', (string) $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $name)));

        $run = $runner->step();
        $this->assertSame(0, $run->cursor, 'nothing was done while another batch held the lock');
        $this->assertSame([], $this->names());

        $other->query($other->prepare('SELECT RELEASE_LOCK(%s)', $name));
        $other->close();

        $this->assertSame(Run::DONE, $this->finish($runner)->status);
        $this->assertCount(5, $this->names());
    }

    /**
     * @test
     *
     * M6: a batch interrupted after making folders, resumed and finished,
     * then undone. The run record lost that batch's created ids; undo left
     * those folders behind.
     */
    public function undo_removes_folders_from_an_interrupted_batch(): void
    {
        ScriptedSource::$folders = $this->flat(10);
        $runner = new Runner();
        $runner->start(new ScriptedSource());

        ScriptedSource::$during = static function (int $id): void {
            if (6 === $id) {
                throw new RuntimeException('Ctrl-C');
            }
        };

        try {
            $runner->step();
            $this->fail('the batch was interrupted');
        } catch (RuntimeException $e) {
            $this->assertSame('Ctrl-C', $e->getMessage());
        }

        $this->assertCount(6, $this->names(), 'six folders were made before the interruption');

        ScriptedSource::$during = null;
        $this->assertSame(Run::DONE, $this->finish($runner)->status);
        $this->assertCount(10, $this->names());

        $undone = $runner->undo();
        $this->assertInstanceOf(Run::class, $undone);
        $this->assertSame([], $this->names(), 'every folder the run made is gone');
    }

    /**
     * @test
     *
     * M7: WordPress stores a term's name HTML-escaped.
     */
    public function a_term_name_is_imported_decoded_and_merges_into_yours(): void
    {
        register_taxonomy('ff_test_tax', 'attachment');
        wp_insert_term('Sales & Marketing', 'ff_test_tax');
        $this->service->create(['name' => 'Sales & Marketing']);

        $source = new TaxonomySource('fftest', 'Test', 'fftest/fftest.php', 'ff_test_tax');
        $this->assertSame('Sales & Marketing', $source->folders()[0]->name);

        $plan = (new Planner())->plan($source)->toArray();
        $this->assertSame(0, $plan['counts']['create']);
        $this->assertSame(1, $plan['counts']['merge']);

        unregister_taxonomy('ff_test_tax');
    }

    /**
     * @test
     *
     * M8: a name that sanitises to nothing, a name over 191 characters and a
     * folder the service refuses (too deep) no longer end the import — and
     * the plan agrees with the run.
     */
    public function one_folder_that_cannot_be_made_does_not_stop_the_import(): void
    {
        $this->service->create(['name' => 'Logos']);

        ScriptedSource::$folders = [
            new SourceFolder(1, null, '<b></b>'),
            new SourceFolder(2, null, str_repeat('x', 250)),
            new SourceFolder(3, null, 'Logos<br>'),
            new SourceFolder(4, null, 'Deep'),
            new SourceFolder(5, 4, 'Deeper'),
            new SourceFolder(6, null, 'After'),
        ];

        $plan = (new Planner())->plan(new ScriptedSource())->toArray();

        add_filter('folderfolio_max_depth', static fn (): int => 0);
        $runner = new Runner();
        $runner->start(new ScriptedSource());
        $run = $this->finish($runner);

        $this->assertSame('', $run->error, 'nothing stopped the run');
        $this->assertSame(6, $run->cursor);
        $this->assertSame(1, $run->foldersSkipped, 'Deeper, refused by the depth limit');
        $this->assertCount(1, $run->warnings);
        $this->assertStringContainsString('Deeper', $run->warnings[0]);
        $this->assertContains('After', $this->names());
        $this->assertContains('Untitled folder 1', $this->names());
        $this->assertContains(str_repeat('x', 191), $this->names());
        $this->assertSame(1, $run->foldersMerged, '"Logos<br>" is your Logos');
        $this->assertSame($plan['counts']['merge'], $run->foldersMerged);
    }

    /**
     * @test
     *
     * L4: a gallery of yours meets a source folder holding a PDF.
     */
    public function a_gallery_target_takes_the_images_and_counts_the_rest(): void
    {
        $gallery = $this->service->create(['name' => 'Logos']);
        $this->service->setKind($gallery->id, FolderKinds::GALLERY);
        $png = $this->image();
        $pdf = $this->pdf();

        ScriptedSource::$folders = [new SourceFolder(1, null, 'Logos')];
        ScriptedSource::$files = [1 => [$png, $pdf]];

        $plan = (new Planner())->plan(new ScriptedSource())->toArray();
        $this->assertSame(1, $plan['counts']['files']);
        $this->assertSame(1, $plan['counts']['not_images']);

        $runner = new Runner();
        $runner->start(new ScriptedSource());
        $run = $this->finish($runner);

        $this->assertSame('', $run->error);
        $this->assertSame(1, $run->filesAdded);
        $this->assertSame(1, $run->filesNotImages);
        $this->assertSame([$gallery->id], array_map(static fn ($f) => $f->id, $this->service->foldersOf($png)));
        $this->assertSame([], $this->service->foldersOf($pdf));
    }

    /**
     * @test
     *
     * L5: a folder deleted in the old plugin, before the cursor, mid-run.
     */
    public function a_folder_removed_before_the_cursor_skips_nothing(): void
    {
        ScriptedSource::$folders = $this->flat(30);
        $runner = new Runner();
        $runner->start(new ScriptedSource());

        $first = $runner->step();
        $this->assertSame(Runner::BATCH, $first->cursor);

        // Folder 3 goes; everything after it moves back one.
        ScriptedSource::$folders = array_values(array_filter(
            ScriptedSource::$folders,
            static fn (SourceFolder $f): bool => 3 !== $f->id
        ));

        $this->assertSame(Run::DONE, $this->finish($runner)->status);
        $this->assertContains('Folder 026', $this->names(), 'the folder that moved onto the cursor was imported');
        $this->assertCount(30, $this->names());
    }

    /**
     * @test
     *
     * L6: undo says which folders it kept and why, and fires the unfiled
     * hook for the files it took out.
     */
    public function undo_says_what_it_kept_and_fires_the_unassigned_hook(): void
    {
        $png = $this->image();
        ScriptedSource::$folders = [new SourceFolder(1, null, 'Kept'), new SourceFolder(2, null, 'Gone')];
        ScriptedSource::$files = [2 => [$png]];

        $runner = new Runner();
        $runner->start(new ScriptedSource());
        $this->finish($runner);

        $kept = null;
        foreach ($this->service->tree() as $node) {
            if ('Kept' === $node['name']) {
                $kept = (int) $node['id'];
            }
        }
        $this->assertNotWPError($this->service->mark($kept, \FolderFolio\Domain\FolderLocks::LOCKED, true));

        $heard = [];
        add_action('folderfolio_attachments_unassigned', static function ($ids, $folderId) use (&$heard): void {
            $heard[] = [$ids, $folderId];
        }, 10, 2);

        // Someone who may not pass a lock undoes it — in a request of its own,
        // as an undo is (a lock is read once per request).
        add_filter('folderfolio_user_can', static fn (bool $allowed, string $ability): bool => 'lock' === $ability ? false : $allowed, 10, 2);
        $undone = (new Runner())->undo();

        $this->assertInstanceOf(Run::class, $undone);
        $this->assertSame(['Kept'], $this->names());
        $this->assertCount(1, $undone->warnings);
        $this->assertStringContainsString('Kept', $undone->warnings[0]);
        $this->assertCount(1, $heard);
        $this->assertSame([$png], $heard[0][0]);
    }
}
