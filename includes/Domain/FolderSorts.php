<?php

declare(strict_types=1);

namespace FolderFolio\Domain;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Support\Settings;
use WP_Error;
use wpdb;

/**
 * The order a folder shows the things inside it in — tier 1 item 2.
 *
 * Two answers per folder and they are separate questions: how its subfolders
 * are arranged, and how its files come back. Both are stored in
 * `folderfolio_folder_meta`, whose primary key is `(folder_id, meta_key)`, so
 * a write is a `REPLACE INTO` and there is no read-modify-write to lose a
 * race on. `Modules\Import\Provenance` is the other user of that table and
 * the shape here follows it.
 *
 * ## Why this is stored and the global sort is not
 *
 * `store.ts` calls the global sort *"a property of how the site is
 * organised"* — a view that lasts a session, chosen by whoever is looking.
 * A per-folder order is not that. It is written down, everyone who opens the
 * site sees it, and it belongs to the folder in the same way its colour does.
 * That is why it needs the `rename` ability (the column headed **Organise**)
 * and the global sort needs none.
 *
 * ## One vocabulary, two scopes
 *
 * Folders and files take the same four labels — Name A–Z, Name Z–A, Newest,
 * Oldest — and `Custom order` exists only for folders, because it means "the
 * arrangement the folders carry themselves" and files have no arrangement
 * until tier 2 gives them one. Storing our own vocabulary rather than
 * WordPress's keeps `WP_Query`'s spelling out of the database;
 * `queryArgs()` is the only place the two meet.
 */
final class FolderSorts
{
    public const FOLDERS = 'sort:folders';
    public const FILES = 'sort:files';

    /** The scopes, as the REST route and the client name them. */
    public const SCOPES = ['folders', 'files'];

    /**
     * What a folder's subfolders can be ordered by: the tree's own five.
     *
     * Deliberately `Settings::SORTS` rather than a second list. The
     * per-folder order and the global one are the same kind of answer asked
     * at a different scope, and two lists would drift the first time a sixth
     * is added.
     */
    public const FOLDER_ORDERS = Settings::SORTS;

    /**
     * What a folder's files can be ordered by: the same four, without Custom.
     *
     * `folderfolio_folder_attachments.sort_order` exists and is already
     * `ORDER BY`'d, so manual file order is cheap when tier 2 reaches it —
     * but until something can write it, offering the option would be an
     * option that cannot be chosen.
     */
    public const FILE_ORDERS = ['name-asc', 'name-desc', 'newest', 'oldest'];

    private wpdb $wpdb;

    public function __construct()
    {
        global $wpdb;

        $this->wpdb = $wpdb;
    }

    private function table(): string
    {
        return $this->wpdb->prefix . 'folderfolio_folder_meta';
    }

    /** @return 'sort:folders'|'sort:files' */
    private static function key(string $scope): string
    {
        return $scope === 'files' ? self::FILES : self::FOLDERS;
    }

    /**
     * Both orders for one folder. Either may be null, meaning "follow the
     * global sort".
     *
     * @return array{folders: ?string, files: ?string}
     */
    public function for(int $folderId): array
    {
        /** @var list<array{meta_key: string, meta_value: ?string}> $rows */
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT meta_key, meta_value FROM {$this->table()}
                 WHERE folder_id = %d AND meta_key IN (%s, %s)",
                $folderId,
                self::FOLDERS,
                self::FILES
            ),
            ARRAY_A
        ) ?: [];

        $out = ['folders' => null, 'files' => null];

        foreach ($rows as $row) {
            $scope = $row['meta_key'] === self::FILES ? 'files' : 'folders';
            $out[$scope] = $row['meta_value'] === null ? null : (string) $row['meta_value'];
        }

        return $out;
    }

    /**
     * Every folder that has an order, keyed by id.
     *
     * One indexed query — `meta_key` carries a KEY — and the merge happens in
     * PHP. Deliberately *not* a join onto the folders query: `all()` is
     * `SELECT *` over every folder, most folders will never have a row here,
     * and two LEFT JOINs to pivot two keys onto one row costs more than
     * reading the handful of rows that exist and looking them up.
     *
     * @return array<int, array{folders: ?string, files: ?string}>
     */
    public function all(): array
    {
        /** @var list<array{folder_id: string, meta_key: string, meta_value: ?string}> $rows */
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT folder_id, meta_key, meta_value FROM {$this->table()}
                 WHERE meta_key IN (%s, %s)",
                self::FOLDERS,
                self::FILES
            ),
            ARRAY_A
        ) ?: [];

        $out = [];

        foreach ($rows as $row) {
            $id = (int) $row['folder_id'];
            $out[$id] ??= ['folders' => null, 'files' => null];
            $scope = $row['meta_key'] === self::FILES ? 'files' : 'folders';
            $out[$id][$scope] = $row['meta_value'] === null ? null : (string) $row['meta_value'];
        }

        return $out;
    }

    /**
     * Set or clear one order.
     *
     * `null` clears it, which is not the same as storing a default: a folder
     * with no row follows whatever the person is looking at, and that is the
     * state every folder starts in and can be returned to.
     */
    /**
     * `bool|WP_Error`, not `true|WP_Error`: the standalone `true` type is PHP
     * 8.2 and the analyser here resolves it as a class name in this
     * namespace. `FolderController::result()` already accepts a bool.
     */
    public function set(int $folderId, string $scope, ?string $order): bool|WP_Error
    {
        if (!in_array($scope, self::SCOPES, true)) {
            return new WP_Error(
                'folderfolio_sort_scope',
                __('A folder is sorted by subfolders or by files.', 'folderfolio'),
                ['status' => 400]
            );
        }

        $allowed = $scope === 'files' ? self::FILE_ORDERS : self::FOLDER_ORDERS;

        if ($order !== null && !in_array($order, $allowed, true)) {
            return new WP_Error(
                'folderfolio_sort_order',
                __('That is not an order this can be sorted by.', 'folderfolio'),
                ['status' => 400]
            );
        }

        if ($order === null) {
            $this->wpdb->query(
                $this->wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    "DELETE FROM {$this->table()} WHERE folder_id = %d AND meta_key = %s",
                    $folderId,
                    self::key($scope)
                )
            );

            return true;
        }

        // REPLACE INTO, not an UPDATE: the primary key is (folder_id,
        // meta_key), so this is the whole of "set it, whether or not it was
        // set before" in one statement.
        $written = $this->wpdb->query(
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "REPLACE INTO {$this->table()} (folder_id, meta_key, meta_value)
                 VALUES (%d, %s, %s)",
                $folderId,
                self::key($scope),
                $order
            )
        );

        if ($written === false) {
            return new WP_Error(
                'folderfolio_sort_failed',
                __('The order could not be saved.', 'folderfolio'),
                ['status' => 500]
            );
        }

        return true;
    }

    /**
     * Our vocabulary, in `WP_Query`'s spelling.
     *
     * The one place the two meet. `title` and `date` are both native orderby
     * values, so this never reaches `posts_clauses` — which matters, because
     * the readme's one claim a competitor cannot match is defended by a
     * negative control asserting the clauses array is byte-identical when no
     * folder is chosen.
     *
     * @return array{orderby: string, order: string}
     */
    public static function queryArgs(string $order): array
    {
        return match ($order) {
            'name-asc' => ['orderby' => 'title', 'order' => 'ASC'],
            'name-desc' => ['orderby' => 'title', 'order' => 'DESC'],
            'oldest' => ['orderby' => 'date', 'order' => 'ASC'],
            default => ['orderby' => 'date', 'order' => 'DESC'],
        };
    }
}
