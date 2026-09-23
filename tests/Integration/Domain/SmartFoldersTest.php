<?php

namespace FolderFolio\Tests\Integration\Domain;

use FolderFolio\Admin\MediaLibraryFilter;
use FolderFolio\Database\Schema;
use FolderFolio\Domain\Folder;
use FolderFolio\Domain\FolderService;
use FolderFolio\Domain\SmartFolders;
use FolderFolio\Domain\SmartRules;
use FolderFolio\Support\FileSizes;
use WP_Query;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Smart folders — tier 3 item 13. Saved rules, every rule must match, the
 * site's (Organise to make), media's rules first.
 */
class SmartFoldersTest extends WP_UnitTestCase
{
    private SmartFolders $smart;

    /** @var array<string, int> */
    private array $files = [];

    private int $me;

    private int $other;

    public function setUp(): void
    {
        parent::setUp();
        (new Schema())->migrate();
        delete_option(SmartFolders::OPTION);

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_attachment_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folders");

        $this->smart = new SmartFolders();
        $this->me = self::factory()->user->create(['role' => 'administrator']);
        $this->other = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($this->me);

        $today = current_datetime()->format('Y-m-d H:i:s');
        $old = current_datetime()->modify('-90 days')->format('Y-m-d H:i:s');

        $make = function (string $key, string $mime, string $date, int $author, string $title, int $bytes): void {
            $id = self::factory()->attachment->create([
                'post_mime_type' => $mime,
                'post_date' => $date,
                'post_author' => $author,
                'post_title' => $title,
            ]);
            FileSizes::store($id, ['filesize' => $bytes]);
            $this->files[$key] = $id;
        };

        $make('photo', 'image/jpeg', $today, $this->me, 'Beach photo', 2_000_000);
        $make('oldphoto', 'image/png', $old, $this->other, 'Archive scan', 9_000_000);
        $make('clip', 'video/mp4', $today, $this->other, 'Launch clip', 80_000_000);
        $make('brief', 'application/pdf', $old, $this->me, 'Brand brief', 300_000);
        $make('notes', 'text/plain', $today, $this->me, 'Meeting notes', 1_000);

        update_option('folderfolio_filesizes_checked', time(), false);
    }

