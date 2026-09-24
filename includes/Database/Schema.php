<?php

declare(strict_types=1);

namespace FolderFolio\Database;

use FolderFolio\Domain\FolderPath;
use FolderFolio\Support\Swatches;

if (!defined('ABSPATH')) {
    exit;
}

class Schema
{
    public function migrate(): void
    {
        global $wpdb;

        if (! function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $charset_collate = $wpdb->get_charset_collate();

        // Every CREATE TABLE below pins InnoDB, and it is load-bearing rather
        // than a default worth restating. MyISAM accepts START TRANSACTION and
        // ROLLBACK and ignores both, so on a MyISAM site Database\Transaction
        // would appear to work and would protect nothing — the delete paths
        // would go back to being able to destroy a subtree's membership and
        // leave the folders standing. dbDelta does not compare the engine
        // clause, so this only affects tables it creates; ensureInnoDb() below
        // carries an existing install across.
        $engine = 'ENGINE=InnoDB';

        // Folders table.
        //
        // parent_id is the source of truth for hierarchy. `path` and `depth`
        // are derived from it — a materialised path, so that subtree reads,
        // moves, deletes, breadcrumbs and cycle checks are all non-recursive.
        // See FolderPath for the format and why the trailing slash matters.
        //
        // object_type scopes a folder to a post type. Only 'attachment' is
        // used in 1.0; the column exists so posts and pages can follow without
        // a migration on live sites.
        $table_folders = $wpdb->prefix . 'folderfolio_folders';
        $sql_folders = "CREATE TABLE {$table_folders} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id BIGINT UNSIGNED NULL,
            path VARCHAR(255) NOT NULL DEFAULT '',
            depth TINYINT UNSIGNED NOT NULL DEFAULT 0,
            object_type VARCHAR(20) NOT NULL DEFAULT 'attachment',
            name VARCHAR(191) NOT NULL,
            slug VARCHAR(191) NULL,
            color VARCHAR(20) NULL,
            icon VARCHAR(50) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY parent_id (parent_id),
            KEY path (path),
            KEY object_type_parent (object_type, parent_id),
            KEY slug (slug)
        ) {$engine} {$charset_collate};";

        \dbDelta($sql_folders);

        // dbDelta adds columns and indexes; it never removes them. These three
        // were created speculatively and never read or written by any shipped
        // version, so there is no data to preserve. Dropping them now, before
        // 1.0, is the cheap moment — once a column ships to real sites,
        // removing it is a migration with a support cost.
        $this->dropColumns($table_folders, ['template_id', 'owner_id', 'visibility']);

        // 0.2.0 stored a free hex in `color`; a folder stores a swatch name
        // now. See Support\Swatches for why — in one line, a hex can only be
        // right on one admin colour scheme, and eight of them ship with core.
        //
        // Run unconditionally rather than behind a DB_VERSION check: it only
        // touches rows whose colour still starts with '#', so a second run is
        // a no-op, and a site that upgraded through a version where the check
        // did not fire is repaired rather than left half-converted.
        $this->migrateFolderColours($table_folders);

        // Attachment-folder assignments table.
        //
        // The primary key permits a file in many folders, and unlike every
        // competitor examined the code permits it too: assignment is an add,
        // not a delete-then-insert. That is what lets the migration wizard
        // import without taking files out of folders the user made.
        //
        // `import_run` is the id of the import that created the row, and null
        // on every row a person created. Undo deletes by it. It replaced a
        // window on `assigned_at`, which was wrong in a way no amount of care
        // with the window could fix: a row filed by hand *during* the import —
        // which screen 07 invites, by saying the run continues after you leave
        // the page — sat inside the window and was deleted by an undo that
        // promises to keep exactly that.
        $table_assignments = $wpdb->prefix . 'folderfolio_attachment_folders';
        $sql_assignments = "CREATE TABLE {$table_assignments} (
            folder_id BIGINT UNSIGNED NOT NULL,
            attachment_id BIGINT UNSIGNED NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            assigned_at DATETIME NOT NULL,
            import_run VARCHAR(32) NULL DEFAULT NULL,
            PRIMARY KEY  (folder_id, attachment_id),
            KEY attachment_id (attachment_id),
            KEY import_run (import_run)
        ) {$engine} {$charset_collate};";

