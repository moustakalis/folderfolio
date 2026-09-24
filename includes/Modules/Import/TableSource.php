<?php

declare(strict_types=1);

namespace FolderFolio\Modules\Import;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sources that keep folders in two tables of their own.
 *
 * FileBird, Real Media Library and CatFolders. Their schemas differ in every
 * detail — column names, root marker, how a post type is scoped — and in
 * nothing structural, so the differences are configuration rather than three
 * near-identical classes:
 *
 * | | FileBird 6.5.8 | RML Lite 4.23.4 | CatFolders 2.5.6 |
 * |---|---|---|---|
 * | folders | `fbv` | `realmedialibrary` | `catfolders` |
 * | name | `name` | `name` | `title` |
 * | parent | `parent`, root `0` | `parent`, root **`-1`** | `parent`, root `0` |
 * | scope | `type` int, 0 = folder | `type` varchar, `'0'` | `type` varchar, `'attachment'` |
 * | pairs | `fbv_attachment_folder` | `realmedialibrary_posts` | `catfolders_posts` |
 * | pair columns | `folder_id`, `attachment_id` | `fid`, `attachment` | `folder_id`, `post_id` |
 *
 * Every one of those was read from the plugin's own `CREATE TABLE`, not from
 * its documentation — v0.2.0's importer was written against `fbv_folders` and
 * `fbv_assignments`, which FileBird has never had, so it reported "not
 * installed" on every real FileBird site and could never run.
 *
 * The scope filter is not optional. Each of these tables holds folders for
 * post types other than attachments, and importing without the filter fills a
 * media library's folder tree with a site's post categories.
 */
class TableSource extends Source
{
    /**
     * @param string          $folderTable      Table suffix, without the prefix.
     * @param string          $assignmentTable  Table suffix, without the prefix.
     * @param string          $nameColumn       Column holding the folder name.
     * @param int             $rootMarker       What this source writes for "no parent".
     * @param string|int|null $scopeValue       Value of $scopeColumn that means "attachments".
     * @param string|null     $scopeColumn      Null when the source does not scope by type.
     * @param string          $folderIdColumn   Pair table's folder column.
     * @param string          $attachmentColumn Pair table's attachment column.
     */
    public function __construct(
        string $key,
        string $label,
        string $pluginFile,
        private readonly string $folderTable,
        private readonly string $assignmentTable,
        private readonly string $nameColumn = 'name',
        private readonly int $rootMarker = 0,
        private readonly string|int|null $scopeValue = null,
        private readonly ?string $scopeColumn = null,
        private readonly string $folderIdColumn = 'folder_id',
        private readonly string $attachmentColumn = 'attachment_id',
        private readonly string $parentColumn = 'parent',
        private readonly string $orderColumn = 'ord'
    ) {
        parent::__construct($key, $label, $pluginFile);
    }

    private function folders_(): string
    {
        global $wpdb;

        return $wpdb->prefix . $this->folderTable;
    }

    private function assignments_(): string
    {
        global $wpdb;

        return $wpdb->prefix . $this->assignmentTable;
    }

