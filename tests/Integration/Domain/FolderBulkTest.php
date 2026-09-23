<?php

namespace FolderFolio\Tests\Integration\Domain;

use FolderFolio\Database\Schema;
use FolderFolio\Domain\FolderBulk;
use FolderFolio\Domain\FolderService;
use WP_UnitTestCase;

/**
 * `Domain\FolderBulk` against a real database — a standing debt since it
 * shipped (`49fbb68`), paid with tier 2 item 9, which made it the engine of
 * folder upload as well as the Tools tab's list.
 */
class FolderBulkTest extends WP_UnitTestCase
{
    private FolderBulk $bulk;
    private FolderService $service;

    public function setUp(): void
    {
        parent::setUp();
        (new Schema())->migrate();
        $this->bulk = new FolderBulk();
        $this->service = new FolderService();

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_attachment_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folders");
    }

    /**
     * Every row says which folder its line ended at — what folder upload files
     * each dropped file into, by id.
     *
     * @test
     */
    public function run_returns_the_folder_each_line_ends_at(): void
    {
        $result = $this->bulk->run("Brand/Logos\nBrand\n\nBrand/Icons/Line");
        $this->assertIsArray($result);

        $ids = array_column($result['rows'], 'folder_id');
        $this->assertCount(3, $ids, 'one row per line, the blank one excepted');

        $this->assertSame($this->service->findByPath('Brand/Logos')?->id, $ids[0]);
        $this->assertSame($this->service->findByPath('Brand')?->id, $ids[1]);
        $this->assertSame($this->service->findByPath('Brand/Icons/Line')?->id, $ids[2]);
        $this->assertSame(4, $result['counts']['folders']);
    }

    /**
     * Under a parent, and a second time: the same ids, nothing new.
     *
     * With a name the sanitiser changes — a directory off a disk can be
     * called anything — which is the case `getOrCreateByPath()` got wrong.
     *
     * @test
     */
    public function run_twice_under_a_parent_finds_what_the_first_made(): void
    {
        $parent = $this->service->create(['name' => 'Drops']);
        $this->assertNotWPError($parent);

        $first = $this->bulk->run("Shoot  2024/Raw\nShoot  2024", $parent->id);
        $second = $this->bulk->run("Shoot  2024/Raw\nShoot  2024", $parent->id);
        $this->assertIsArray($first);
        $this->assertIsArray($second);

        $this->assertSame(
            array_column($first['rows'], 'folder_id'),
            array_column($second['rows'], 'folder_id')
        );
        $this->assertSame(0, $second['counts']['folders']);
        $this->assertSame(2, $second['counts']['unchanged']);
        $this->assertSame('Shoot 2024', $this->service->findByPath('Drops/Shoot 2024')?->name);
    }

    /**
     * One line past the nesting limit refuses the list, and writes nothing.
     *
     * The plan route marks the line with the reason, which is the sentence
     * folder upload shows; the create route refuses the whole submission.
     *
     * @test
     */
    public function one_line_too_deep_refuses_the_whole_list(): void
    {
        $deep = implode('/', array_map(static fn (int $n): string => 'L' . $n, range(1, 30)));

        $plan = $this->bulk->plan("Fine\n" . $deep);
        $this->assertIsArray($plan);
        $this->assertNull($plan['rows'][0]['error']);
        $this->assertIsString($plan['rows'][1]['error']);

        $this->assertWPError($this->bulk->run("Fine\n" . $deep));
        $this->assertSame([], $this->service->tree(), 'not even the line that was fine');
    }
}
