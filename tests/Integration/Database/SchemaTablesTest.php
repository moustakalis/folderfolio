<?php

namespace FolderFolio\Tests\Integration\Database;

use FolderFolio\Database\Network;
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

        $this->assertCount(3, $m[1], 'The pattern finds each CREATE TABLE in migrate().');
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

    /**
     * `folderfolio_user_preferences` was made from the first version and never
     * used; Nick dropped it before 1.0 (25 Sep). A site that has it loses it
     * on the upgrade.
     *
     * @test
     */
    public function migrate_drops_a_retired_table(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'folderfolio_user_preferences';
        $wpdb->query("CREATE TABLE {$table} (user_id BIGINT UNSIGNED NOT NULL, preferences LONGTEXT NULL, PRIMARY KEY (user_id))");

        $wpdb->suppress_errors(true);
        $this->assertNotEmpty($wpdb->get_col("SHOW COLUMNS FROM {$table}"), 'Precondition: the table is there.');

        (new Schema())->migrate();

        $this->assertEmpty($wpdb->get_col("SHOW COLUMNS FROM {$table}"), 'Gone after migrate().');
        $wpdb->suppress_errors(false);
        $this->assertNotContains('folderfolio_user_preferences', Schema::TABLES);
    }

    /**
     * A site that never ran the upgrade still has the retired tables: the
     * uninstaller and a deleted network site drop them as well.
     *
     * @test
     */
    public function the_retired_tables_are_dropped_on_uninstall_and_with_a_site(): void
    {
        global $wpdb;

        $uninstall = (string) file_get_contents(dirname(__DIR__, 3) . '/uninstall.php');

        preg_match("/\\\$retired = \\[(.*?)\\];/s", $uninstall, $m);
        preg_match_all("/'(folderfolio_[a-z_]+)'/", $m[1] ?? '', $names);

        $this->assertNotEmpty(Schema::RETIRED_TABLES);
        $this->assertEqualsCanonicalizing(Schema::RETIRED_TABLES, $names[1]);

        $dropped = (new Network())->dropTables([], get_current_blog_id());

        foreach (Schema::RETIRED_TABLES as $table) {
            $this->assertContains($wpdb->prefix . $table, $dropped);
        }
    }
}
