<?php

namespace FolderFolio\Tests\Integration\Modules\Import;

use FolderFolio\Database\Schema;
use FolderFolio\Modules\Import\Planner;
use FolderFolio\Modules\Import\Run;
use FolderFolio\Modules\Import\Runner;
use FolderFolio\Modules\Import\RunStore;
use FolderFolio\Modules\Import\Source;
use FolderFolio\Modules\Import\SourceFolder;
use WP_UnitTestCase;

/**
 * A source plugin that does not exist.
 *
 * The readers are tested against the real thing — FileBird, CatFolders, Real
 * Media Library and Premio's Folders are all installed on the development
 * site, and the schemas in `Catalog` were read from their `CREATE TABLE`s. But
 * a test suite cannot install four competitors, and the interesting behaviour
 * is not in the readers anyway: it is in what the planner predicts, whether
 * the run matches that prediction, and whether undo puts the library back.
 *
 * So this stands in for all of them. It holds the shapes that matter — a
 * nested tree, two siblings of one name, a file that no longer exists — and
 * none of the SQL.
 */
final class FakeSource extends Source
{
    /**
     * @param list<SourceFolder>    $folders
     * @param array<int, list<int>> $files source folder id => attachment ids
     */
    public function __construct(
        private readonly array $folders,
        private readonly array $files,
        string $key = 'fake'
    ) {
        // The key is a parameter because the runner re-reads its source from
        // the catalogue every batch, by key — two fixtures sharing one key
        // means the second run reads the first fixture's folders.
        parent::__construct($key, 'Fake Folders', 'fake/fake.php');
    }

    public function hasData(): bool
    {
        return [] !== $this->folders;
    }

    public function folderCount(): int
    {
        return count($this->folders);
    }

    public function assignmentCount(): int
    {
        return array_sum(array_map('count', $this->files));
    }

    public function folders(): array
    {
        return $this->folders;
    }

    public function attachmentIdsFor(int $sourceFolderId): array
    {
        return $this->files[$sourceFolderId] ?? [];
    }
}

/**
 * The preview, the run, and the way back.
 *
 * The assertion that matters most is the one that compares a plan to the run
 * that follows it. A preview is a promise, and the first time these two were
 * compared on real data the plan said "35 folders created" and the run
 * reported "26 created, 9 merged" — a divergence that was not a bug in the
 * run, and was a bug in the promise.
 */
class ImportTest extends WP_UnitTestCase
{
    private int $attachment;

    public function setUp(): void
    {
        parent::setUp();

        (new Schema())->migrate();

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_attachment_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folder_meta");

        delete_option(RunStore::OPTION);

        wp_set_current_user($this->factory->user->create(['role' => 'administrator']));

        $this->attachment = $this->factory->post->create(['post_type' => 'attachment']);

        // The runner re-reads its source from the catalogue every batch, so a
        // source that is not in the catalogue cannot be run. The filter that
        // lets a site add an importer of its own is what lets a test add one.
        add_filter('folderfolio_import_sources', [$this, 'addFakeSource']);
    }

    /**
     * @param list<Source> $sources
     *
     * @return list<Source>
     */
    public function addFakeSource(array $sources): array
    {
        return [...$sources, $this->source()];
    }

    public function tearDown(): void
    {
        remove_filter('folderfolio_import_sources', [$this, 'addFakeSource']);
        delete_option(RunStore::OPTION);

        parent::tearDown();
    }

    private function source(): FakeSource
    {
        return new FakeSource(
            [
                new SourceFolder(1, null, 'Brand'),
                new SourceFolder(2, 1, 'Logos'),
                // The same name, in the same place, as folder 2. Every source
                // examined permits this in its tables even where its own UI
                // does not.
                new SourceFolder(3, 1, 'Logos'),
                new SourceFolder(4, null, 'Campaigns'),
            ],
            [
                2 => [$this->attachment],
                // 404 is not an attachment: the pair tables have no foreign
                // keys, so every source names files that were deleted years
                // ago.
                3 => [$this->attachment, 404],
                4 => [404],
            ]
        );
    }

    private function runToCompletion(Runner $runner): Run
    {
        $run = $runner->step();

        $guard = 0;

        while ($run instanceof Run && !$run->isFinished() && ++$guard < 50) {
            $run = $runner->step();
        }

        $this->assertInstanceOf(Run::class, $run);

        return $run;
    }

    /**
     * @test
     */
    public function the_plan_predicts_exactly_what_the_run_does(): void
    {
        $source = $this->source();

        $plan = (new Planner())->plan($source)->toArray();

        $this->assertSame(3, $plan['counts']['create']);
        $this->assertSame(1, $plan['counts']['duplicate'], 'Two folders called Logos under Brand become one.');
        $this->assertSame(0, $plan['counts']['merge']);
        $this->assertSame(1, $plan['counts']['files'], 'One real attachment, however many folders name it.');
        $this->assertSame(1, $plan['counts']['skipped']);

        $runner = new Runner();
        $runner->start($source);
        $run = $this->runToCompletion($runner);

        $this->assertSame($plan['counts']['create'], $run->foldersCreated);
        $this->assertSame($plan['counts']['merge'], $run->foldersMerged);
        $this->assertSame($plan['counts']['duplicate'], $run->duplicatesCollapsed);
        $this->assertSame($plan['counts']['files'], $run->filesAdded);
    }

    /**
     * @test
     */
    public function a_folder_that_already_exists_is_merged_into_rather_than_duplicated(): void
    {
        $existing = \FolderFolio::createFolder('Brand');

        $this->assertNotWPError($existing);

        $plan = (new Planner())->plan($this->source())->toArray();

        $this->assertSame(1, $plan['counts']['merge']);
        $this->assertSame(['Brand'], $plan['samples']['merge']);
        $this->assertSame(2, $plan['counts']['create'], 'Brand is not created twice.');
    }

