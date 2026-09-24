<?php

declare(strict_types=1);

namespace FolderFolio\Domain;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Support\PostTypes;
use WP_Error;
use wpdb;

class AttachmentFolderRepository
{
    private wpdb $wpdb;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    public function table(): string
    {
        return $this->wpdb->prefix . 'folderfolio_attachment_folders';
    }

    /**
     * A join that keeps only rows whose post is in a status the type's list
     * screen shows — tier 3 item 12.
     *
     * Empty for media, deliberately: those counts have never joined the posts
     * table and a library of 100,000 files should not start now. A post
     * folder has to, because posts go to the trash and a folder showing a
     * count for a post nobody can see in it is a wrong number.
     */
    private function statusJoin(?string $objectType, string $alias): string
    {
        if ($objectType === null || $objectType === PostTypes::MEDIA) {
            return '';
        }

        $statuses = implode(', ', array_map(
            fn (string $status): string => (string) $this->wpdb->prepare('%s', $status),
            PostTypes::statuses($objectType)
        ));

        return (string) $this->wpdb->prepare(
            ' INNER JOIN %i AS ff_p ON ff_p.ID = %i.attachment_id AND ff_p.post_type = %s',
            $this->wpdb->posts,
            $alias,
            $objectType
        ) . " AND ff_p.post_status IN ({$statuses})";
    }

    /**
     * Every write says it happened, and every read that is cached keys on it.
     *
     * Two readers depend on this. GalleryQuery caches a folder's ids under
     * `wp_cache_get_last_changed('folderfolio')`, and its docblock said every
     * write bumped it — none did, so with a persistent object cache a gallery
     * never saw a file filed after its first render. And WP_Query (6.1+)
     * caches a query's ids under the SQL and the *posts* last-changed, which
     * a filing never touches: the library's folder view returned the ids it
     * had before the write. MediaLibraryFilter now puts this value in the
     * folder join's SQL, so a write here is a new key there. Found on 23 Sep
     * by a test that arranged a folder twice in one request.
     */
    private function changed(): void
    {
        wp_cache_set_last_changed('folderfolio');
    }

