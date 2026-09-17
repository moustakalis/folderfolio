<?php

namespace FolderFolio\Tests\Integration\Database;

use FolderFolio\Database\Schema;
use WP_UnitTestCase;

/**
 * The migration, run against a table that already has rows in it.
 *
 * Every other test in this suite gets its schema built from nothing, which is
 * the one case a migration cannot get wrong. The case that matters is a live
 * site on the previous version: dbDelta has to add the column without touching
 * what is already filed.
 */
class SchemaTest extends WP_UnitTestCase
{
    /**
     * @test
     */
    public function migrate_adds_import_run_to_an_assignments_table_that_predates_it(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'folderfolio_attachment_folders';

        // The table exactly as DB_VERSION 3 shipped it.
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
        $wpdb->query(
            "CREATE TABLE {$table} (
                folder_id BIGINT UNSIGNED NOT NULL,
                attachment_id BIGINT UNSIGNED NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                assigned_at DATETIME NOT NULL,
                PRIMARY KEY  (folder_id, attachment_id),
                KEY attachment_id (attachment_id)
            )"
        );
        $wpdb->query(
            "INSERT INTO {$table} (folder_id, attachment_id, sort_order, assigned_at)
             VALUES (7, 9, 0, '2026-01-01 00:00:00')"
        );

        (new Schema())->migrate();

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");

        $this->assertContains('import_run', $columns, 'dbDelta adds the column.');
        $this->assertSame('1', $wpdb->get_var("SELECT COUNT(*) FROM {$table}"), 'And keeps the row.');
        $this->assertNull(
            $wpdb->get_var("SELECT import_run FROM {$table} WHERE attachment_id = 9"),
            'A row that predates the column belongs to no import: null, so undo never claims it.'
        );
    }

    /**
     * @test
     */
    public function migrate_is_idempotent(): void
    {
        global $wpdb;

        $schema = new Schema();
        $schema->migrate();
        $schema->migrate();

        $table = $wpdb->prefix . 'folderfolio_attachment_folders';
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");

        // SHOW COLUMNS, not information_schema: the WordPress test suite
        // rewrites every CREATE TABLE into CREATE TEMPORARY TABLE so that a
        // test's schema dies with its connection, and a temporary table is
        // invisible to information_schema — the query comes back 0 whatever
        // the truth is.
        $this->assertSame(
            1,
            count(array_filter($columns, static fn (string $c): bool => 'import_run' === $c)),
            'Running the migration twice leaves one column, not two and not none.'
        );
    }
}
