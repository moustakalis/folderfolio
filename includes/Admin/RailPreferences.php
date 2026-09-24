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
     * This person's own startup folder — tier 1 item 7b.
     *
     * The same three-way every folder value in this plugin uses: `null` is no
     * personal choice, `0` is Unassigned, a positive id is a folder. It lives
     * here rather than in `Settings` for the reason the class comment already
     * gives about width — it is theirs, not their machine's, and not the
     * site's.
     *
     * ## `null` means "no preference", not "none"
     *
     * A user with no value here follows the site's `startup_folder`. There is
     * deliberately no fourth value for *"the site has one and I want none"*:
     * expressing it would need a control that says so, and the control this
     * has is a toggle on a folder, which can only say **this one** or **not
     * this one**. Somebody who does not want the site's folder today clears
     * the filter, which holds for the session.
     *
     * ## And it is read as theirs, never as the site's
     *
     * The toggle shows pressed only when *this* value names the folder — not
     * when the site's does. Otherwise pressing it would have to mean "stop
     * following the site", the site's folder would keep arriving, and the
     * button would look broken while working correctly.
     */
    public const STARTUP_NONE = null;

    /**
     * @param array<string, mixed> $raw
     *
     * @return array{open: bool, width: int, startup: ?int, stars: list<int>}
     */
    public static function sanitize(array $raw): array
    {
        return [
            'open' => self::sanitizeOpen($raw['open'] ?? true),
            'width' => self::clampWidth($raw['width'] ?? self::DEFAULT_WIDTH),
            // `??` on purpose: an absent key and a stored null both mean no
            // personal startup folder, and nothing needs to tell them apart.
            'startup' => self::startupFolder($raw['startup'] ?? null),
            'stars' => self::stars($raw['stars'] ?? []),
        ];
    }

    /**
     * The startup folder, in the three-way described on STARTUP_NONE.
     *
     * The same shape as `Support\Settings::startupFolder()` and
     * `Admin\MediaLibraryFilter::normalizeFolderId()`, and the same reason
     * for not calling either: this half of the class is pure and static so it
     * can be unit-tested with no WordPress loaded, and that one runs
     * `wp_unslash()`. What the three share is asserted rather than shared —
     * **`0` is Unassigned and an empty value is absent** — because the failure
     * mode is one of them quietly starting to read `0` as nothing.
     *
     * @param mixed $value
     */
    public static function startupFolder($value): ?int
    {
        if (null === $value || '' === $value || is_array($value) || !is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }

    /**
     * The folders this person has starred — tier 2 item 10.
     *
     * A star is a person's own (Nick's answer), so it lives with their rail
     * rather than on the folder. Positive ids, each once, in the order given,
     * capped: the Starred group scrolls after five, and a list with no end
     * would be a preference that grows with every click. A star on a folder
     * since deleted is dropped by the client, which has the tree.
     *
     * @param mixed $value
     * @return list<int>
     */
    public static function stars($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $id) {
            if (is_numeric($id) && (int) $id > 0 && !in_array((int) $id, $out, true)) {
                $out[] = (int) $id;
            }
        }

        return array_slice($out, 0, self::MAX_STARS);
    }

    public const MAX_STARS = 100;

    /**
     * Star or unstar one folder for one person, and return their stars.
     *
     * One id rather than the list, and read-modify-write here rather than in
     * the browser: the list lives in whichever bundle drew the menu, and the
     * media picker's copy of it was never seeded — a Star pressed there would
     * have replaced every star the person had with the one they pressed. A
     * second tab has the same stale copy. The server's is the one to change.
     *
     * A new star goes last, so the Starred group grows at its foot. At the cap
     * the oldest is dropped rather than the new one refused: the press is the
     * thing the person meant.
     *
     * @return list<int>
     */
    public static function star(int $userId, int $folderId, bool $on): array
    {
        $prefs = self::forUser($userId);
        $stars = array_values(array_filter($prefs['stars'], static fn (int $id): bool => $id !== $folderId));

        if ($on) {
            $stars[] = $folderId;
            $stars = array_slice($stars, -self::MAX_STARS);
        }

        $prefs['stars'] = $stars;

        return self::save($userId, $prefs)['stars'];
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
     * @return array{open: bool, width: int, startup: ?int, stars: list<int>}
     */
    public static function forUser(int $userId): array
    {
        // A user option, not plain user meta: on a network one person has
        // one row of user meta for every site, and a star or a starting
        // folder is a folder *id* — site 2's folder 12 is a different folder
        // from site 1's, or none. `get_user_option()` reads this site's
        // prefixed key and falls back to the unprefixed one, which is where
        // every value written before 25 Sep lives (review item #27).
        $stored = get_user_option(self::META_KEY, $userId);

        if (!is_array($stored)) {
            $stored = [];
        }

        /** @var array<string, mixed> $stored */
        return self::sanitize($stored);
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array{open: bool, width: int, startup: ?int, stars: list<int>}
     */
    public static function save(int $userId, array $raw): array
    {
        $clean = self::sanitize($raw);

        update_user_option($userId, self::META_KEY, $clean);

        // The unprefixed row is the fallback forUser() reads. On a single
        // site it is this site's own older copy, spent once the prefixed one
        // exists; on a network it may be the only value another site has, so
        // it stays there.
        if (!is_multisite()) {
            delete_user_meta($userId, self::META_KEY);
        }

        return $clean;
    }
}
