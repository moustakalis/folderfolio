<?php

namespace FolderFolio\Tests\Integration\Domain;

use FolderFolio\Database\Schema;
use FolderFolio\Domain\Folder;
use FolderFolio\Domain\FolderPath;
use FolderFolio\Domain\FolderRepository;
use FolderFolio\Domain\FolderService;
use WP_UnitTestCase;

/**
 * Review H1: a folder must never be left with an empty path.
 *
 * Every subtree statement is `WHERE path LIKE '<path>%'`, and an empty path is
 * a prefix of every row. A folder whose second write (the path) failed was
 * left with `path = ''`, and a cascade delete of it deleted every folder on
 * the site.
 */
class FolderPathIntegrityTest extends WP_UnitTestCase
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
    }

    private function folderCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}folderfolio_folders");
    }

    /**
     * @test
     */
    public function a_failed_path_write_leaves_no_row_behind(): void
    {
        $this->service->create(['name' => 'Keep me']);
        $before = $this->folderCount();

        // Make the path UPDATE fail the way a database error would.
        $break = static function (string $sql): string {
            return str_contains($sql, 'SET `path`') ? 'SELECT broken FROM' : $sql;
        };
        global $wpdb;
        $shown = $wpdb->suppress_errors(true);
        add_filter('query', $break);
        $result = $this->service->create(['name' => 'Half made']);
        remove_filter('query', $break);
        $wpdb->suppress_errors($shown);

        $this->assertWPError($result);
        $this->assertSame($before, $this->folderCount(), 'the half-made row was taken back out');
        $this->assertSame(
            0,
            (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}folderfolio_folders WHERE path = ''")
        );
    }

    /**
     * @test
     */
    public function a_path_too_long_for_the_column_is_refused_not_truncated(): void
    {
        $before = $this->folderCount();
        $parentPath = '/' . str_repeat('9', FolderPath::MAX_LENGTH - 2) . '/';

        $result = (new FolderRepository())->create(['name' => 'Too deep'], $parentPath);

        $this->assertWPError($result);
        $this->assertSame($before, $this->folderCount());
    }

    /**
     * @test
     */
    public function the_subtree_statements_refuse_an_empty_path(): void
    {
        $this->service->create(['name' => 'A']);
        $this->service->create(['name' => 'B']);
        $repository = new FolderRepository();

        $this->assertSame([], $repository->subtreeIds(''));
        $this->assertSame([], $repository->subtree('/'));
        $this->assertWPError($repository->deleteSubtree(''));
        $this->assertWPError($repository->rewriteSubtreePaths('', '/1/', 1));
        $this->assertSame(2, $this->folderCount(), 'nothing was deleted');
    }

    /**
     * @test
     */
    public function a_cascade_delete_of_a_pathless_folder_deletes_nothing_else(): void
    {
        $keep = $this->service->create(['name' => 'Keep']);
        $this->service->create(['name' => 'Child', 'parent_id' => $keep->id]);
        $broken = $this->service->create(['name' => 'Broken']);
        $this->assertInstanceOf(Folder::class, $broken);

        // A row damaged before this fix, or by hand.
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}folderfolio_folders", ['path' => ''], ['id' => $broken->id]);
        wp_cache_set_last_changed('folderfolio');

        $this->service->delete($broken->id, FolderService::CHILDREN_CASCADE);

        $this->assertGreaterThanOrEqual(2, $this->folderCount(), 'Keep and its child are still there');
        $this->assertNotNull($this->service->get($keep->id));
    }

    /**
     * @test
     */
    public function the_depth_filter_is_held_to_what_the_column_holds(): void
    {
        $raise = static fn (): int => 500;
        add_filter('folderfolio_max_depth', $raise);

        $parent = null;
        $last = null;
        for ($depth = 0; $depth <= FolderPath::DEPTH_CEILING + 1; $depth++) {
            $last = $this->service->create(['name' => 'L' . $depth, 'parent_id' => $parent]);
            if (is_wp_error($last)) {
                break;
            }
            $parent = $last->id;
        }
        remove_filter('folderfolio_max_depth', $raise);

        $this->assertWPError($last);
        $this->assertSame('folderfolio_max_depth_exceeded', $last->get_error_code());
        $this->assertSame(FolderPath::DEPTH_CEILING + 1, $depth, 'refused one level past the ceiling');
    }
}
