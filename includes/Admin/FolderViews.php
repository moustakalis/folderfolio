<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Domain\FolderRepository;
use FolderFolio\Support\Capabilities;
use FolderFolio\Support\PostTypes;

/**
 * The status line above a post list — *All | Published | Drafts* — inside a
 * folder. Review item #24; Nick's pick on 25 Sep, option C with a lead-in.
 *
 * Core's line counts the whole post type and none of its links carries the
 * folder, so inside *Launch* (3 of 12 posts) it read *All (12) | Drafts (2)*
 * and *Drafts* left the folder without a word. With a folder selected this
 * rebuilds the line for the folder:
 *
 *     📁 In Launch:  All (3) | Published (2) | Drafts (1)
 *
 * - every link keeps the folder, and every count is counted inside it;
 * - a status with nothing in the folder drops out, as core drops an empty
 *   status for the type;
 * - the lead-in says whose counts they are. It sits inside the *All* item,
 *   not in an item of its own: core joins the items with " |", so an item of
 *   our own would grow a stray bar (trap 128).
 *
 * **With no folder selected nothing here runs** — the readme's first claim,
 * that FolderFolio never changes a screen unless you pick a folder, holds on
 * this line too. The month filter is left as core has it.
 *
 * Counts follow `wp_count_posts( $type, 'readable' )`: someone who cannot read
 * others' private posts counts only their own. *Mine* and *Sticky* follow
 * core's rules for showing them. The labels are core's own strings, so they
 * arrive in the site's language with core's plural forms.
 */
final class FolderViews
{
    public function register(): void
    {
        add_action('load-edit.php', [$this, 'registerForPostType']);
    }

    public function registerForPostType(): void
    {
        global $typenow;

        $type = is_string($typenow) && '' !== $typenow ? $typenow : 'post';

        if (PostTypes::MEDIA === $type || !Capabilities::canUseFolders($type)) {
            return;
        }

        add_filter("views_edit-{$type}", fn ($views) => $this->filter((array) $views, $type));
    }

    /**
     * @param array<string, string> $views Core's, keyed by status.
     * @return array<string, string>
     */
    public function filter(array $views, string $type): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a read-only filter; normalizeFolderId() is the sanitiser, an int or null
        $folderId = MediaLibraryFilter::normalizeFolderId(wp_unslash($_GET[MediaLibraryFilter::QUERY_VAR] ?? null));

        if (null === $folderId || [] === $views) {
            return $views;
        }

        $name = $this->folderName($folderId, $type);

        if (null === $name) {
            return $views;
        }

        $counts = $this->counts($folderId, $type);
        $links = [];
        $all = 0;

        foreach (get_post_stati(['show_in_admin_all_list' => true]) as $status) {
            $all += $counts['status'][$status] ?? 0;
        }

