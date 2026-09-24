<?php

namespace FolderFolio\Tests\Integration\Database;

use FolderFolio\Database\Schema;
use WP_UnitTestCase;

/**
 * `Schema::TABLES` is the list a deleted network site drops, and uninstall.php
 * keeps its own copy because it runs without the autoloader. Three lists of
 * one thing: this holds them together, so a fifth table added to migrate()
 * fails here until both know about it.
 */
class SchemaTablesTest extends WP_UnitTestCase
{
    /**
     * @test
     */
    public function the_list_names_every_table_migrate_creates(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/Database/Schema.php');

        // Each `CREATE TABLE {$var}`, and what `$var` was assigned.
        preg_match_all('/CREATE TABLE \{\$([a-z_]+)\}/', $source, $created);
        preg_match_all('/\$([a-z_]+) = \$wpdb->prefix \. \'(folderfolio_[a-z_]+)\';/', $source, $assigned);
        $names = array_combine($assigned[1], $assigned[2]);
        $m = [1 => array_values(array_map(fn (string $var): string => $names[$var] ?? $var, $created[1]))];

        $this->assertCount(4, $m[1], 'The pattern finds each CREATE TABLE in migrate().');
        $this->assertEqualsCanonicalizing($m[1], Schema::TABLES);
    }

    /**
     * @test
     */
    public function every_listed_table_exists_after_migrate(): void
    {
        global $wpdb;

        (new Schema())->migrate();

        // SHOW COLUMNS rather than SHOW TABLES: inside a test the WordPress
        // test library makes every CREATE TABLE a temporary table, which
        // SHOW TABLES does not list.
        foreach (Schema::TABLES as $table) {
            $this->assertNotEmpty($wpdb->get_col('SHOW COLUMNS FROM ' . $wpdb->prefix . $table), $table);
        }
    }

    /**
     * @test
     */
    public function uninstall_drops_the_same_tables(): void
    {
        $uninstall = (string) file_get_contents(dirname(__DIR__, 3) . '/uninstall.php');

        preg_match("/\\\$tables = \\[(.*?)\\];/s", $uninstall, $m);
        preg_match_all("/'(folderfolio_[a-z_]+)'/", $m[1] ?? '', $names);

        $this->assertEqualsCanonicalizing(Schema::TABLES, $names[1]);
    }
}
