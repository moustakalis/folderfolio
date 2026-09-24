<?php

declare(strict_types=1);

namespace FolderFolio\Modules\Import;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Somebody else already filed this library.
 *
 * `Tree.tsx` rendered *"No folders yet"* into an empty rail, and the comment
 * beside it called that *"a library nobody has filed yet"*. On a site with
 * FileBird installed that sentence is not a missing feature — it is **untrue**.
 * `Catalog::detect()` could already see 77 folders and 89 assignments across
 * four plugins on the development site, and nothing anywhere said so.
 *
 * This is the one piece of state that fix needs, and it is shaped by two
 * costs.
 *
 * ## Why it is gated on our own folder count
 *
 * `Catalog::detect()` runs nine sources' existence checks and then counts the
 * ones that answered — far too heavy for every load of `upload.php`. But the
 * sentence is only ever wrong **when our own tree is empty**, and that is one
 * `COUNT(*)` on a table that is usually tiny. So the gate runs first and the
 * detection runs almost never: on a library that has been filed here, this
 * costs a single count and stops.
 *
 * ## Why the result is cached, and why nothing has to invalidate it
 *
 * On a library that has *not* been filed here, the gate opens on every load,
 * so the detection would run on every load. An hour's transient makes that one
 * set of queries per hour while somebody reads the screen.
 *
 * The obvious next thought is that an import must drop the cache. It must
 * not, and it is worth writing down why: an import creates folders *here*, so
 * the **gate** closes and `waiting()` returns null on the very next load
 * without consulting the cache at all. The same is true of a user simply
 * making a folder. Undo puts the count back to zero, but an import adds and
 * never moves, so the other plugin's data is exactly what it was and the
 * cached answer is still right. The only genuinely stale case is a rival's
 * data being destroyed within the hour, which the TTL covers.
 *
 * So there is no invalidation hook, and there should not be one. A cache
 * whose freshness is guaranteed by the condition that reads it does not need
 * a second mechanism that can be forgotten.
 *
 * A `false` transient is indistinguishable from a missing one, so "nothing to
 * say" is stored as a sentinel rather than as `false`.
 *
 * ## Why one vendor is named
 *
 * Nick's decision: *"20 folders from FileBird"* rather than Real Media
 * Library's unnamed *"another plugin for folders"*. Naming is permitted — the
 * wp.org ban covers readme tags (guideline 12) and slugs (17), not admin UI —
 * and a user cannot act on a plugin they have not been told the name of. The
 * largest source is the one named; the rest are counted, and the Import tab
 * lists all nine.
 */
final class Elsewhere
{
    private const CACHE = 'folderfolio_elsewhere';

    private const TTL = HOUR_IN_SECONDS;

    /** Stored in place of the array, because a `false` transient means "missing". */
    private const NOTHING = 'none';

    /**
     * What is waiting somewhere else, or null when there is nothing to say.
     *
     * Null in three different situations, deliberately conflated because the
     * caller does the same thing in all three: this library already has
     * folders of ours; no other plugin has any data; or the tables are not
     * readable. The caller's question is "is there a true sentence to put
     * here", and the answer is no.
     *
     * @return array{key: string, label: string, folders: int, files: int, others: int}|null
     */
    public static function waiting(): ?array
    {
        if (self::foldersHere() > 0) {
            return null;
        }

        /** @var array{key: string, label: string, folders: int, files: int, others: int}|string|false $cached */
        $cached = get_transient(self::CACHE);

        if (self::NOTHING === $cached) {
            return null;
        }

        if (is_array($cached)) {
            return $cached;
        }

        $found = self::look();

        set_transient(self::CACHE, $found ?? self::NOTHING, self::TTL);

        return $found;
    }

    /**
     * The same, shaped for `window.folderFolio`.
     *
     * The Import tab is a `manage_options` screen, so an editor who can upload
     * files sees the sentence and no button — an empty URL rather than a link
     * that would send them to a screen they cannot open. The sentence is still
     * worth saying to them: it explains where their folders went.
     *
     * The URL is added here rather than inside the cached value, because a
     * capability is not a property of the site's data.
     *
     * @return array{key: string, label: string, folders: int, files: int, others: int, importUrl: string}|null
     */
    public static function forConfig(): ?array
    {
        $found = self::waiting();

        if (null === $found) {
            return null;
        }

        $found['importUrl'] = current_user_can('manage_options')
            ? esc_url_raw(admin_url('admin.php?page=folderfolio&tab=import'))
            : '';

        return $found;
    }

    /**
     * @return array{key: string, label: string, folders: int, files: int, others: int}|null
     */
    private static function look(): ?array
    {
        return self::pick(Catalog::detect());
    }

    /**
     * Which of the detected sources to name, and how many others there are.
     *
     * Separate from the query above and public because it is the half with a
     * decision in it, and the half a test can reach: the queries need four
     * competitors' tables in the database, and this needs nine arrays.
     *
     * The largest by folder count wins, with the file count breaking a tie —
     * the one worth naming is the one holding most of somebody's work. The
     * rest are counted rather than listed, because the sentence names one
     * vendor and the Import tab lists all nine.
     *
     * @param list<array<string, mixed>> $detected Catalog::detect()'s rows.
     * @return array{key: string, label: string, folders: int, files: int, others: int}|null
     */
    public static function pick(array $detected): ?array
    {
        $withData = array_values(array_filter(
            $detected,
            static fn (array $source): bool => (bool) ($source['has_data'] ?? false)
        ));

        if ([] === $withData) {
            return null;
        }

        usort(
            $withData,
            static fn (array $a, array $b): int
                => [(int) $b['folders'], (int) $b['assignments']]
                <=> [(int) $a['folders'], (int) $a['assignments']]
        );

        $first = $withData[0];

        return [
            'key' => (string) $first['key'],
            'label' => (string) $first['label'],
            'folders' => (int) $first['folders'],
            'files' => (int) $first['assignments'],
            'others' => count($withData) - 1,
        ];
    }

    /**
     * Our own folders, counted directly.
     *
     * Not `FolderRepository::all()`, which builds objects for every row: the
     * question here is whether the number is zero.
     */
    private static function foldersHere(): int
    {
        global $wpdb;

        // Identifiers cannot be bound as values — %i quotes one — and this one
        // is built from $wpdb->prefix.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table; a live count, whether it is zero.
        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->prefix . 'folderfolio_folders')
        );
    }
}
