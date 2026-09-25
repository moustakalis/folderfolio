<?php

namespace FolderFolio\Tests\Integration\Database;

use FolderFolio\Admin\RailPreferences;
use FolderFolio\Database\Schema;
use FolderFolio\Plugin;
use WP_UnitTestCase;

/**
 * Review item #27: a network. Skipped on a single site — run the suite with
 * `WP_MULTISITE=1` (CI has a leg for it).
 *
 * Each of these makes or drops tables, which MySQL commits implicitly, so the
 * sites they create are deleted by the test itself rather than left to the
 * rollback.
 */
class NetworkTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        if (!is_multisite()) {
            $this->markTestSkipped('Needs a network: WP_MULTISITE=1.');
        }
    }

    public function tear_down(): void
    {
        parent::tear_down();

        // After the rollback, not before it: making a site commits (its
        // tables are DDL), so the network activation this test wrote is
        // already committed and a delete inside the transaction would be
        // rolled back with it.
        delete_site_option('active_sitewide_plugins');
    }

    private function tablesOf(int $siteId): array
    {
        global $wpdb;

        $prefix = $wpdb->get_blog_prefix($siteId);
        $found = [];

        // SHOW COLUMNS, not SHOW TABLES: the test library turns every CREATE
        // TABLE inside a test into a temporary table, which SHOW TABLES skips.
        $wpdb->suppress_errors(true);

        foreach (Schema::TABLES as $table) {
            if ($wpdb->get_col('SHOW COLUMNS FROM ' . $prefix . $table) !== []) {
                $found[] = $table;
            }
        }

        $wpdb->suppress_errors(false);

        return $found;
    }

    private function dropTablesOf(int $siteId): void
    {
        global $wpdb;

        foreach (Schema::TABLES as $table) {
            $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->get_blog_prefix($siteId) . $table);
        }

        delete_blog_option($siteId, 'folderfolio_db_version');
    }

    private function activateForNetwork(): void
    {
        update_site_option('active_sitewide_plugins', [plugin_basename(FOLDERFOLIO_PLUGIN_FILE) => time()]);
    }

    /**
     * @test
     */
    public function a_network_activation_installs_every_site(): void
    {
        $siteId = self::factory()->blog->create();
        $this->dropTablesOf($siteId);
        $this->assertSame([], $this->tablesOf($siteId), 'Precondition: the second site has none.');

        Plugin::activate(true);

        $this->assertEqualsCanonicalizing(Schema::TABLES, $this->tablesOf($siteId));
        $this->assertSame(Plugin::DB_VERSION, get_blog_option($siteId, 'folderfolio_db_version'));
        $this->assertEqualsCanonicalizing(Schema::TABLES, $this->tablesOf(get_main_site_id()));

        wp_delete_site($siteId);
    }

    /**
     * @test
     */
    public function a_site_activation_installs_only_that_site(): void
    {
        $siteId = self::factory()->blog->create();
        $this->dropTablesOf($siteId);

        Plugin::activate(false);

        $this->assertSame([], $this->tablesOf($siteId));

        wp_delete_site($siteId);
    }

    /**
     * @test
     */
    public function a_new_site_is_installed_when_the_plugin_is_network_active(): void
    {
        $this->activateForNetwork();

        $siteId = self::factory()->blog->create();

        $this->assertEqualsCanonicalizing(Schema::TABLES, $this->tablesOf($siteId));

        wp_delete_site($siteId);
    }

    /**
     * @test
     */
    public function a_new_site_is_left_alone_when_the_plugin_is_not(): void
    {
        $siteId = self::factory()->blog->create();

        $this->assertSame([], $this->tablesOf($siteId));

        wp_delete_site($siteId);
    }

    /**
     * @test
     */
    public function deleting_a_site_drops_its_tables(): void
    {
        $this->activateForNetwork();
        $siteId = self::factory()->blog->create();
        $this->assertCount(count(Schema::TABLES), $this->tablesOf($siteId), 'Precondition.');

        wp_delete_site($siteId);

        $this->assertSame([], $this->tablesOf($siteId));
        $this->assertEqualsCanonicalizing(Schema::TABLES, $this->tablesOf(get_main_site_id()), 'The main site keeps its own.');
    }

    /**
     * @test
     */
    public function a_star_on_one_site_is_not_a_star_on_another(): void
    {
        $this->activateForNetwork();
        $siteId = self::factory()->blog->create();
        $userId = self::factory()->user->create();

        RailPreferences::save($userId, ['stars' => [12], 'startup' => 12, 'width' => 400]);

        switch_to_blog($siteId);
        $there = RailPreferences::forUser($userId);
        restore_current_blog();

        $this->assertSame([], $there['stars']);
        $this->assertNull($there['startup']);
        $this->assertSame([12], RailPreferences::forUser($userId)['stars'], 'And the first site keeps it.');

        wp_delete_site($siteId);
    }
}