    /**
     * @test
     */
    public function running_it_twice_reconciles_instead_of_duplicating(): void
    {
        $source = $this->source();

        $runner = new Runner();
        $runner->start($source);
        $this->runToCompletion($runner);

        $before = count(\FolderFolio::getTree());

        // The case a per-source "already imported" flag gets wrong: the user
        // kept using the old plugin and wants what they have added since.
        $plan = (new Planner())->plan($source)->toArray();

        $this->assertSame(0, $plan['counts']['create']);
        // Four, not three: both same-named siblings landed in one folder, and
        // provenance records both links because its key carries the source id
        // rather than its value.
        $this->assertSame(4, $plan['counts']['reconcile']);
        $this->assertSame(0, $plan['counts']['merge']);
        $this->assertSame(0, $plan['counts']['files']);
        $this->assertSame(1, $plan['counts']['already']);

        delete_option(RunStore::OPTION);

        $runner = new Runner();
        $runner->start($source);
        $this->runToCompletion($runner);

        $this->assertCount($before, \FolderFolio::getTree(), 'A second run adds no folders.');
    }

    /**
     * @test
     */
    public function provenance_survives_the_folder_being_renamed(): void
    {
        $source = $this->source();

        $runner = new Runner();
        $runner->start($source);
        $this->runToCompletion($runner);

        $tree = \FolderFolio::getTree();
        $brand = null;

        foreach ($tree as $node) {
            if ('Brand' === $node['name']) {
                $brand = (int) $node['id'];
            }
        }

        $this->assertNotNull($brand);

        \FolderFolio::renameFolder($brand, 'Marque');

        $plan = (new Planner())->plan($source)->toArray();

        // Matched on where it came from, not on what it is called now — which
        // is the whole reason provenance is a pair of meta rows rather than a
        // name comparison.
        $this->assertSame(4, $plan['counts']['reconcile']);
        $this->assertSame(0, $plan['counts']['create']);
    }

    /**
     * @test
     */
    public function undo_removes_what_the_import_made_and_keeps_what_it_did_not(): void
    {
        $mine = \FolderFolio::createFolder('Campaigns');
        $this->assertNotWPError($mine);

        $ownFile = $this->factory->post->create(['post_type' => 'attachment']);
        \FolderFolio::assign([$ownFile], $mine->id);

        $runner = new Runner();
        $runner->start($this->source());
        $this->runToCompletion($runner);

        $runner = new Runner();
        $run = $runner->undo();

        $this->assertInstanceOf(Run::class, $run);
        $this->assertSame(Run::UNDONE, $run->status);

        $names = array_column(\FolderFolio::getTree(), 'name');

        $this->assertSame(['Campaigns'], $names, 'Only the folder that was here first survives.');
        $this->assertSame(
            [$ownFile],
            \FolderFolio::getAttachmentIds($mine->id),
            'A file filed by hand before the import is not an import artefact.'
        );
    }

    /**
     * The case the `import_run` column exists for.
     *
     * Screen 07 says the run continues after you close the tab, so the user is
     * invited to go back to the media library and carry on working — into the
     * very folders the import is merging into — while it runs. Undo used to
     * delete assignments by (folder, time window), which cannot tell that row
     * from one of its own.
     *
     * Thirty folders, so that one batch of twenty-five leaves the run going.
     *
     * @test
     */
    public function undo_keeps_a_file_somebody_filed_while_the_import_was_running(): void
    {
        $folders = [];

        for ($i = 1; $i <= 30; $i++) {
            $folders[] = new SourceFolder($i, null, 'Folder ' . $i);
        }

        $source = new FakeSource($folders, [1 => [$this->attachment]], 'fake-wide');

        add_filter(
            'folderfolio_import_sources',
            static fn (array $sources): array => [...$sources, $source]
        );

        $runner = new Runner();
        $runner->start($source);

        $run = $runner->step();

        $this->assertInstanceOf(Run::class, $run);
        $this->assertFalse($run->isFinished(), 'One batch of 25 leaves five folders to go.');

        $first = null;

        foreach (\FolderFolio::getTree() as $node) {
            if ('Folder 1' === $node['name']) {
                $first = (int) $node['id'];
            }
        }

        $this->assertNotNull($first);

        // Somebody drags a file into a folder the import has already made,
        // while the rest of the import is still running.
        $theirs = $this->factory->post->create(['post_type' => 'attachment']);
        \FolderFolio::assign([$theirs], $first);

        $this->runToCompletion($runner);

        $undone = (new Runner())->undo();

        $this->assertInstanceOf(Run::class, $undone);
        $this->assertContains(
            'Folder 1',
            array_column(\FolderFolio::getTree(), 'name'),
            'A folder the import made is kept when somebody has filed into it.'
        );
        $this->assertSame(
            [$theirs],
            \FolderFolio::getAttachmentIds($first),
            'Their file stays; the one the import filed there is gone.'
        );
    }

    /**
     * @test
     */
    public function stopping_leaves_a_finished_run_rather_than_a_half_made_tree(): void
    {
        $runner = new Runner();
        $runner->start($this->source());

        $runner->stop();

        $run = $runner->step();

        $this->assertInstanceOf(Run::class, $run);
        $this->assertSame(Run::DONE, $run->status);
        $this->assertTrue($run->toArray()['can_undo']);
    }

    /**
     * @test
     */
    public function two_runs_cannot_be_in_flight_at_once(): void
    {
        $runner = new Runner();
        $runner->start($this->source());

        $second = $runner->start($this->source());

        $this->assertWPError($second);
        $this->assertSame('folderfolio_import_in_progress', $second->get_error_code());
    }
}
