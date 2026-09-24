<?php

declare(strict_types=1);

namespace FolderFolio\Domain;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Support\Capabilities;
use WP_Error;
use wpdb;

/**
 * Locked and pinned folders — tier 2 item 10.
 *
 * Both are one row each in `folderfolio_folder_meta` (primary key
 * `(folder_id, meta_key)`), the table `FolderSorts` already writes; a folder
 * that is neither has no row. No migration.
 *
 * ## What a lock is (Nick's answers, 23 Sep, board 3ZU8VGkJemznTvKp8tNnvY)
 *
 * It protects a folder's **shape**: no rename, move, reorder or delete, and
 * nothing created, cut or pasted inside it. Files still go in and out, and
 * its colour and *Sort inside* may still change — they change how a folder is
 * shown, not what it is. It covers **the whole subtree**: a lock that let
 * `Acme/Logos` be renamed or deleted would not have protected `Acme`'s shape.
 *
 * It is a **permission**, not a safety catch: it stops everyone except those
 * who have the `lock` ability, who lock, unlock and are not stopped by it.
 * Out of the box that is Administrator only — see `Settings::defaultRoles()`.
 *
 * ## Why the guard lives in the domain, not the routes
 *
 * A folder is created from seven places — the rail, paste, bulk-create, the
 * importer, a dropped directory, the CLI, the public API — and every one of
 * them ends in `FolderService`. A check at the REST layer would have to be
 * remembered in each; here it cannot be forgotten. The bypass is still the
 * current user's ability, asked through a callable so a test can say who is
 * asking.
 *
 * ## Pin
 *
 * A pinned folder sorts first in its level, whatever the sort — applied by
 * the client's `sortTree()`, which is where every level is ordered. Pinning
 * is a shared change to the order everyone sees, so it is `rename`
 * (Organise); and on a locked folder, since it moves the folder within its
 * level, it is refused like any other move unless the person holds `lock`.
 */
final class FolderLocks
{
    public const LOCKED = 'state:locked';

    public const PINNED = 'state:pinned';

    private wpdb $wpdb;

    /** @var callable(): bool */
    private $bypass;

    /** @var array<int, true>|null */
    private ?array $locked = null;

    /**
     * @param (callable(): bool)|null $bypass Whether the current request is
     *        exempt — the `lock` ability, unless a test says otherwise.
     *
     * WP-CLI is exempt too. A lock is a permission between the people who use
     * the admin; whoever runs `wp folderfolio` has the database already, and a
     * CLI run has no user at all, so without this every command would be
     * refused on a locked folder — including by the administrator who locked it.
     */
    public function __construct(?callable $bypass = null)
    {
        global $wpdb;

        $this->wpdb = $wpdb;
        $this->bypass = $bypass ?? static fn (): bool => (defined('WP_CLI') && constant('WP_CLI'))
            || Capabilities::can('lock');
    }

    private function table(): string
    {
        return $this->wpdb->prefix . 'folderfolio_folder_meta';
    }

    /**
     * Every folder carrying one of the two marks, as id → true.
     *
     * @return array<int, true>
     */
    private function ids(string $key): array
    {
        $wpdb = $this->wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table, no core API; locked() memoises the answer per request.
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT folder_id FROM %i WHERE meta_key = %s',
                $this->table(),
                $key
            )
        ) ?: [];

        $out = [];

        foreach ($rows as $id) {
            $out[(int) $id] = true;
        }

        return $out;
    }

    /**
     * Folders locked by their own mark (not by an ancestor's).
     *
     * @return array<int, true>
     */
    public function locked(): array
    {
        return $this->locked ??= $this->ids(self::LOCKED);
    }

    /**
     * @return array<int, true>
     */
    public function pinned(): array
    {
        return $this->ids(self::PINNED);
    }

    /**
     * Mark or unmark a folder. Idempotent.
     */
    public function set(int $folderId, string $key, bool $on): bool|WP_Error
    {
        $wpdb = $this->wpdb;

        $written = $on
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a write to our own table; it bumps the 'folderfolio' last_changed key below.
            ? $wpdb->query(
                $wpdb->prepare(
                    'REPLACE INTO %i (folder_id, meta_key, meta_value) VALUES (%d, %s, %s)',
                    $this->table(),
                    $folderId,
                    $key,
                    '1'
                )
            )
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a write to our own table; it bumps the 'folderfolio' last_changed key below.
            : $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM %i WHERE folder_id = %d AND meta_key = %s',
                    $this->table(),
                    $folderId,
                    $key
                )
            );

        $this->locked = null;

        if ($written === false) {
            return new WP_Error(
                'folderfolio_mark_failed',
                __('That could not be saved.', 'folderfolio'),
                ['status' => 500]
            );
        }

        wp_cache_set_last_changed('folderfolio');

        return true;
    }

    /**
     * The folder whose lock covers this path, or null — the topmost, because
     * that is the one somebody would have to unlock.
     *
     * `$path` is the id path the `path` column stores, `/12/40/41/`.
     */
    public function lockingId(string $path): ?int
    {
        $locked = $this->locked();

        if ($locked === []) {
            return null;
        }

        foreach (FolderPath::ids($path) as $id) {
            if (isset($locked[$id])) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Refuse a change to the shape at `$path`, unless this person may lock.
     *
     * Null when the change may go ahead. `$name` resolves the locking
     * folder's name for the sentence — the repository's, passed in so this
     * class needs no second reader of the folders table.
     *
     * @param callable(int): ?string $name
     */
    public function guard(string $path, callable $name): ?WP_Error
    {
        $id = $this->lockingId($path);

        if ($id === null || ($this->bypass)()) {
            return null;
        }

        return self::refusal($name($id) ?? '');
    }

    /**
     * Refuse when any folder under `$path` — itself included — is locked:
     * deleting a folder takes its whole subtree with it, or reparents its
     * children, and either changes the shape of whatever inside it is locked.
     *
     * @param list<int> $subtreeIds
     * @param callable(int): ?string $name
     */
    public function guardSubtree(array $subtreeIds, callable $name): ?WP_Error
    {
        if (($this->bypass)()) {
            return null;
        }

        $locked = $this->locked();

        foreach ($subtreeIds as $id) {
            if (isset($locked[$id])) {
                return self::refusal($name($id) ?? '');
            }
        }

        return null;
    }

    /**
     * Whether this person is exempt from locks — the `lock` ability.
     */
    public function exempt(): bool
    {
        return ($this->bypass)();
    }

    public static function refusal(string $name): WP_Error
    {
        return new WP_Error(
            'folderfolio_locked',
            sprintf(
                /* translators: %s: the locked folder's name. */
                __('“%s” is locked. Someone who can lock folders can unlock it.', 'folderfolio'),
                $name
            ),
            ['status' => 403]
        );
    }
}
