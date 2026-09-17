<?php

declare(strict_types=1);

namespace FolderFolio\Blocks;

use FolderFolio\Domain\FolderService;
use WP_Post;
use WP_Query;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which attachments a gallery block shows.
 *
 * Kept apart from render.php because this is the part with consequences: it is
 * **the first code path in this plugin an unauthenticated visitor reaches**.
 * Everything else is behind `upload_files`. So it is a class with one job, it
 * can be tested directly, and the rules it has to keep are written where they
 * are enforced rather than in a template:
 *
 * - attachments only, `post_status = inherit`, and the query runs through
 *   WP_Query rather than SQL of ours, so core's own visibility rules — a
 *   private parent, a password-protected one — apply exactly as they do to any
 *   other query on the page.
 * - **Folders are not access control.** Putting a file in a folder does not
 *   make it private and never will. A gallery can only ever surface what the
 *   theme would surface anyway; that is a rule, not an implementation detail,
 *   and the docs say so in those words.
 * - nothing is read from the request. The block's attributes are the whole
 *   input, and they come from the post content.
 */
final class GalleryQuery
{
    /**
     * A ceiling, not a default.
     *
     * `limit: 0` means "the whole folder", and a folder with nine thousand
     * files in it would otherwise render nine thousand `<img>` tags into a
     * page. The number is high enough that no real gallery meets it and low
     * enough that a mistake stays a page rather than becoming an outage.
     */
    public const MAX = 500;

    /** @var list<string> */
    public const ORDER_BY = ['date', 'title', 'menu_order', 'rand'];

    private FolderService $folders;

    public function __construct(?FolderService $folders = null)
    {
        $this->folders = $folders ?? new FolderService();
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return list<WP_Post>
     */
    public function attachments(array $attributes): array
    {
        $ids = $this->attachmentIds($attributes);

        if ([] === $ids) {
            return [];
        }

        $orderBy = is_string($attributes['orderBy'] ?? null) ? $attributes['orderBy'] : 'date';
        $order = 'asc' === ($attributes['order'] ?? 'desc') ? 'ASC' : 'DESC';
        $limit = (int) ($attributes['limit'] ?? 0);

        if (!in_array($orderBy, self::ORDER_BY, true)) {
            $orderBy = 'date';
        }

        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post__in' => $ids,
            'orderby' => $orderBy,
            'order' => $order,
            // Always the ceiling, not the block's limit: the visibility filter
            // below removes rows, and slicing afterwards is what keeps
            // "show me six" meaning six.
            'posts_per_page' => self::MAX,
            'ignore_sticky_posts' => true,
            // Nothing paginates, so the second COUNT query is waste on a page
            // that is very often served from a full-page cache anyway.
            'no_found_rows' => true,
            'update_post_term_cache' => false,
        ]);

        /** @var list<WP_Post> $posts */
        $posts = $query->posts;

        $posts = $this->publiclyViewable($posts);

        return $limit > 0 ? array_slice($posts, 0, $limit) : $posts;
    }

    /**
     * Drop anything the theme would not show anyway.
     *
     * **WP_Query does not do this for us, and the test that says so was worth
     * writing.** `post_status = 'inherit'` returns an attachment whose parent
     * is private, draft or trashed, to a logged-out visitor — measured, not
     * assumed. Core's own gallery never hits this because its ids live in post
     * content; ours come from a folder, and a folder is not access control.
     *
     * `is_post_publicly_viewable()` is the right question because it is the
     * same question the rest of WordPress asks, and because it does not depend
     * on who is looking: an editor previewing the page sees exactly the
     * gallery a visitor will get. A gallery that shows the author one more
     * image than it shows the world is the worst version of this bug — it
     * publishes a page that does not contain what they saw.
     *
     * @param list<WP_Post> $posts
     *
     * @return list<WP_Post>
     */
    private function publiclyViewable(array $posts): array
    {
        $parents = array_values(array_unique(array_filter(
            array_map(static fn (WP_Post $post): int => (int) $post->post_parent, $posts)
        )));

        if ([] !== $parents) {
            // One query for the parents rather than one per image: the check
            // below reads each attachment's parent status.
            _prime_post_caches($parents, false, false);
        }

        return array_values(array_filter(
            $posts,
            static fn (WP_Post $post): bool => is_post_publicly_viewable($post)
        ));
    }

    /**
     * The folders' attachment ids, resolved once and cached.
     *
     * `wp_cache_get_last_changed()` is in the key, which is how core's own term
     * and comment queries do this: any write to the assignments table calls
     * `wp_cache_set_last_changed('folderfolio')` and every cached id set for
     * the old timestamp becomes unreachable in the same instant. Without a
     * persistent object cache this is a per-request cache and still worth
     * having — a page with four galleries on it resolves each folder once.
     *
     * @param array<string, mixed> $attributes
     *
     * @return list<int>
     */
    private function attachmentIds(array $attributes): array
    {
        $folderIds = array_values(array_filter(array_map(
            'intval',
            is_array($attributes['folderIds'] ?? null) ? $attributes['folderIds'] : []
        ), static fn (int $id): bool => $id > 0));

        if ([] === $folderIds) {
            return [];
        }

        $descendants = (bool) ($attributes['includeDescendants'] ?? false);
        $key = 'gallery:' . implode(',', $folderIds) . ':' . ($descendants ? '1' : '0')
            . ':' . wp_cache_get_last_changed('folderfolio');

        $cached = wp_cache_get($key, 'folderfolio');

        if (is_array($cached)) {
            /** @var list<int> $cached */
            return $cached;
        }

        $ids = [];

        foreach ($folderIds as $folderId) {
            foreach ($this->folders->attachmentIds($folderId, $descendants) as $attachmentId) {
                $ids[] = (int) $attachmentId;
            }
        }

        $ids = array_values(array_unique($ids));

        wp_cache_set($key, $ids, 'folderfolio');

        return $ids;
    }
}