    /**
     * Does a table exist?
     *
     * `SHOW TABLES LIKE` needs its argument escaped for LIKE, not just for
     * quoting: a prefix containing `_` would otherwise match a table it should
     * not. `esc_like` before `prepare`, which is the order that matters.
     */
    private function tableExists(string $table): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema introspection; the answer must be live, never cached.
        $found = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        );

        return $found === $table;
    }

    public function hasData(): bool
    {
        // A table that exists but is empty is not data. The tables outlive the
        // plugin, so "the table is there" answers nothing on its own.
        return $this->tableExists($this->folders_()) && $this->folderCount() > 0;
    }

    public function folderCount(): int
    {
        global $wpdb;

        if (!$this->tableExists($this->folders_())) {
            return 0;
        }

        // Identifiers and the scope column are ours, from the catalogue, never
        // from a request, and go in through %i; the scope *value* is bound.
        $sql = 'SELECT COUNT(*) FROM %i' . $this->scopeWhere();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- another plugin's table, which no API of ours or core's reads; read once to plan an import. $sql is literal SQL and placeholders only; every identifier goes through %i.
        return (int) $wpdb->get_var($wpdb->prepare($sql, $this->folders_(), ...$this->scopeArgs()));
    }

    public function assignmentCount(): int
    {
        global $wpdb;

        if (!$this->tableExists($this->assignments_()) || !$this->tableExists($this->folders_())) {
            return 0;
        }

        // Joined to the folder table so that a pair pointing at a folder of
        // another post type — or at a folder that no longer exists — is not
        // counted as something this import would bring over.
        $sql = 'SELECT COUNT(*) FROM %i a'
            . ' INNER JOIN %i f ON f.id = a.%i'
            . $this->scopeWhere('f.');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- another plugin's table, which no API of ours or core's reads; read once to plan an import. $sql is literal SQL and placeholders only; every identifier goes through %i.
        return (int) $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is literal SQL and placeholders only; every identifier goes through %i.
            $wpdb->prepare($sql, $this->assignments_(), $this->folders_(), $this->folderIdColumn, ...$this->scopeArgs())
        );
    }

    /**
     * @return list<SourceFolder>
     */
    public function folders(): array
    {
        global $wpdb;

        if (!$this->tableExists($this->folders_())) {
            return [];
        }

        $sql = 'SELECT id, %i AS parent, %i AS name, %i AS ord'
            . ' FROM %i' . $this->scopeWhere() . ' ORDER BY id ASC';

        /** @var list<array{id: string, parent: string, name: string, ord: string|null}> $rows */
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- another plugin's table, which no API of ours or core's reads; read once to plan an import. $sql is literal SQL and placeholders only; every identifier goes through %i.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is literal SQL and placeholders only; every identifier goes through %i.
                $sql,
                $this->parentColumn,
                $this->nameColumn,
                $this->orderColumn,
                $this->folders_(),
                ...$this->scopeArgs()
            ),
            ARRAY_A
        ) ?: [];

        $folders = [];

        foreach ($rows as $row) {
            $parent = (int) $row['parent'];

            $folders[] = new SourceFolder(
                (int) $row['id'],
                // The normalisation this whole class exists to get right.
                $parent === $this->rootMarker ? null : $parent,
                (string) $row['name'],
                (int) ($row['ord'] ?? 0)
            );
        }

        return $folders;
    }

    /**
     * @return list<int>
     */
    public function attachmentIdsFor(int $sourceFolderId): array
    {
        global $wpdb;

        if (!$this->tableExists($this->assignments_())) {
            return [];
        }

        /** @var list<string> $ids */
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- another plugin's table, which no API of ours or core's reads; read once to plan an import.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT DISTINCT %i FROM %i WHERE %i = %d',
                $this->attachmentColumn,
                $this->assignments_(),
                $this->folderIdColumn,
                $sourceFolderId
            )
        ) ?: [];

        // DISTINCT because Real Media Library keys its pair table on
        // (attachment, isShortcut): one file that appears in a folder both
        // directly and as a shortcut is two rows and one assignment.
        return array_values(array_map('intval', $ids));
    }

    private function scopeWhere(string $alias = ''): string
    {
        if (null === $this->scopeColumn || null === $this->scopeValue) {
            return '';
        }

        $placeholder = is_int($this->scopeValue) ? '%d' : '%s';

        return ' WHERE ' . $alias . '%i = ' . $placeholder;
    }

    /**
     * What scopeWhere()'s placeholders bind, in order: the column, then the
     * value. Empty exactly when scopeWhere() is.
     *
     * @return list<string|int>
     */
    private function scopeArgs(): array
    {
        if (null === $this->scopeColumn || null === $this->scopeValue) {
            return [];
        }

        return [$this->scopeColumn, $this->scopeValue];
    }
}
