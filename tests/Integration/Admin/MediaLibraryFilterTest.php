<?php

namespace FolderFolio\Tests\Integration\Admin;

use FolderFolio\Admin\MediaLibraryFilter;
use WP_Query;
use WP_UnitTestCase;

/**
 * The bail, which is the one claim in the readme a competitor cannot match.
 *
 * `posts_clauses` is registered unconditionally, on every query on the site —
 * the REST media endpoints, a theme's own `WP_Query`, core's own — and it has
 * never had a test saying it leaves them alone. Every existing test here sets
 * the query var first, so the whole suite would stay green if the guard were
 * deleted and we started joining our assignments table into everything.
 *
 * That is not hypothetical: read on 21 Sep, three of the four plugins on the
 * market do exactly this. FileBird goes further and forces its remembered
 * folder onto `upload.php` with no user action, which on the dev site hid 28
 * of 47 files the moment it was activated, with nothing on screen to say so.
 * Ours bails, and *"FolderFolio never filters your media library unless you
 * pick a folder"* is only true while these assertions pass.
 *
 * `assertSame` on the whole array, not a search for our table name: a filter
 * that added a harmless-looking `ORDER BY` would pass a substring check.
 */
class MediaLibraryFilterTest extends WP_UnitTestCase
{
    private MediaLibraryFilter $filter;

    /** @var array<string, string> */
    private array $clauses;

    public function setUp(): void
    {
        parent::setUp();

        $this->filter = new MediaLibraryFilter();

        global $wpdb;

        // The shape core hands a posts_clauses filter. Not empty strings:
        // a filter that returned a fresh array would pass against those.
        $this->clauses = [
            'where' => " AND {$wpdb->posts}.post_type = 'attachment'",
            'groupby' => '',
            'join' => '',
            'orderby' => "{$wpdb->posts}.post_date DESC",
            'distinct' => '',
            'fields' => "{$wpdb->posts}.*",
            'limits' => 'LIMIT 0, 20',
        ];
    }

    public function test_a_query_that_asked_for_no_folder_is_returned_untouched(): void
    {
        $query = new WP_Query();

        self::assertSame(
            $this->clauses,
            $this->filter->joinFolderAssignments($this->clauses, $query)
        );
    }

    /**
     * The same, for a query that has been through `WP_Query::parse_query()`.
     *
     * An unset query var reads as `''` rather than null once a query has been
     * parsed, and the two are different bails in the same `if`. This is the
     * one of the pair that a real page load produces.
     */
    public function test_a_parsed_query_with_no_folder_is_returned_untouched(): void
    {
        $query = new WP_Query(['post_type' => 'attachment']);

        self::assertSame('', $query->get(MediaLibraryFilter::QUERY_VAR));
        self::assertSame(
            $this->clauses,
            $this->filter->joinFolderAssignments($this->clauses, $query)
        );
    }

    /**
     * The positive control, so the two above cannot pass by the filter being
     * inert. Trap 46: a zero that matches the prediction is the most dangerous
     * measurement there is — confirm the fixture can produce a non-zero.
     */
    public function test_a_query_that_asked_for_a_folder_gets_the_join(): void
    {
        $query = new WP_Query();
        $query->set(MediaLibraryFilter::QUERY_VAR, 7);

        $filtered = $this->filter->joinFolderAssignments($this->clauses, $query);

        self::assertNotSame($this->clauses, $filtered);
        self::assertStringContainsString('folderfolio_attachment_folders', $filtered['join']);
        self::assertStringContainsString('INNER JOIN', $filtered['join']);
        self::assertStringContainsString('folderfolio_assignments.folder_id = 7', $filtered['where']);

        // And it leaves alone every clause it has no business in.
        foreach (['groupby', 'orderby', 'distinct', 'fields', 'limits'] as $clause) {
            self::assertSame($this->clauses[$clause], $filtered[$clause]);
        }
    }

    /**
     * Zero is Unassigned, which is a filter and not an absence — the
     * distinction `normalizeFolderId()` exists to keep.
     */
    public function test_folder_zero_is_a_filter_not_an_absence(): void
    {
        $query = new WP_Query();
        $query->set(MediaLibraryFilter::QUERY_VAR, 0);

        $filtered = $this->filter->joinFolderAssignments($this->clauses, $query);

        self::assertStringContainsString('LEFT JOIN', $filtered['join']);
        self::assertStringContainsString(
            'folderfolio_assignments.attachment_id IS NULL',
            $filtered['where']
        );
    }
}
