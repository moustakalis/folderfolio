<?php

declare(strict_types=1);

namespace FolderFolio\Domain;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The pure half of pasting a copy: what the copy is called, and where it goes.
 *
 * Kept apart from FolderService so the unit suite can reach it without
 * WordPress — both questions have edges (a name at the 191-character limit, a
 * level that was arranged while the menu was open) that nobody arranges on a
 * dev site on purpose.
 */
final class FolderCopy
{
    /** The column is VARCHAR(191); validate() refuses anything longer. */
    public const MAX_NAME = 191;

    /**
     * The first free name for a copy, the Finder rule.
     *
     * `Brand` when nothing beside it is called that — a copy pasted somewhere
     * else keeps its name, because there is nothing to tell apart. Then
     * `Brand copy`, then `Brand copy 2`, `Brand copy 3`. Only the top folder of
     * a pasted subtree ever comes through here: everything beneath it lands
     * under a parent that did not exist a moment ago.
     *
     * `$taken` is the authority, not a list of names read beforehand: it is
     * `FolderRepository::siblingNameExists()`, which compares under the
     * table's collation. A PHP-side comparison would disagree with MySQL on
     * case and accents, and the disagreement would surface as the paste
     * failing on a duplicate — trap 69, and the reason this takes a callable.
     *
     * The two patterns arrive translated, so the order of name and suffix is
     * the translator's. The name is shortened — never the suffix — until the
     * result fits the column, because a copy whose "copy" was cut off is
     * indistinguishable from its source.
     *
     * @param callable(string): bool $taken
     * @param string $single   e.g. "%s copy"
     * @param string $numbered e.g. "%1$s copy %2$d"
     */
    public static function name(string $name, callable $taken, string $single, string $numbered): string
    {
        if (!$taken($name)) {
            return $name;
        }

        $candidate = self::fit($name, static fn (string $base): string => sprintf($single, $base));

        for ($n = 2; $taken($candidate); $n++) {
            $candidate = self::fit($name, static fn (string $base): string => sprintf($numbered, $base, $n));
        }

        return $candidate;
    }

    /**
     * The level a copy lands in, in the order the person sees it.
     *
     * The client sends the level with a `0` where the copy goes — it has no id
     * yet — and this puts the real one there. Exactly one placeholder, and
     * no id twice; anything else is a request that does not describe a level.
     *
     * @param list<int> $order
     * @return list<int>|null Null when the list is malformed.
     */
    public static function place(array $order, int $copyId): ?array
    {
        $placeholders = array_keys($order, 0, true);

        if (count($placeholders) !== 1) {
            return null;
        }

        $real = array_values(array_filter($order, static fn (int $id): bool => $id !== 0));

        if (count($real) !== count(array_unique($real)) || in_array($copyId, $real, true)) {
            return null;
        }

        $placed = $order;
        $placed[$placeholders[0]] = $copyId;

        return array_values($placed);
    }

    /**
     * Shorten `$name` until `$format($name)` fits the column.
     *
     * @param callable(string): string $format
     */
    private static function fit(string $name, callable $format): string
    {
        $result = $format($name);
        $over = mb_strlen($result) - self::MAX_NAME;

        if ($over <= 0) {
            return $result;
        }

        return $format(rtrim(mb_substr($name, 0, max(1, mb_strlen($name) - $over))));
    }
}
