<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The rail's open state and width, per user.
 *
 * Per user and server-side, not localStorage, for one reason: the server has
 * to know the answer before it prints the page. A rail whose width is read
 * from the browser after load is a rail that jumps — the library renders at
 * full width, then 300px of it is taken away and every thumbnail reflows. With
 * the width in user meta the markup goes out at the right size and nothing
 * moves.
 *
 * It also means the preference follows the user between browsers, which is
 * what anyone would expect of a setting that is theirs rather than their
 * machine's.
 *
 * The sanitising half is deliberately pure and static: it is the part that has
 * to be right when the value arrives over HTTP, and it is unit-tested without
 * WordPress.
 */
final class RailPreferences
{
    public const META_KEY = 'folderfolio_rail';

    /**
     * The designed width. Everything in the handoff's geometry table is
     * measured at this number.
     */
    public const DEFAULT_WIDTH = 300;

    /**
     * Below this the four labelled toolbar buttons — Rename, Delete, Sort,
     * More — stop fitting side by side, which is the point at which the rail
     * stops being the designed component and starts being a narrower thing
     * nobody drew.
     */
    public const MIN_WIDTH = 240;

    /**
     * Above this the rail is taking more from the grid than a folder tree can
     * justify. A user who wants more can still drag to it; they cannot drag
     * the library off the screen.
     */
    public const MAX_WIDTH = 560;

    /**
     * Width of the collapsed tab. Not a stored width — the stored width is
     * remembered across a collapse so that reopening restores it.
     */
    public const TAB_WIDTH = 28;

    /**
     * @param array<string, mixed> $raw
     *
     * @return array{open: bool, width: int}
     */
    public static function sanitize(array $raw): array
    {
        return [
            'open' => self::sanitizeOpen($raw['open'] ?? true),
            'width' => self::clampWidth($raw['width'] ?? self::DEFAULT_WIDTH),
        ];
    }

    /**
     * @param mixed $value
     */
    public static function clampWidth($value): int
    {
        // Arrives from HTTP, from user meta written by an older version, or
        // from a filter. None of those are guaranteed to be an integer, and
        // (int) 'wide' is 0, which is why the default is applied to anything
        // non-numeric rather than to the cast result.
        if (!is_numeric($value)) {
            return self::DEFAULT_WIDTH;
        }

        $width = (int) $value;

        return max(self::MIN_WIDTH, min(self::MAX_WIDTH, $width));
    }

    /**
     * @param mixed $value
     */
    public static function sanitizeOpen($value): bool
    {
        // '0' and 'false' both arrive as truthy strings from a form-encoded
        // request body, so this cannot be a plain (bool) cast.
        if (is_string($value)) {
            return !in_array(strtolower($value), ['', '0', 'false', 'no', 'off'], true);
        }

        return (bool) $value;
    }

    /**
     * @return array{open: bool, width: int}
     */
    public static function forUser(int $userId): array
    {
        $stored = get_user_meta($userId, self::META_KEY, true);

        if (!is_array($stored)) {
            $stored = [];
        }

        /** @var array<string, mixed> $stored */
        return self::sanitize($stored);
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array{open: bool, width: int}
     */
    public static function save(int $userId, array $raw): array
    {
        $clean = self::sanitize($raw);

        update_user_meta($userId, self::META_KEY, $clean);

        return $clean;
    }
}
