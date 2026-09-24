<?php

declare(strict_types=1);

namespace FolderFolio\Database;

use FolderFolio\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The two network events a site-level plugin does not see by itself — review
 * item #27.
 *
 * FolderFolio's tables carry each site's prefix, so a network is one set of
 * tables per site. Activation installs the sites that exist
 * (`Plugin::activate()`); this installs the ones made afterwards, and removes
 * a site's tables when the site itself is deleted.
 */
final class Network
{
    public function register(): void
    {
        // After core's own install of the site (priority 10) and its default
        // content, so the switch below lands on a finished site.
        add_action('wp_initialize_site', [$this, 'initializeSite'], 200);
        add_filter('wpmu_drop_tables', [$this, 'dropTables'], 10, 2);
    }

    /**
     * Install a new site — only when the plugin is active for the network.
     * A plugin activated site by site is installed by its own activation.
     *
     * @param mixed $site
     */
    public function initializeSite($site): void
    {
        if (!$site instanceof \WP_Site || !self::isNetworkActive()) {
            return;
        }

        switch_to_blog((int) $site->blog_id);
        Plugin::install();
        restore_current_blog();
    }

    /**
     * Core drops the tables it lists when a site is deleted; ours are added.
     *
     * @param mixed $tables
     * @param mixed $siteId
     *
     * @return array<int|string, string>
     */
    public function dropTables($tables, $siteId = 0): array
    {
        global $wpdb;

        $tables = is_array($tables) ? $tables : [];
        $prefix = $wpdb->get_blog_prefix((int) $siteId);

        foreach (Schema::TABLES as $table) {
            $tables[] = $prefix . $table;
        }

        return $tables;
    }

    public static function isNetworkActive(): bool
    {
        $active = get_site_option('active_sitewide_plugins', []);

        return is_array($active) && isset($active[plugin_basename(FOLDERFOLIO_PLUGIN_FILE)]);
    }
}
