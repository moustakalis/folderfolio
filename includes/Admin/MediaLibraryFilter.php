<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Domain\FolderSorts;
use FolderFolio\Domain\SmartFolders;
use FolderFolio\Domain\SmartRules;
use FolderFolio\Support\FileSizes;
use FolderFolio\Support\Capabilities;
use FolderFolio\Support\PostTypes;
use WP_Query;

/**
 * Folder filtering for the Media Library, applied in SQL.
 *
 * The filter has to reach the query itself: hiding attachment tiles in the DOM
 * only filters the page you are looking at, so counts stay wrong and the next
 * page of results brings the hidden media straight back.
 *
 * Two entry points, because the library has two:
 *  - list mode reads the folder from the URL on the main query;
 *  - grid mode posts it inside the query-attachments AJAX payload.
 *
 * Both end up as one query var, which posts_clauses turns into a join.
 */
final class MediaLibraryFilter
{
    /**
     * Query var carrying the folder being filtered on.
     *
     * Absent means no filter. Zero means "media in no folder at all", which is
     * why this cannot use absint() alone.
     */
    public const QUERY_VAR = 'folderfolio_folder';

    /**
     * Query var set when the folder being shown is in Custom file order.
     *
     * A var of ours rather than an `orderby` value, because WP_Query's
     * vocabulary has no word for "each file's position in this folder" and
     * would quietly fall back to the date if given one it did not know.
     */
    public const ORDER_VAR = 'folderfolio_order';

    /**
     * Query var carrying a smart folder's id — tier 3 item 13. Absent means no
     * smart folder; like the folder var, its absence is the bail.
     */
    public const SMART_VAR = 'folderfolio_smart';

    /**
     * Rules to apply directly, without a saved smart folder — the count and
     * the editor's live preview. Never read from a request: only code that
     * has already sanitised the rules sets it (`SmartFolders::count()`).
     */
    public const SMART_RULES_VAR = 'folderfolio_smart_rules';

    public function register(): void
    {
        add_action('pre_get_posts', [$this, 'applyToListMode']);
        add_filter('ajax_query_attachments_args', [$this, 'applyToGridMode']);

        // Registered unconditionally: the two entry points above are admin-only,
        // but anything that sets the query var directly - the REST media
        // endpoints, a theme, our own future code - should get the same join.
        add_filter('posts_clauses', [$this, 'joinFolderAssignments'], 10, 2);
    }