    /**
     * Get attachment IDs assigned to a folder.
     *
     * @return list<int>
     */
    public function attachmentIdsForFolder(int $folderId): array
    {
        $wpdb = $this->wpdb;

        return array_map(
            'intval',
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table, no core API; read live, and the cached reader (GalleryQuery) keys on the 'folderfolio' last_changed every write bumps.
            $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT attachment_id FROM %i WHERE folder_id = %d ORDER BY sort_order ASC, attachment_id ASC',
                    $this->table(),
                    $folderId
                )
            ) ?: []
        );
    }

    /**
     * Where these files sit in this folder, for the ones filed there.
     *
     * @param list<int> $attachmentIds
     * @return array<int, int> attachment id => sort_order
     */
    public function positionsIn(int $folderId, array $attachmentIds): array
    {
        if ($attachmentIds === []) {
            return [];
        }

        $wpdb = $this->wpdb;
        $placeholders = implode(',', array_fill(0, count($attachmentIds), '%d'));

        /** @var list<array{attachment_id: string, sort_order: string}> $rows */
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table, no core API; read live, and the cached reader (GalleryQuery) keys on the 'folderfolio' last_changed every write bumps.
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the sniff cannot count the spread ids that fill the %d list.
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only: one %d per attachment id.
                "SELECT attachment_id, sort_order FROM %i WHERE folder_id = %d AND attachment_id IN ($placeholders)",
                $this->table(),
                $folderId,
                ...$attachmentIds
            ),
            ARRAY_A
        ) ?: [];

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['attachment_id']] = (int) $row['sort_order'];
        }

        return $out;
    }

    /** The lowest position in a folder, or null when it holds nothing. */
    public function firstPosition(int $folderId): ?int
    {
        $wpdb = $this->wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table, no core API; read live, and the cached reader (GalleryQuery) keys on the 'folderfolio' last_changed every write bumps.
        $min = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT MIN(sort_order) FROM %i WHERE folder_id = %d',
                $this->table(),
                $folderId
            )
        );

        return $min === null ? null : (int) $min;
    }

    /**
     * Write each file's position in a folder, 0 upwards in the order given.
     *
     * One UPDATE per 500 files with a CASE, not one per file: a folder is
     * rewritten whole on every move, and on the 1,000-file case that is the
     * difference between two statements and a thousand.
     *
     * @param list<int> $attachmentIds The folder's files, in their new order.
     */
    public function writePositions(int $folderId, array $attachmentIds): bool|WP_Error
    {
        $wpdb = $this->wpdb;

        foreach (array_chunk($attachmentIds, 500, true) as $chunk) {
            $cases = [];
            $args = [];

            foreach ($chunk as $position => $attachmentId) {
                $cases[] = 'WHEN %d THEN %d';
                $args[] = (int) $attachmentId;
                $args[] = (int) $position;
            }

            $in = implode(',', array_fill(0, count($chunk), '%d'));

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a write to our own table; changed() bumps the 'folderfolio' last_changed key.
            $result = $wpdb->query(
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the sniff cannot count the CASE pairs or the %d list, which arrive as one spread.
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only: $cases is 'WHEN %d THEN %d' per file.
                    'UPDATE %i SET sort_order = CASE attachment_id ' . implode(' ', $cases)
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only: one %d per file.
                        . " ELSE sort_order END WHERE folder_id = %d AND attachment_id IN ($in)",
                    ...[$this->table(), ...$args, $folderId, ...array_map('intval', array_values($chunk))]
                )
            );

            if ($result === false) {
                return new WP_Error(
                    'folderfolio_order_failed',
                    __('The new order could not be saved.', 'folderfolio')
                );
            }
        }

        $this->changed();

        return true;
    }

    /**
     * Get folder IDs assigned to an attachment.
     *
     * @return list<int>
     */
    public function folderIdsForAttachment(int $attachmentId): array
    {
        $wpdb = $this->wpdb;

        return array_map(
            'intval',
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table, no core API; read live, and the cached reader (GalleryQuery) keys on the 'folderfolio' last_changed every write bumps.
            $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT folder_id FROM %i WHERE attachment_id = %d ORDER BY sort_order ASC, folder_id ASC',
                    $this->table(),
                    $attachmentId
                )
            ) ?: []
        );
    }

    /**
     * Folder ids for many attachments at once.
     *
     * The Folders column in the media list table needs this for every row on
     * the page. Calling folderIdsForAttachment() per row is twenty queries for
     * a screen that can answer the whole question in one, and it is the shape
     * of mistake that only shows up on a site with real traffic.
     *
     * Attachments with no folder are absent from the result rather than
     * present with an empty list: the caller is rendering a table and already
     * has to handle a missing key, and inventing rows here would mean
     * building a map of every id on the page twice.
     *
     * @param list<int> $attachmentIds
     * @return array<int, list<int>> Keyed by attachment id, in path order.
     */
    public function folderIdsForAttachments(array $attachmentIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $attachmentIds))));

        if ($ids === []) {
            return [];
        }

        $wpdb = $this->wpdb;
        $folders = $wpdb->prefix . 'folderfolio_folders';
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));

        /*
         * Joined to the folders table, and ordered by path, so the column
         * reads the same way twice — and so an assignment whose folder has
         * been deleted out from under it is dropped here rather than rendering
         * as a blank link. (Deletes clean up after themselves, so that row
         * should not exist; this is the cheap place to be sure.)
         */
        // The interpolation sits mid-string, where a phpcs:ignore cannot reach.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only: one %d per attachment id.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table, no core API; read live, and the cached reader (GalleryQuery) keys on the 'folderfolio' last_changed every write bumps.
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the sniff cannot count the spread ids that fill the %d list.
            $wpdb->prepare(
                "SELECT a.attachment_id, a.folder_id
                 FROM %i AS a
                 INNER JOIN %i AS f ON f.id = a.folder_id
                 WHERE a.attachment_id IN ({$placeholders})
                 ORDER BY f.path ASC",
                $this->table(),
                $folders,
                ...$ids
            ),
            ARRAY_A
        ) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['attachment_id']][] = (int) $row['folder_id'];
        }

        return $out;
    }

    public function assign(
        int $folderId,
        int $attachmentId,
        int $sortOrder = 0,
        ?string $importRun = null
    ): bool|WP_Error {
        $result = $this->wpdb->replace($this->table(), [
            'folder_id' => $folderId,
            'attachment_id' => $attachmentId,
            'sort_order' => $sortOrder,
            'assigned_at' => current_time('mysql', true),
            // Null for every row a person creates, which is what undo reads it
            // for. REPLACE rather than INSERT, so a file the import filed and
            // the user has since re-filed by hand loses the marker and stops
            // being the import's to remove — which is the right way round.
            'import_run' => $importRun,
        ]);

        if ($result === false) {
            return new WP_Error('folderfolio_assignment_failed', __('The files could not be filed in the folder.', 'folderfolio'));
        }

        $this->changed();

        return true;
    }

    public function unassign(int $folderId, int $attachmentId): bool|WP_Error
    {
        $result = $this->wpdb->delete($this->table(), [
            'folder_id' => $folderId,
            'attachment_id' => $attachmentId,
        ]);

        if ($result === false) {
            return new WP_Error('folderfolio_unassignment_failed', __('The files could not be taken out of the folder.', 'folderfolio'));
        }

        $this->changed();

        return true;
    }

    /**
     * Drop every assignment for an attachment.
     *
     * dbDelta cannot express foreign keys, so nothing else removes these rows
     * when the media is deleted - they used to outlive their attachment
     * indefinitely.
     */
    public function deleteForAttachment(int $attachmentId): bool|WP_Error
    {
        $result = $this->wpdb->delete($this->table(), ['attachment_id' => $attachmentId]);

        if ($result === false) {
            return new WP_Error(
                'folderfolio_attachment_cleanup_failed',
                __('The deleted file’s folder entries could not be removed.', 'folderfolio')
            );
        }

        $this->changed();

        return true;
    }

    public function deleteForFolder(int $folderId): bool|WP_Error
    {
        $result = $this->wpdb->delete($this->table(), ['folder_id' => $folderId]);

        if ($result === false) {
            return new WP_Error('folderfolio_assignment_cleanup_failed', __('The folder entries could not be removed.', 'folderfolio'));
        }

        $this->changed();

        return true;
    }

    /**
     * Every assignment, as pairs — for the export.
     *
     * One query and no grouping in SQL: the caller wants them grouped by
     * folder and GROUP_CONCAT would cap silently at group_concat_max_len,
     * which on a default MySQL is 1024 characters — about 130 attachment ids,
     * with no error when the 131st is dropped. Grouping in PHP has no such
     * ceiling.
     *
     * Ordered so the file is stable: the same library exports byte-identical
     * twice, which is what makes a diff between two exports mean something.
     *
     * @return list<array{folder_id: int, attachment_id: int}>
     */
    public function all(): array
    {
        $wpdb = $this->wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table, no core API; read live, and the cached reader (GalleryQuery) keys on the 'folderfolio' last_changed every write bumps.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT folder_id, attachment_id FROM %i
                 ORDER BY folder_id ASC, sort_order ASC, attachment_id ASC',
                $this->table()
            ),
            ARRAY_A
        ) ?: [];

        return array_map(
            static fn (array $row): array => [
                'folder_id' => (int) $row['folder_id'],
                'attachment_id' => (int) $row['attachment_id'],
            ],
            $rows
        );
    }

    /**
     * Direct attachment count for every folder that has at least one.
     *
     * One GROUP BY for the whole tree. Folders with no attachments are absent
     * from the result; callers default them to 0.
     *
     * This is the exact, cheap number. FolderTree::withCounts() rolls these up
     * the tree in PHP for the inherited badge, less what multiFiled() says it
     * would count twice; subtreeCount() below gives the same figure for one
     * folder.
     *
     * @return array<int, int> folder id => count
     */
    public function directCounts(?string $objectType = null): array
    {
        $wpdb = $this->wpdb;
        $join = $this->statusJoin($objectType, 'a');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table, no core API; read live, and the cached reader (GalleryQuery) keys on the 'folderfolio' last_changed every write bumps; $join is statusJoin()'s prepare()d fragment.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $join is statusJoin()'s fragment, built with prepare().
                "SELECT a.folder_id, COUNT(*) AS total FROM %i AS a{$join} GROUP BY a.folder_id",
                $this->table()
            ),
            ARRAY_A
        ) ?: [];

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['folder_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Exact attachment count for a folder and everything beneath it.
     *
     * COUNT(DISTINCT) matters here: a file may be filed in two folders inside
     * the same subtree. The tree's roll-up agrees with this number since
     * 23 Sep — `FolderTree::overcount()` takes the second counting off — and
     * this remains the one-folder way to ask.
     */
    /**
     * Every file filed in two or more folders, and those folders.
     *
     * The input to `FolderTree::overcount()`. Only the files the plain
     * roll-up can count twice, so a library where each file sits in one folder
     * returns nothing and costs one GROUP BY over the `attachment_id` index —
     * the same order of work `directCounts()` already does.
     *
     * @return array<int, list<int>> attachment id => folder ids
     */
    public function multiFiled(?string $objectType = null): array
    {
        $wpdb = $this->wpdb;
        $table = $this->table();
        $join = $this->statusJoin($objectType, 'a');

        // Identifiers, and statuses prepared above; nothing here comes from a
        // request.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table, no core API; read live, and the cached reader (GalleryQuery) keys on the 'folderfolio' last_changed every write bumps; $join is statusJoin()'s prepare()d fragment.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.attachment_id, a.folder_id
                 FROM %i AS a
                 INNER JOIN (
                     SELECT attachment_id FROM %i
                     GROUP BY attachment_id
                     HAVING COUNT(*) > 1
                 ) AS m ON m.attachment_id = a.attachment_id"
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $join is statusJoin()'s fragment, built with prepare().
                    . "{$join}",
                $table,
                $table
            ),
            ARRAY_A
        ) ?: [];

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['attachment_id']][] = (int) $row['folder_id'];
        }

        return $out;
    }

    /**
     * The two counts the rail's fixed rows show.
     *
     * "Unassigned" is not derivable from the per-folder counts: subtracting
     * assigned from total double-counts anything filed in two folders, and it
     * counts assignments whose attachment has since been deleted. It has to be
     * asked of the media table directly.
     *
     * Trashed attachments are excluded from both, so the numbers agree with
     * what the library actually lists.
     *
     * @return array{all: int, unassigned: int}
     */
    public function libraryCounts(string $objectType = PostTypes::MEDIA): array
    {
        global $wpdb;

        $table = $this->table();

        if ($objectType !== PostTypes::MEDIA) {
            return $this->typeCounts($objectType);
        }

        // $table is built from $wpdb->prefix, and an identifier cannot be a
        // bound value in any case — %i quotes it. No user input reaches this.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- joins our own table, no core API; read live, as the rail's count must be.
        $unassigned = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM %i p
                 WHERE p.post_type = 'attachment'
                   AND p.post_status <> 'trash'
                   AND NOT EXISTS (
                       SELECT 1 FROM %i a WHERE a.attachment_id = p.ID
                   )",
                $wpdb->posts,
                $table
            )
        );

        // Core's own, and it is cached — cheaper than a second COUNT(*) and it
        // already applies the same trash rule.
        $all = (int) array_sum((array) wp_count_attachments());

        return [
            'all' => $all,
            'unassigned' => $unassigned,
        ];
    }

    /**
     * The fixed rows' counts on a post type's screen: every item the list
     * calls *All*, and those in no folder.
     *
     * @return array{all: int, unassigned: int}
     */
    private function typeCounts(string $objectType): array
    {
        $wpdb = $this->wpdb;
        $table = $this->table();
        $statuses = implode(', ', array_map(
            fn (string $status): string => (string) $this->wpdb->prepare('%s', $status),
            PostTypes::statuses($objectType)
        ));

        // The interpolation sits mid-string, where a phpcs:ignore cannot reach.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $statuses is each status quoted by prepare('%s') above.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- joins our own table, no core API; read live, as the rail's count must be; $statuses is each status quoted by prepare('%s').
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS total,
                        SUM(NOT EXISTS (SELECT 1 FROM %i a WHERE a.attachment_id = p.ID)) AS unassigned
                 FROM %i p
                 WHERE p.post_type = %s AND p.post_status IN ({$statuses})",
                $table,
                $wpdb->posts,
                $objectType
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return [
            'all' => (int) ($row['total'] ?? 0),
            'unassigned' => (int) ($row['unassigned'] ?? 0),
        ];
    }

    public function subtreeCount(string $path, ?string $objectType = null): int
    {
        $wpdb = $this->wpdb;
        $folders = $wpdb->prefix . 'folderfolio_folders';
        $join = $this->statusJoin($objectType, 'a');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table, no core API; read live, and the cached reader (GalleryQuery) keys on the 'folderfolio' last_changed every write bumps; $join is statusJoin()'s prepare()d fragment.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT a.attachment_id)
                 FROM %i AS a
                 INNER JOIN %i AS f ON f.id = a.folder_id"
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $join is statusJoin()'s fragment, built with prepare().
                    . "{$join}
                 WHERE f.path LIKE %s",
                $this->table(),
                $folders,
                $wpdb->esc_like($path) . '%'
            )
        );
    }

    /**
     * Attachment ids in a folder and everything beneath it.
     *
     * @return list<int>
     */
    public function subtreeAttachmentIds(string $path): array
    {
        $wpdb = $this->wpdb;
        $folders = $wpdb->prefix . 'folderfolio_folders';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table, no core API; read live, and the cached reader (GalleryQuery) keys on the 'folderfolio' last_changed every write bumps.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT DISTINCT a.attachment_id
                 FROM %i AS a
                 INNER JOIN %i AS f ON f.id = a.folder_id
                 WHERE f.path LIKE %s',
                $this->table(),
                $folders,
                $wpdb->esc_like($path) . '%'
            )
        ) ?: [];

        return array_map('intval', $ids);
    }

    /**
     * Remove every assignment for a set of folders.
     *
     * Used when a subtree is deleted: one statement instead of one per folder.
     *
     * @param list<int> $folderIds
     * @return bool|WP_Error
     */
    public function deleteForFolders(array $folderIds): bool|WP_Error
    {
        if ($folderIds === []) {
            return true;
        }

        $wpdb = $this->wpdb;
        $placeholders = implode(',', array_fill(0, count($folderIds), '%d'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a write to our own table; changed() bumps the 'folderfolio' last_changed key.
        $deleted = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the sniff cannot count the spread ids that fill the %d list.
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only: one %d per folder id.
                "DELETE FROM %i WHERE folder_id IN ({$placeholders})",
                $this->table(),
                ...$folderIds
            )
        );

        if ($deleted === false) {
            return new WP_Error(
                'folderfolio_assignment_delete_failed',
                __('The folder entries could not be removed.', 'folderfolio')
            );
        }

        $this->changed();

        return true;
    }

    /**
     * File everything in one folder into another as well — a pasted copy's
     * half of "with files".
     *
     * One INSERT … SELECT, so a folder of ten thousand files is one statement
     * and not ten thousand. Each file keeps its position (`sort_order`), which
     * is what makes the copy a copy rather than the same files in a different
     * order. `import_run` is left null: these rows are a person's work, and an
     * undo of some earlier import must not reach into a folder that did not
     * exist when it ran. INSERT IGNORE, because the destination is a folder
     * created a moment ago in the same transaction and has no rows — a
     * collision would mean something else wrote there first, and the row it
     * wrote is the one to keep.
     *
     * Returns no count on purpose: MySQL's affected-rows here depends on what
     * the destination already held (trap 65). The caller read the source's
     * ids to check them, and that list is the number.
     */
    public function copyFolder(int $fromFolderId, int $toFolderId): bool|WP_Error
    {
        $wpdb = $this->wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a write to our own table; changed() bumps the 'folderfolio' last_changed key.
        $inserted = $wpdb->query(
            $wpdb->prepare(
                'INSERT IGNORE INTO %i
                     (folder_id, attachment_id, sort_order, assigned_at, import_run)
                 SELECT %d, attachment_id, sort_order, %s, NULL
                 FROM %i
                 WHERE folder_id = %d',
                $this->table(),
                $toFolderId,
                current_time('mysql', true),
                $this->table(),
                $fromFolderId
            )
        );

        if ($inserted === false) {
            return new WP_Error(
                'folderfolio_assignment_failed',
                __('The files could not be filed in the folder.', 'folderfolio')
            );
        }

        $this->changed();

        return true;
    }

    /**
     * Remove every assignment one import run created.
     *
     * This is how an import is undone without the run having stored a list of
     * everything it filed — there can be a hundred thousand pairs, and the run
     * lives in an option.
     *
     * It used to be a window on `assigned_at`, and that was wrong. `assigned_at`
     * is second-granular, so a file filed by hand in the same second the run
     * started was inside it; worse, so was anything filed by hand *during* a
     * long import, which screen 07 invites by saying the run continues after
     * you close the tab. Undo promises to keep what the import did not do, and
     * a time window cannot tell the difference. A column on the row can.
     *
     * @return int Rows removed.
     */
    public function deleteAssignedByRun(string $importRun): int
    {
        if ($importRun === '') {
            return 0;
        }

        $wpdb = $this->wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a write to our own table; changed() bumps the 'folderfolio' last_changed key.
        $deleted = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE import_run = %s',
                $this->table(),
                $importRun
            )
        );

        $this->changed();

        return (int) $deleted;
    }

    /**
     * Drop assignment rows that point at nothing.
     *
     * Two kinds, and they arrive by different routes. A row whose folder is
     * gone is left by a delete that failed part-way — the folder row went, the
     * assignments did not. A row whose attachment is gone is left by anything
     * that removed a post without firing `delete_attachment`: a direct SQL
     * delete, a migration, or a version of this plugin before that hook
     * existed.
     *
     * Doctor reports both and repairs neither; this is the repair, run from
     * the Status tab by somebody who has read what it found. Both statements
     * are NOT EXISTS rather than joins, because a LEFT JOIN … IS NULL delete
     * needs a different syntax on MySQL and MariaDB.
     *
     * @return int Rows removed.
     */
    public function deleteOrphans(): int
    {
        $wpdb = $this->wpdb;
        $table = $this->table();
        $folders = $wpdb->prefix . 'folderfolio_folders';

        // Identifiers cannot be bound as values — %i quotes them — and both
        // are built from $wpdb->prefix.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a write to our own table; changed() bumps the 'folderfolio' last_changed key.
        $removed = (int) $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i
                 WHERE NOT EXISTS (
                     SELECT 1 FROM %i f WHERE f.id = %i.folder_id
                 )',
                $table,
                $folders,
                $table
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a write to our own table; changed() bumps the 'folderfolio' last_changed key.
        $removed += (int) $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i
                 WHERE NOT EXISTS (
                     SELECT 1 FROM %i p WHERE p.ID = %i.attachment_id
                 )',
                $table,
                $wpdb->posts,
                $table
            )
        );

        $this->changed();

        return $removed;
    }
}
