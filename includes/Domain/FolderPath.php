<?php

declare(strict_types=1);

namespace FolderFolio\Domain;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Materialised path arithmetic.
 *
 * A folder's path is its ancestor ids and its own id, slash-delimited, with a
 * leading AND a trailing slash:
 *
 *     Brand      id 1    /1/
 *     Logos      id 7    /1/7/
 *     Primary    id 12   /1/7/12/
 *
 * The trailing slash is not cosmetic. The subtree of folder 7 is
 * `path LIKE '/1/7/%'`; folder 70's path is `/1/70/`, which does not match,
 * because the pattern requires a literal `/` where `/1/70/` has a `0`. Drop
 * the trailing slash and every id that is a string-prefix of another leaks
 * into its subtree. `FolderPathTest` covers exactly that case.
 *
 * `parent_id` remains the source of truth; this is a derived index that makes
 * subtree reads, moves, deletes and breadcrumbs non-recursive.
 *
 * Deliberately free of WordPress: no functions, no globals, no $wpdb. It is
 * unit-testable without a WordPress bootstrap, which is the point.
 */
final class FolderPath
{
    /**
     * Maximum nesting depth.
     *
     * "Unlimited nested folders" is the product promise, but an unbounded
     * claim in a VARCHAR(255) column is a truncation bug waiting to happen.
     * 20 levels is far past any real filing structure and leaves ample room
     * in the column; exceeding it produces an error, never a silent trim.
     */
    public const MAX_DEPTH = 20;

    public const SEPARATOR = '/';

    /**
     * Build a child path from its parent's path.
     *
     * A root folder (no parent) gets `/<id>/`.
     */
    public static function build(?string $parentPath, int $id): string
    {
        if ($parentPath === null || $parentPath === '' || $parentPath === self::SEPARATOR) {
            return self::SEPARATOR . $id . self::SEPARATOR;
        }

        return $parentPath . $id . self::SEPARATOR;
    }

    /**
     * Depth of a path. Root folders are depth 0.
     */
    public static function depth(string $path): int
    {
        $ids = self::ids($path);

        return $ids === [] ? 0 : count($ids) - 1;
    }

    /**
     * Every id in the path, ancestors first, the folder's own id last.
     *
     * @return list<int>
     */
    public static function ids(string $path): array
    {
        $trimmed = trim($path, self::SEPARATOR);

        if ($trimmed === '') {
            return [];
        }

        return array_map('intval', explode(self::SEPARATOR, $trimmed));
    }

    /**
     * Ancestor ids, outermost first, excluding the folder itself.
     *
     * This is what makes a breadcrumb free: the answer is already in the row.
     *
     * @return list<int>
     */
    public static function ancestorIds(string $path): array
    {
        $ids = self::ids($path);

        array_pop($ids);

        return $ids;
    }

    /**
     * The path of this folder's parent, or null when it is a root folder.
     */
    public static function parentPath(string $path): ?string
    {
        $ids = self::ancestorIds($path);

        if ($ids === []) {
            return null;
        }

        return self::SEPARATOR . implode(self::SEPARATOR, $ids) . self::SEPARATOR;
    }

    /**
     * SQL LIKE pattern matching a folder and everything beneath it.
     *
     * `%` matches the empty string, so the folder's own path matches too.
     */
    public static function subtreePattern(string $path): string
    {
        return $path . '%';
    }

    /**
     * Is $path inside $ancestorPath's subtree (or equal to it)?
     */
    public static function isWithin(string $path, string $ancestorPath): bool
    {
        return str_starts_with($path, $ancestorPath);
    }

    /**
     * Rewrite a descendant's path when its subtree root moves.
     *
     * Moving `/1/7/` to `/3/9/7/` turns the descendant `/1/7/12/` into
     * `/3/9/7/12/` by swapping the prefix and keeping the tail.
     */
    public static function rewrite(string $path, string $oldPrefix, string $newPrefix): string
    {
        if (!self::isWithin($path, $oldPrefix)) {
            return $path;
        }

        return $newPrefix . substr($path, strlen($oldPrefix));
    }

}
