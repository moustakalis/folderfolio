<?php

namespace FolderFolio\Tests\Integration\Domain;

use FolderFolio\Admin\MediaLibraryFilter;
use FolderFolio\Blocks\GalleryQuery;
use FolderFolio\Database\Schema;
use FolderFolio\Domain\FolderService;
use FolderFolio\Domain\FolderSorts;
use WP_Query;
use WP_UnitTestCase;

/**
 * Manual file order inside a folder — tier 2 item 8.
 *
 * Nick's three answers (board 66d5HKiPtdtNYTf7JWWBG5): files are placed by
 * drag and by Move to start / end; placing a file in a folder not in Custom
 * order makes it Custom; a new file in a folder goes first.
 */
class FileOrderTest extends WP_UnitTestCase
{
    private FolderService $service;

    private int $folder;

    /** @var list<int> Oldest first: a, b, c, d. */
    private array $files;

    public function setUp(): void
    {
        parent::setUp();
        (new Schema())->migrate();

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_attachment_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folder_meta");

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $this->service = new FolderService();
        $this->folder = $this->service->create(['name' => 'Logos'])->id;

        // Titles run against the dates, so name order and date order differ.
        $this->files = [];
        foreach (['d-oldest', 'c', 'b', 'a-newest'] as $n => $title) {
            $this->files[] = $this->attachment($title, sprintf('2026-01-0%d 10:00:00', $n + 1));
        }
    }