        // Which view is current, read from the request as core reads it.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view of the list; sanitize_key() is the sanitiser
        $status = isset($_REQUEST['post_status']) ? sanitize_key(wp_unslash((string) $_REQUEST['post_status'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view of the list; absint() is the sanitiser
        $author = isset($_GET['author']) ? absint(wp_unslash($_GET['author'])) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view of the list; only whether it is set
        $sticky = !empty($_REQUEST['show_sticky']);

        $base = ['post_type' => $type, MediaLibraryFilter::QUERY_VAR => $folderId];
        $me = get_current_user_id();

        $links['all'] = [
            'url' => add_query_arg($base, 'edit.php'),
            'label' => sprintf(
                /* translators: %s: Number of posts. Core's own string, so it arrives translated. */
                _nx('All <span class="count">(%s)</span>', 'All <span class="count">(%s)</span>', $all, 'posts', 'default'), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- core's label for this link, reused so the line reads as core's does in every language
                number_format_i18n($all)
            ),
            'current' => '' === $status && 0 === $author && !$sticky,
        ];

        if ($counts['mine'] > 0 && $counts['mine'] !== $all) {
            $links['mine'] = [
                'url' => add_query_arg($base + ['author' => $me], 'edit.php'),
                'label' => sprintf(
                    /* translators: %s: Number of posts. Core's own string. */
                    _nx('Mine <span class="count">(%s)</span>', 'Mine <span class="count">(%s)</span>', $counts['mine'], 'posts', 'default'), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- core's label, reused as above
                    number_format_i18n($counts['mine'])
                ),
                'current' => $author === $me,
            ];

            // Core marks All as the whole list when Mine is offered.
            $links['all']['url'] = add_query_arg(['all_posts' => 1], $links['all']['url']);
        }

        foreach (get_post_stati(['show_in_admin_status_list' => true], 'objects') as $object) {
            $n = $counts['status'][$object->name] ?? 0;

            if (0 === $n) {
                continue;
            }

            $links[$object->name] = [
                'url' => add_query_arg($base + ['post_status' => $object->name], 'edit.php'),
                'label' => sprintf(translate_nooped_plural($object->label_count, $n), number_format_i18n($n)),
                'current' => $status === $object->name,
            ];
        }

        if ($counts['sticky'] > 0) {
            $link = [
                'sticky' => [
                    'url' => add_query_arg($base + ['show_sticky' => 1], 'edit.php'),
                    'label' => sprintf(
                        /* translators: %s: Number of posts. Core's own string. */
                        _nx('Sticky <span class="count">(%s)</span>', 'Sticky <span class="count">(%s)</span>', $counts['sticky'], 'posts', 'default'), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- core's label, reused as above
                        number_format_i18n($counts['sticky'])
                    ),
                    'current' => $sticky,
                ],
            ];

            // Where core puts it: after Published, or after All.
            $split = 1 + (int) array_search(isset($links['publish']) ? 'publish' : 'all', array_keys($links), true);
            $links = array_merge(array_slice($links, 0, $split), $link, array_slice($links, $split));
        }

        $out = [];

        foreach ($links as $key => $link) {
            $out[$key] = sprintf(
                '<a href="%s"%s>%s</a>',
                esc_url($link['url']),
                $link['current'] ? ' class="current" aria-current="page"' : '',
                $link['label']
            );
        }

        $out['all'] = $this->leadIn($name) . ' ' . $out['all'];

        return $out;
    }

    /**
     * "📁 In Launch:" — real text, so a screen reader reads whose counts
     * follow; the glyph is decoration. A long name is clipped by the style
     * sheet, and the whole name is its title.
     */
    private function leadIn(string $name): string
    {
        return sprintf(
            '<span class="folderfolio-views-in"><span class="dashicons dashicons-category" aria-hidden="true"></span>%s</span>',
            sprintf(
                /* translators: %s: a folder's name. Leads the status links above a post list, whose counts are this folder's. */
                esc_html__('In %s:', 'folderfolio'),
                sprintf(
                    '<span class="folderfolio-views-in__name" title="%1$s">%2$s</span>',
                    esc_attr($name),
                    esc_html($name)
                )
            )
        );
    }

    /**
     * The folder's name, or null when it is not a folder of this type — a
     * stale link keeps core's line rather than a line about nothing.
     */
    private function folderName(int $folderId, string $type): ?string
    {
        if (0 === $folderId) {
            return __('Unassigned', 'folderfolio');
        }

        $row = (new FolderRepository())->find($folderId);

        if (null === $row || (string) $row['object_type'] !== $type) {
            return null;
        }

        return (string) $row['name'];
    }

    /**
     * One grouped query for the statuses and *Mine*, one for *Sticky*.
     *
     * @return array{status: array<string, int>, mine: int, sticky: int}
     */
    private function counts(int $folderId, string $type): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'folderfolio_attachment_folders';
        $me = get_current_user_id();
        $object = get_post_type_object($type);

        // wp_count_posts( $type, 'readable' ): without read_private_posts, a
        // private post counts only for its author.
        $readAll = null === $object || current_user_can($object->cap->read_private_posts) ? 1 : 0;

        // Unassigned is every post of the type filed nowhere; a folder is its
        // rows. The assignments table holds post ids of every type, and an id
        // is unique across types.
        if (0 === $folderId) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- counts for this page load; our join has no core API.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.post_status, COUNT(*) AS n, SUM(p.post_author = %d) AS mine
                     FROM %i p LEFT JOIN %i a ON a.attachment_id = p.ID
                     WHERE p.post_type = %s AND a.attachment_id IS NULL
                       AND (%d = 1 OR p.post_status <> 'private' OR p.post_author = %d)
                     GROUP BY p.post_status",
                    $me,
                    $wpdb->posts,
                    $table,
                    $type,
                    $readAll,
                    $me
                ),
                ARRAY_A
            ) ?: [];
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- counts for this page load; our join has no core API.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.post_status, COUNT(*) AS n, SUM(p.post_author = %d) AS mine
                     FROM %i p INNER JOIN %i a ON a.attachment_id = p.ID AND a.folder_id = %d
                     WHERE p.post_type = %s
                       AND (%d = 1 OR p.post_status <> 'private' OR p.post_author = %d)
                     GROUP BY p.post_status",
                    $me,
                    $wpdb->posts,
                    $table,
                    $folderId,
                    $type,
                    $readAll,
                    $me
                ),
                ARRAY_A
            ) ?: [];
        }

        $status = [];
        $mine = 0;
        $counted = get_post_stati(['show_in_admin_all_list' => true]);

        foreach ($rows as $row) {
            $name = (string) $row['post_status'];
            $status[$name] = (int) $row['n'];

            // Mine counts what All counts — not the trash, not auto-drafts.
            if (in_array($name, $counted, true)) {
                $mine += (int) $row['mine'];
            }
        }

        return ['status' => $status, 'mine' => $mine, 'sticky' => $this->sticky($folderId, $type)];
    }

    /**
     * Sticky posts in the folder — only posts can be sticky.
     */
    private function sticky(int $folderId, string $type): int
    {
        global $wpdb;

        $ids = 'post' === $type ? array_filter(array_map('intval', (array) get_option('sticky_posts', []))) : [];

        if ([] === $ids) {
            return 0;
        }

        $table = $wpdb->prefix . 'folderfolio_attachment_folders';
        $list = implode(',', $ids);

        if (0 === $folderId) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a count for this page load; our join has no core API.
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i p LEFT JOIN %i a ON a.attachment_id = p.ID
                     WHERE p.post_type = %s AND a.attachment_id IS NULL
                       AND p.post_status NOT IN ('trash', 'auto-draft') AND FIND_IN_SET(p.ID, %s)",
                    $wpdb->posts,
                    $table,
                    $type,
                    $list
                )
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a count for this page load; our join has no core API.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i p INNER JOIN %i a ON a.attachment_id = p.ID AND a.folder_id = %d
                 WHERE p.post_type = %s
                   AND p.post_status NOT IN ('trash', 'auto-draft') AND FIND_IN_SET(p.ID, %s)",
                $wpdb->posts,
                $table,
                $folderId,
                $type,
                $list
            )
        );
    }
}