    /**
     * @param list<array{field: string, op: string, value: int|string}> $rules
     * @return list<string>
     */
    private function match(array $rules): array
    {
        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'fields' => 'ids',
            'posts_per_page' => -1,
            MediaLibraryFilter::SMART_RULES_VAR => SmartRules::sanitize($rules, 'attachment'),
        ]);

        $names = array_flip($this->files);
        $out = array_map(static fn ($id): string => $names[(int) $id] ?? "#{$id}", $query->posts);
        sort($out);

        return $out;
    }

    /** @test */
    public function each_rule_narrows_and_all_of_them_must_match(): void
    {
        $this->assertSame(['oldphoto', 'photo'], $this->match([['field' => 'type', 'op' => 'is', 'value' => 'image']]));
        $this->assertSame(['brief', 'notes'], $this->match([['field' => 'type', 'op' => 'is', 'value' => 'document']]));
        $this->assertSame(['brief', 'clip', 'notes'], $this->match([['field' => 'type', 'op' => 'is_not', 'value' => 'image']]));
        $this->assertSame(['clip', 'notes', 'photo'], $this->match([['field' => 'date', 'op' => 'last', 'value' => 30]]));
        $this->assertSame(['brief', 'oldphoto'], $this->match([['field' => 'date', 'op' => 'before', 'value' => current_datetime()->modify('-30 days')->format('Y-m-d')]]));
        $this->assertSame(['brief', 'notes', 'photo'], $this->match([['field' => 'author', 'op' => 'is', 'value' => 'me']]));
        $this->assertSame(['clip', 'oldphoto'], $this->match([['field' => 'author', 'op' => 'is', 'value' => $this->other]]));
        $this->assertSame(['clip', 'oldphoto'], $this->match([['field' => 'size', 'op' => 'gt', 'value' => 5_000_000]]));
        $this->assertSame(['brief', 'notes'], $this->match([['field' => 'size', 'op' => 'lt', 'value' => 1_000_000]]));
        $this->assertSame(['brief'], $this->match([['field' => 'name', 'op' => 'contains', 'value' => 'brief']]));

        // All of them: this month's images of mine.
        $this->assertSame(['photo'], $this->match([
            ['field' => 'type', 'op' => 'is', 'value' => 'image'],
            ['field' => 'date', 'op' => 'last', 'value' => 30],
            ['field' => 'author', 'op' => 'is', 'value' => 'me'],
        ]));
    }

    /** @test */
    public function uploaded_by_me_means_whoever_is_looking(): void
    {
        $rules = [['field' => 'author', 'op' => 'is', 'value' => 'me']];

        wp_set_current_user($this->other);
        $this->assertSame(['clip', 'oldphoto'], $this->match($rules));
    }

    /** @test */
    public function filed_means_in_a_folder_and_a_folder_means_its_subtree(): void
    {
        $service = new FolderService();
        $brand = $service->create(['name' => 'Brand']);
        $logos = $service->create(['name' => 'Logos', 'parent_id' => $brand->id]);
        $this->assertInstanceOf(Folder::class, $logos);

        $service->assignAttachments($logos->id, [$this->files['photo']]);
        $service->assignAttachments($brand->id, [$this->files['brief']]);

        $this->assertSame(['clip', 'notes', 'oldphoto'], $this->match([['field' => 'filed', 'op' => 'none', 'value' => '']]));
        $this->assertSame(['brief', 'photo'], $this->match([['field' => 'filed', 'op' => 'any', 'value' => '']]));
        $this->assertSame(['brief', 'photo'], $this->match([['field' => 'filed', 'op' => 'in', 'value' => $brand->id]]));
        $this->assertSame(['photo'], $this->match([['field' => 'filed', 'op' => 'in', 'value' => $logos->id]]));

        // Unfiled images: what a plain folder cannot show.
        $this->assertSame(['oldphoto'], $this->match([
            ['field' => 'type', 'op' => 'is', 'value' => 'image'],
            ['field' => 'filed', 'op' => 'none', 'value' => ''],
        ]));
    }

    /** @test */
    public function rules_are_cleaned_and_a_folder_with_none_is_refused(): void
    {
        $this->assertSame([], SmartRules::sanitize([
            ['field' => 'colour', 'op' => 'is', 'value' => 'red'],
            ['field' => 'type', 'op' => 'is', 'value' => 'spreadsheet'],
            ['field' => 'date', 'op' => 'after', 'value' => '2026-02-30'],
            ['field' => 'size', 'op' => 'eq', 'value' => 10],
            'nonsense',
        ], 'attachment'));
        $this->assertSame([], SmartRules::sanitize([['field' => 'type', 'op' => 'is', 'value' => 'image']], 'page'), 'no rules for pages yet');

        $none = $this->smart->create('Empty', 'attachment', []);
        $this->assertWPError($none);
        $this->assertSame('folderfolio_smart_no_rules', $none->get_error_code());

        $made = $this->smart->create('Images', 'attachment', [['field' => 'type', 'op' => 'is', 'value' => 'image']]);
        $this->assertIsArray($made);
        $this->assertSame(2, $this->smart->count($made['rules']));

        $twice = $this->smart->create('images', 'attachment', [['field' => 'type', 'op' => 'is', 'value' => 'video']]);
        $this->assertWPError($twice);
        $this->assertSame('folderfolio_duplicate_name', $twice->get_error_code());

        $renamed = $this->smart->update($made['id'], 'Pictures', null);
        $this->assertSame('Pictures', $renamed['name']);
        $this->assertSame($made['rules'], $renamed['rules']);

        $this->assertTrue($this->smart->delete($made['id']));
        $this->assertWPError($this->smart->delete($made['id']));
    }

    /** @test */
    public function the_library_filters_by_a_saved_smart_folder_and_a_gone_one_matches_nothing(): void
    {
        $made = $this->smart->create('Videos', 'attachment', [['field' => 'type', 'op' => 'is', 'value' => 'video']]);

        $query = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', MediaLibraryFilter::SMART_VAR => $made['id']]);
        $this->assertSame([$this->files['clip']], array_map('intval', $query->posts));

        $this->smart->delete($made['id']);
        $query = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', MediaLibraryFilter::SMART_VAR => $made['id']]);
        $this->assertSame([], $query->posts);
    }

    /** @test */
    public function without_a_smart_folder_the_clauses_are_untouched(): void
    {
        $captured = [];
        $spy = static function (array $clauses) use (&$captured): array {
            $captured[] = $clauses;

            return $clauses;
        };

        add_filter('posts_clauses', $spy, 5);
        new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids']);
        remove_filter('posts_clauses', $spy, 5);

        $before = $captured[0];
        $after = (new MediaLibraryFilter())->joinFolderAssignments($before, new WP_Query());

        $this->assertSame($before, $after);
    }

    /** @test */
    public function a_size_rule_fills_in_sizes_the_index_does_not_have(): void
    {
        delete_post_meta($this->files['clip'], FileSizes::META);
        delete_option('folderfolio_filesizes_checked');
        wp_update_attachment_metadata($this->files['clip'], ['filesize' => 70_000_000]);

        // Written on the metadata write already; delete again to test the backfill.
        delete_post_meta($this->files['clip'], FileSizes::META);
        $this->assertTrue(FileSizes::backfill());
        $this->assertSame('70000000', get_post_meta($this->files['clip'], FileSizes::META, true));
    }

    /** @test */
    public function reading_is_use_and_making_is_organise(): void
    {
        // An Author uploads files but cannot Organise by default.
        wp_set_current_user(self::factory()->user->create(['role' => 'author']));

        $list = new WP_REST_Request('GET', '/folderfolio/v1/smart');
        $this->assertSame(200, rest_do_request($list)->get_status());

        $create = new WP_REST_Request('POST', '/folderfolio/v1/smart');
        $create->set_body_params(['name' => 'Mine', 'rules' => [['field' => 'author', 'op' => 'is', 'value' => 'me']]]);
        $this->assertSame(403, rest_do_request($create)->get_status());

        wp_set_current_user($this->other);
        $made = rest_do_request($create);
        $this->assertSame(201, $made->get_status());
        $this->assertSame(2, $made->get_data()['data']['count'], 'uploaded by the editor asking');

        $preview = new WP_REST_Request('POST', '/folderfolio/v1/smart/preview');
        $preview->set_body_params(['rules' => [['field' => 'type', 'op' => 'is', 'value' => 'image']]]);
        $this->assertSame(2, rest_do_request($preview)->get_data()['data']['count']);

        $index = rest_do_request($list)->get_data()['data'];
        $this->assertSame(['Mine'], array_column($index, 'name'));
    }
}