    private function attachment(string $title, string $date): int
    {
        $id = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => 'image/jpeg',
            'post_title' => $title,
            'post_date' => $date,
            'post_date_gmt' => $date,
        ]);

        update_post_meta($id, '_wp_attached_file', "folderfolio-$title.jpg");
        wp_update_attachment_metadata($id, ['width' => 60, 'height' => 40, 'file' => "folderfolio-$title.jpg", 'sizes' => []]);

        return $id;
    }

    /** What the library shows for this folder, in order, as titles. */
    private function shown(): array
    {
        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            MediaLibraryFilter::QUERY_VAR => $this->folder,
        ] + MediaLibraryFilter::ordering($this->folder));

        return array_map(static fn ($post): string => $post->post_title, $query->posts);
    }

    /**
     * @test
     */
    public function new_files_go_first_in_the_order_they_were_added(): void
    {
        [$d, $c, $b, $a] = $this->files;

        $this->service->assignAttachments($this->folder, [$d, $c]);
        $this->service->assignAttachments($this->folder, [$b, $a]);

        $this->assertSame([$b, $a, $d, $c], $this->positionOrder());
    }

    /** The folder's files by position alone, the way Custom shows them. */
    private function positionOrder(): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT attachment_id FROM {$wpdb->prefix}folderfolio_attachment_folders WHERE folder_id = %d ORDER BY sort_order ASC",
            $this->folder
        )));
    }

    /**
     * The bug found reading assign(): REPLACE wrote the batch index, so Add to
     * folder on a file already there moved it.
     *
     * @test
     */
    public function adding_a_file_that_is_already_there_leaves_it_where_it_is(): void
    {
        [$d, $c, $b, $a] = $this->files;

        $this->service->assignAttachments($this->folder, [$d, $c, $b, $a]);
        $this->service->moveFiles($this->folder, [$a], 'end');
        $before = $this->positionOrder();

        $this->service->assignAttachments($this->folder, [$a, $d]);
        $this->assertSame($before, $this->positionOrder(), 'add');

        $this->service->assignAttachments($this->folder, [$a], FolderService::MODE_MOVE);
        $this->assertSame($before, $this->positionOrder(), 'move into the folder it is in');
    }

    /**
     * @test
     */
    public function placing_a_file_keeps_the_order_on_screen_and_makes_the_folder_custom(): void
    {
        [$d, $c, $b, $a] = $this->files;
        $this->service->assignAttachments($this->folder, [$d, $c, $b, $a]);
        (new FolderSorts())->set($this->folder, 'files', 'name-asc');

        $this->assertSame(['a-newest', 'b', 'c', 'd-oldest'], $this->shown());

        // d, dropped before b: the rest keep the name order they were in.
        $this->assertSame(4, $this->service->moveFiles($this->folder, [$d], 'before', $b));

        $this->assertSame('custom', (new FolderSorts())->for($this->folder)['files']);
        $this->assertSame(['a-newest', 'd-oldest', 'b', 'c'], $this->shown());
    }

    /**
     * @test
     */
    public function start_end_and_after_and_several_files_keep_their_own_order(): void
    {
        [$d, $c, $b, $a] = $this->files;
        $this->service->assignAttachments($this->folder, [$d, $c, $b, $a]);

        // No order of its own: newest first, a b c d.
        $this->assertSame(['a-newest', 'b', 'c', 'd-oldest'], $this->shown());

        // Selected d then a; they go in the order they were already in, a d.
        $this->service->moveFiles($this->folder, [$d, $a], 'end');
        $this->assertSame(['b', 'c', 'a-newest', 'd-oldest'], $this->shown());

        $this->service->moveFiles($this->folder, [$d], 'start');
        $this->assertSame(['d-oldest', 'b', 'c', 'a-newest'], $this->shown());

        $this->service->moveFiles($this->folder, [$b], 'after', $c);
        $this->assertSame(['d-oldest', 'c', 'b', 'a-newest'], $this->shown());
    }

    /**
     * @test
     */
    public function refuses_what_is_not_in_the_folder_and_writes_nothing(): void
    {
        [$d, $c, $b, $a] = $this->files;
        $this->service->assignAttachments($this->folder, [$d, $c, $b]);
        $before = $this->positionOrder();

        $this->assertSame('folderfolio_order_not_here', $this->service->moveFiles($this->folder, [$a], 'start')->get_error_code());
        $this->assertSame('folderfolio_order_anchor', $this->service->moveFiles($this->folder, [$d], 'before', $a)->get_error_code());
        $this->assertSame('folderfolio_order_anchor', $this->service->moveFiles($this->folder, [$d, $c], 'before', $c)->get_error_code());
        $this->assertSame('folderfolio_order_place', $this->service->moveFiles($this->folder, [$d], 'middle')->get_error_code());

        $this->assertSame($before, $this->positionOrder());
        $this->assertNull((new FolderSorts())->for($this->folder)['files'], 'a refusal does not make the folder Custom');
    }

    /**
     * @test
     */
    public function the_gallery_block_shows_the_folder_in_its_order(): void
    {
        [$d, $c, $b, $a] = $this->files;
        $this->service->assignAttachments($this->folder, [$d, $c, $b, $a]);
        $this->service->moveFiles($this->folder, [$c], 'start');

        $ids = static fn (array $posts): array => array_map(static fn ($post): int => $post->ID, $posts);
        $query = new GalleryQuery($this->service);

        $this->assertSame([$c, $a, $b, $d], $ids($query->attachments(['folderIds' => [$this->folder], 'orderBy' => 'folder'])));
        // The same folder by date is a different list, not the cached one.
        $this->assertSame([$a, $b, $c, $d], $ids($query->attachments(['folderIds' => [$this->folder], 'orderBy' => 'date'])));
    }

    /**
     * @test
     */
    public function arranging_needs_organise_not_only_assign(): void
    {
        [$d, $c] = $this->files;
        $this->service->assignAttachments($this->folder, [$d, $c]);

        do_action('rest_api_init');
        $request = new \WP_REST_Request('POST', "/folderfolio/v1/folders/{$this->folder}/files/order");
        $request->set_body_params(['ids' => [$d], 'place' => 'start']);

        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));
        $this->assertSame(403, rest_do_request($request)->get_status(), 'a contributor may file, not arrange');

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status());
        $this->assertSame(2, $response->get_data()['data']['placed']);
    }

    /**
     * WP_Query caches ids under its SQL and the posts' last-changed; filing
     * touches neither. Before 23 Sep the second query below returned the
     * first one's ids — the folder view and Unassigned both — and a gallery
     * kept its first list, because nothing bumped the cache key its docblock
     * said every write bumped.
     *
     * @test
     */
    public function a_filing_is_seen_by_the_next_query_in_the_same_request(): void
    {
        [$d, $c, $b] = $this->files;
        $this->service->assignAttachments($this->folder, [$d]);

        $inFolder = fn (): array => array_map('intval', (new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'fields' => 'ids',
            'posts_per_page' => -1,
            MediaLibraryFilter::QUERY_VAR => $this->folder,
        ]))->posts);
        $unassigned = fn (): array => array_map('intval', (new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'fields' => 'ids',
            'posts_per_page' => -1,
            MediaLibraryFilter::QUERY_VAR => 0,
        ]))->posts);
        $gallery = fn (): array => array_map(
            static fn ($post): int => $post->ID,
            (new GalleryQuery($this->service))->attachments(['folderIds' => [$this->folder]])
        );

        $this->assertSame([$d], $inFolder());
        $this->assertContains($c, $unassigned());
        $this->assertSame([$d], $gallery());

        $this->service->assignAttachments($this->folder, [$c]);

        $this->assertEqualsCanonicalizing([$d, $c], $inFolder());
        $this->assertNotContains($c, $unassigned());
        $this->assertEqualsCanonicalizing([$d, $c], $gallery());
    }
}