    /**
     * List mode: upload.php?folderfolio_folder=12 on the main query — and,
     * since tier 3 item 12, edit.php?post_type=page&folderfolio_folder=12.
     *
     * Read-only filtering from a GET parameter, so there is no nonce here;
     * the capability check is what matters.
     */
    public function applyToListMode(WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $type = self::listType($query);

        if ($type === null || !Capabilities::canUseFolders($type)) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a read-only filter; normalizeFolderId() is the sanitiser, an int or null
        $smartId = self::normalizeFolderId(wp_unslash($_GET[self::SMART_VAR] ?? null));

        if (null !== $smartId && $smartId > 0) {
            $query->set(self::SMART_VAR, $smartId);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a read-only filter; normalizeFolderId() is the sanitiser, an int or null
        $folderId = self::normalizeFolderId(wp_unslash($_GET[self::QUERY_VAR] ?? null));

        if (null === $folderId) {
            return;
        }

        $query->set(self::QUERY_VAR, $folderId);

        // A column header the person clicked is an order they chose on this
        // screen, and it beats the folder's own. Before tier 2 the folder's
        // won silently: clicking Title in a folder sorted by date did nothing.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only whether a sort was asked for
        if (isset($_GET['orderby'])) {
            return;
        }

        foreach (self::ordering($folderId) as $key => $value) {
            $query->set($key, $value);
        }
    }

    /**
     * The one type a main query lists, or null.
     *
     * post_type is a string on upload.php but the query var accepts an
     * array; comparing with !== would silently disable the filter. Media wins
     * when it is among several, as before item 12; any other type has to be
     * the only one — a query over posts *and* pages is not a screen with one
     * folder tree.
     */
    private static function listType(WP_Query $query): ?string
    {
        $types = array_values(array_filter((array) $query->get('post_type'), 'is_string'));

        if (in_array(PostTypes::MEDIA, $types, true)) {
            return PostTypes::MEDIA;
        }

        return count($types) === 1 ? $types[0] : null;
    }

    /**
     * Grid mode: wp_ajax_query_attachments() intersects the posted query
     * against a fixed key list, so a custom key never reaches $args. The raw
     * request is the only place it survives.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function applyToGridMode(array $args): array
    {
        if (!current_user_can('upload_files')) {
            return $args;
        }

        // Core's own query-attachments request, which checked its nonce-free
        // capability above; every key read from it is cast where it is used.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $query = wp_unslash($_REQUEST['query'] ?? null);

        if (!is_array($query)) {
            return $args;
        }

        $smartId = self::normalizeFolderId($query[self::SMART_VAR] ?? null);

        if (null !== $smartId && $smartId > 0) {
            $args[self::SMART_VAR] = $smartId;
        }

        $folderId = self::normalizeFolderId($query[self::QUERY_VAR] ?? null);

        if (null === $folderId) {
            return $args;
        }

        $args[self::QUERY_VAR] = $folderId;

        return array_merge($args, self::ordering($folderId));
    }

    /**
     * The order this folder shows its files in, if it has been given one.
     *
     * **The bail is the feature, and it has to cover ordering exactly as it
     * covers filtering.** Both callers above have already returned when no
     * folder was chosen, which is what keeps the promise in the readme — that
     * this plugin never touches a library nobody filtered. An order quietly
     * applied to an unfiltered library would be the same broken promise
     * wearing different clothes, so this is reached only from inside that
     * guard and never from a filter of its own.
     *
     * Zero is Unassigned, which is a real selection but not a folder, so it
     * has nothing to read an order from.
     *
     * `orderby` and `order` and nothing else. They are native WP_Query
     * arguments, so this never reaches `posts_clauses` — whose negative
     * control asserts the clauses array is byte-identical, and would fail the
     * moment an ORDER BY was appended there.
     *
     * Custom is our own var, not `orderby` — see ORDER_VAR — and it reaches
     * `posts_clauses` only through the folder join, so the unfiltered library
     * is still never touched.
     *
     * Public because FolderService::orderedAttachmentIds() asks WP_Query for
     * a folder's files in exactly the order the library shows them, and a
     * second copy of this mapping would be the one that drifted.
     *
     * @return array{orderby?: string, order?: string, folderfolio_order?: string}
     */
    public static function ordering(int $folderId): array
    {
        if ($folderId <= 0) {
            return [];
        }

        $order = (new FolderSorts())->for($folderId)['files'];

        if ($order === 'custom') {
            return [self::ORDER_VAR => 'custom'];
        }

        return $order === null ? [] : FolderSorts::queryArgs($order);
    }

    /**
     * Turn the query var into a join.
     *
     * The assignments table is keyed on (folder_id, attachment_id), so an inner
     * join against one folder matches at most one row per attachment and needs
     * no DISTINCT.
     *
     * @param array<string, string> $clauses
     * @return array<string, string>
     */
    public function joinFolderAssignments(array $clauses, WP_Query $query): array
    {
        return self::applySmart(self::applyFolder($clauses, $query), $query);
    }

    /**
     * A smart folder's rules as `WHERE` fragments (`Domain\SmartRules`).
     *
     * The same bail as the folder join: no smart folder asked for, clauses
     * untouched. A smart folder that no longer exists matches nothing — a
     * stale link shows an empty view rather than the whole library under a
     * name it no longer has.
     *
     * @param array<string, string> $clauses
     * @return array<string, string>
     */
    private static function applySmart(array $clauses, WP_Query $query): array
    {
        $rules = $query->get(self::SMART_RULES_VAR);

        if (!is_array($rules) || $rules === []) {
            $smartId = (int) $query->get(self::SMART_VAR);

            if ($smartId <= 0) {
                return $clauses;
            }

            $smart = (new SmartFolders())->get($smartId);

            if ($smart === null) {
                $clauses['where'] .= ' AND 1 = 0';

                return $clauses;
            }

            $rules = $smart['rules'];
        }

        /** @var list<array{field: string, op: string, value: int|string}> $rules */
        if (SmartRules::needsSizes($rules)) {
            FileSizes::backfill();
        }

        $clauses['where'] .= SmartRules::where($rules) . self::cacheVersion();

        return $clauses;
    }

    /**
     * @param array<string, string> $clauses
     * @return array<string, string>
     */
    private static function applyFolder(array $clauses, WP_Query $query): array
    {
        $folderId = $query->get(self::QUERY_VAR);

        if ('' === $folderId || null === $folderId) {
            return $clauses;
        }

        global $wpdb;

        $folderId = (int) $folderId;
        $table = $wpdb->prefix . 'folderfolio_attachment_folders';

        if (0 === $folderId) {
            $clauses['join'] .= " LEFT JOIN {$table} AS folderfolio_assignments"
                . " ON folderfolio_assignments.attachment_id = {$wpdb->posts}.ID";
            $clauses['where'] .= ' AND folderfolio_assignments.attachment_id IS NULL' . self::cacheVersion();

            return $clauses;
        }

        $clauses['join'] .= " INNER JOIN {$table} AS folderfolio_assignments"
            . " ON folderfolio_assignments.attachment_id = {$wpdb->posts}.ID";
        $clauses['where'] .= $wpdb->prepare(
            ' AND folderfolio_assignments.folder_id = %d',
            $folderId
        );

        // A write to our tables is a new WP_Query cache key; see cacheVersion().
        $clauses['where'] .= self::cacheVersion();

        // Each file's place in this folder. Newest first breaks a tie, which
        // only files never arranged share — and it is the order they were
        // in before anybody arranged anything.
        if ('custom' === $query->get(self::ORDER_VAR)) {
            $clauses['orderby'] = "folderfolio_assignments.sort_order ASC, {$wpdb->posts}.post_date DESC, {$wpdb->posts}.ID DESC";
        }

        return $clauses;
    }

    /**
     * Our tables' last-changed, as an SQL comment for the cache key.
     *
     * WP_Query caches a query's ids under its SQL and the *posts*
     * last-changed, and filing a file changes neither, so a folder view — or
     * Unassigned — kept returning the ids it had before the write until some
     * post changed: for the rest of the request always, and for good on a
     * site with a persistent object cache. Found on 23 Sep by a test that
     * arranged a folder twice in one request. Only ever on the folder join:
     * the unfiltered library's clauses are untouched.
     */
    private static function cacheVersion(): string
    {
        return ' /* folderfolio ' . preg_replace('/[^0-9. ]/', '', (string) wp_cache_get_last_changed('folderfolio')) . ' */';
    }

    /**
     * Normalise a requested folder id.
     *
     * Returns null when no filter was requested, so that folder 0 - the
     * unassigned pseudo-folder - stays distinguishable from "not filtering".
     *
     * Public and static because FolderSelect has to decide which option is
     * selected from the same raw value this class filters on. Two copies of
     * this would work until one of them started treating '0' as absent, and
     * then the Unassigned option would stop looking selected on the one screen
     * that shows it.
     */
    public static function normalizeFolderId(mixed $value): ?int
    {
        if (null === $value || '' === $value || is_array($value)) {
            return null;
        }

        $value = sanitize_text_field(wp_unslash((string) $value));

        if (!is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }
}