        \dbDelta($sql_assignments);

        // Folder meta table.
        //
        // It shipped with PRIMARY KEY (folder_id) - one meta row per folder,
        // ever. dbDelta adds columns and indexes but will not alter a primary
        // key, so the table has to go and come back. Nothing reads or writes it
        // yet; the row count is checked anyway rather than assumed.
        $table_meta = $wpdb->prefix . 'folderfolio_folder_meta';

        $this->dropIfEmpty($table_meta);
        $sql_meta = "CREATE TABLE {$table_meta} (
            folder_id BIGINT UNSIGNED NOT NULL,
            meta_key VARCHAR(191) NOT NULL,
            meta_value LONGTEXT NULL,
            PRIMARY KEY  (folder_id, meta_key),
            KEY meta_key (meta_key)
        ) {$engine} {$charset_collate};";

        \dbDelta($sql_meta);

        // User preferences table.
        $table_preferences = $wpdb->prefix . 'folderfolio_user_preferences';
        $sql_preferences = "CREATE TABLE {$table_preferences} (
            user_id BIGINT UNSIGNED NOT NULL,
            preferences LONGTEXT NULL,
            PRIMARY KEY  (user_id)
        ) {$engine} {$charset_collate};";

        \dbDelta($sql_preferences);

        // Carry an install created before the engine was pinned. Only the two
        // tables the transactional write paths touch — the meta and preference
        // tables are single-row-per-key and never take part in a multi-statement
        // sequence, so rewriting them would be a table rebuild for nothing.
        $this->ensureInnoDb([$table_folders, $table_assignments]);

        // Fill in path/depth for any row that predates those columns. Safe to
        // run every time: it only touches rows whose path is still empty.
        $this->backfillPaths();
    }

    /**
     * Convert tables that are not already InnoDB.
     *
     * Only reachable on an install that predates the pinned engine, which
     * before 1.0 means a development site. It is written to be safe anyway,
     * because the alternative to converting is a transaction that silently
     * protects nothing.
     *
     * Skipped entirely when the server cannot offer InnoDB. That is the case
     * this cannot fix, and is also why `Doctor` reports the engine rather than
     * this method assuming it succeeded: MySQL does not fail a
     * `CREATE TABLE … ENGINE=InnoDB` when InnoDB is unavailable, it quietly
     * substitutes the default engine and carries on.
     *
     * @param list<string> $tables
     */
    private function ensureInnoDb(array $tables): void
    {
        global $wpdb;

        if (!self::innoDbAvailable()) {
            return;
        }

        foreach ($tables as $table) {
            if (self::engineOf($table) === 'innodb') {
                continue;
            }

            // Identifiers cannot be bound, and $table is built from
            // $wpdb->prefix — no caller-supplied value reaches this.
            $wpdb->query("ALTER TABLE {$table} ENGINE=InnoDB");
        }
    }

    /**
     * The storage engine of a table, lower-cased, or null when unknown.
     *
     * `SHOW TABLE STATUS` rather than information_schema: it needs no extra
     * privilege, which matters on shared hosting where the database user often
     * cannot read information_schema.TABLES for anything but its own schema.
     */
    public static function engineOf(string $table): ?string
    {
        global $wpdb;

        $engine = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $table),
            1 // The Engine column.
        );

        return $engine === null ? null : strtolower((string) $engine);
    }

    /**
     * Whether this server can create InnoDB tables at all.
     */
    public static function innoDbAvailable(): bool
    {
        global $wpdb;

        $support = $wpdb->get_var(
            "SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'"
        );

        return in_array(strtoupper((string) $support), ['YES', 'DEFAULT'], true);
    }

    /**
     * Recompute path and depth for every folder, from parent_id.
     *
     * parent_id is the source of truth, so this can always rebuild the derived
     * columns from scratch. Exposed through the Status screen and WP-CLI as a
     * repair action — Real Media Library ships five "reset" endpoints and
     * CatFolders a "clean-db"; two competitors independently concluding a
     * folder plugin needs a repair tool is enough evidence to build one before
     * the support inbox asks for it.
     *
     * @param bool $force Rebuild every row, not only rows with an empty path.
     * @return int Number of rows that had drifted, and were changed.
     */
    public function backfillPaths(bool $force = false): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'folderfolio_folders';

        if (!$force) {
            $pending = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE path = '' OR path IS NULL"
            );

            if ($pending === 0) {
                return 0;
            }
        }

        /** @var list<array{id: string, parent_id: string|null}> $rows */
        $rows = $wpdb->get_results(
            "SELECT id, parent_id FROM {$table}",
            ARRAY_A
        ) ?: [];

        /** @var array<int, int|null> $parents */
        $parents = [];

        foreach ($rows as $row) {
            $parents[(int) $row['id']] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        }

        $written = 0;

        foreach ($parents as $id => $_parent) {
            $chain = [];
            $cursor = $id;
            $guard = 0;

            // Walk to the root, defending against a cycle in existing data
            // rather than looping forever. A cycle cannot be created through
            // FolderService, but this routine exists precisely for rows that
            // got into a state the service would not have allowed.
            while ($cursor !== null && $guard++ <= FolderPath::MAX_DEPTH + 1) {
                if (in_array($cursor, $chain, true)) {
                    $chain = [];
                    break;
                }

                array_unshift($chain, $cursor);
                $cursor = $parents[$cursor] ?? null;
            }

            if ($chain === []) {
                continue;
            }

            $path = FolderPath::SEPARATOR
                . implode(FolderPath::SEPARATOR, $chain)
                . FolderPath::SEPARATOR;

            // Every row is written; a row whose path and depth were already
            // right changes nothing, and MySQL counts only rows it changed —
            // so the number is what had drifted, which is what the CLI and
            // the facade say it is (24 Sep: it was every folder on the site).
            $written += (int) $wpdb->update(
                $table,
                ['path' => $path, 'depth' => count($chain) - 1],
                ['id' => $id]
            );
        }

        return $written;
    }

    /**
     * Turn every stored hex colour into the nearest swatch name.
     *
     * Reads the distinct values rather than the rows: a site with 5,000
     * folders has at most ten colours among them, so this is ten small
     * UPDATEs and not one per folder.
     */
    private function migrateFolderColours(string $table): void
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix.
        $hexes = $wpdb->get_col(
            "SELECT DISTINCT color FROM {$table} WHERE color LIKE '#%'"
        );
        // phpcs:enable

        foreach ($hexes as $hex) {
            // null when the value is not a hex this can read — '#nope', or a
            // truncation. Clearing it is the safe end of the two: the column
            // is validated against the ten names from here on, and a value
            // that is none of them would fail every later save of that folder.
            $swatch = Swatches::nearest((string) $hex);

            $wpdb->update(
                $table,
                ['color' => $swatch],
                ['color' => $hex],
                ['%s'],
                ['%s']
            );
        }
    }

    /**
     * Drop columns, if they are there.
     *
     * An ALTER against a column that was never created must not fatal — a site
     * installing 1.0 fresh never had these, and a site upgrading did.
     *
     * @param list<string> $columns
     */
    private function dropColumns(string $table, array $columns): void
    {
        global $wpdb;

        $existing = $wpdb->get_col("SHOW COLUMNS FROM {$table}") ?: [];

        foreach ($columns as $column) {
            if (!in_array($column, $existing, true)) {
                continue;
            }

            $wpdb->query("ALTER TABLE {$table} DROP COLUMN `{$column}`");
        }
    }

    /**
     * Drop a table only when it holds no rows.
     *
     * Used where a definition changed in a way dbDelta cannot apply in place.
     */
    private function dropIfEmpty(string $table): void
    {
        global $wpdb;

        $exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        if ($exists !== $table) {
            return;
        }

        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") > 0) {
            return;
        }

        $wpdb->query("DROP TABLE {$table}");
    }
}
