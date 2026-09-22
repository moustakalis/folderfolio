<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Domain\FolderSorts;
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
     * List mode: upload.php?folderfolio_folder=12 on the main query.
     *
     * Read-only filtering from a GET parameter, so there is no nonce here;
     * the capability check is what matters.
     */
    public function applyToListMode(WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query() || !current_user_can('upload_files')) {
            return;
        }

        // post_type is a string on upload.php but the query var accepts an
        // array; comparing with !== would silently disable the filter.
        if (!in_array('attachment', (array) $query->get('post_type'), true)) {
            return;
        }

        $folderId = self::normalizeFolderId($_GET[self::QUERY_VAR] ?? null);

        if (null === $folderId) {
            return;
        }

        $query->set(self::QUERY_VAR, $folderId);

        foreach (self::ordering($folderId) as $key => $value) {
            $query->set($key, $value);
        }
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

        $query = $_REQUEST['query'] ?? null;

        if (!is_array($query)) {
            return $args;
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
     * @return array{orderby?: string, order?: string}
     */
    private static function ordering(int $folderId): array
    {
        if ($folderId <= 0) {
            return [];
        }

        $order = (new FolderSorts())->for($folderId)['files'];

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
            $clauses['where'] .= ' AND folderfolio_assignments.attachment_id IS NULL';

            return $clauses;
        }

        $clauses['join'] .= " INNER JOIN {$table} AS folderfolio_assignments"
            . " ON folderfolio_assignments.attachment_id = {$wpdb->posts}.ID";
        $clauses['where'] .= $wpdb->prepare(
            ' AND folderfolio_assignments.folder_id = %d',
            $folderId
        );

        return $clauses;
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
