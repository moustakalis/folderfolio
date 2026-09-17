<?php

declare(strict_types=1);

namespace FolderFolio\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The site's settings — screen 08's first tab.
 *
 * One option row, not five. Every value here is read on the media library
 * screen, which means it is read on every page load of the screen the plugin
 * exists for; five `get_option` calls would be five rows to autoload and five
 * places for a partial write to leave the site in a state no single save ever
 * produced.
 *
 * The sanitising half is pure and static, like RailPreferences: it is the part
 * that has to be right when the values arrive from a form post, and it is unit
 * tested without WordPress. `get()` and `save()` are the only methods that
 * touch the options table.
 *
 * ## On the roles matrix
 *
 * FileBird and Real Media Library both put per-role folder permissions behind
 * their paid tier. It is four booleans against five roles; there is nothing in
 * it worth charging for, and a media library where an Author can quietly
 * delete the folder an Editor built is a real problem on a real site.
 *
 * The matrix narrows, it never widens: `Capabilities` requires `upload_files`
 * before it consults the matrix at all, so no row here can hand the folder
 * tree to somebody who cannot open the media library. Nothing is written to
 * `wp_user_roles` — the matrix is read at the moment of the check, so there is
 * no role migration on activation and nothing left behind on uninstall but
 * this one option.
 *
 * @phpstan-type Matrix array<string, list<string>>
 * @phpstan-type SettingsArray array{
 *     count_mode: string,
 *     default_sort: string,
 *     undo_window: int,
 *     roles: Matrix
 * }
 */
final class Settings
{
    public const OPTION = 'folderfolio_settings';

    /**
     * Whether a folder's badge counts its subtree or only its own files.
     *
     * Inherited is the default because a parent holding a hundred files in its
     * children reading `0` is the bug every competitor ships — see §4 of the
     * research. Direct is offered because a site that files everything at one
     * level finds the roll-up noise.
     */
    public const COUNT_MODES = ['inherited', 'direct'];

    /**
     * Matches SortOrder in assets/src/apps/rail/store.ts. A value that is not
     * in this list would leave the rail's sort menu with nothing selected.
     */
    public const SORTS = ['name-asc', 'name-desc', 'newest', 'oldest'];

    /**
     * The four columns of the roles matrix, in the order screen 08 draws them.
     *
     * `rename` covers moving a folder as well: both edit a folder that already
     * exists, and a fifth column for "move" would be a distinction the person
     * filling in this table does not think in.
     */
    public const ABILITIES = ['create', 'rename', 'delete', 'assign'];

    public const DEFAULT_UNDO = 5;

    /**
     * Floor and ceiling on the undo window, in seconds.
     *
     * The floor is not 0. A zero-second window is a delete with no recourse
     * and no dialog either, which is the one combination the design rules out;
     * and below about three seconds the toast has barely finished appearing
     * before the button it carries is gone.
     */
    public const MIN_UNDO = 3;

    public const MAX_UNDO = 60;

    /**
     * @return SettingsArray
     */
    public static function defaults(): array
    {
        return [
            'count_mode' => 'inherited',
            'default_sort' => 'name-asc',
            'undo_window' => self::DEFAULT_UNDO,
            'roles' => self::defaultRoles(),
        ];
    }

    /**
     * The five core roles, as screen 08 draws them.
     *
     * Administrator is here for the display; `Capabilities` does not consult
     * it, because a matrix that can lock every administrator out of the folder
     * tree is a support ticket nobody can answer from inside the admin.
     *
     * A role that is not in this map — Shop Manager, or anything a plugin
     * registers — is not silently denied: `Capabilities` falls back to the
     * WordPress capability it would have used before this table existed.
     *
     * @return Matrix
     */
    public static function defaultRoles(): array
    {
        return [
            'administrator' => self::ABILITIES,
            'editor' => self::ABILITIES,
            'author' => ['create', 'assign'],
            'contributor' => ['assign'],
            'subscriber' => [],
        ];
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return SettingsArray
     */
    public static function sanitize(array $raw): array
    {
        $defaults = self::defaults();

        return [
            'count_mode' => self::oneOf($raw['count_mode'] ?? null, self::COUNT_MODES, $defaults['count_mode']),
            'default_sort' => self::oneOf($raw['default_sort'] ?? null, self::SORTS, $defaults['default_sort']),
            'undo_window' => self::clampUndo($raw['undo_window'] ?? null),
            'roles' => self::sanitizeRoles($raw['roles'] ?? null),
        ];
    }

    /**
     * @param mixed        $value
     * @param list<string> $allowed
     */
    private static function oneOf($value, array $allowed, string $fallback): string
    {
        if (!is_string($value)) {
            return $fallback;
        }

        // Arrives from a <select>, so it is already one of ours in the normal
        // case; the strtolower is for the filter and WP-CLI paths.
        $value = strtolower(trim($value));

        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    /**
     * @param mixed $value
     */
    public static function clampUndo($value): int
    {
        // (int) 'soon' is 0, which would silently become the floor rather than
        // the default, so non-numeric input is rejected before the cast.
        if (!is_numeric($value)) {
            return self::DEFAULT_UNDO;
        }

        return max(self::MIN_UNDO, min(self::MAX_UNDO, (int) $value));
    }

    /**
     * @param mixed $value
     *
     * @return Matrix
     */
    public static function sanitizeRoles($value): array
    {
        if (!is_array($value)) {
            return self::defaultRoles();
        }

        $clean = [];

        foreach ($value as $role => $abilities) {
            if (!is_string($role) || '' === $role) {
                continue;
            }

            // Role slugs are [a-z0-9_-] by convention and by what
            // add_role() produces; anything else did not come from our form.
            $role = strtolower($role);

            if (1 !== preg_match('/^[a-z0-9_-]+$/', $role)) {
                continue;
            }

            $clean[$role] = self::sanitizeAbilities($abilities);
        }

        // An empty matrix is a form that posted no checkboxes at all, which is
        // a broken request rather than "nobody may do anything".
        return [] === $clean ? self::defaultRoles() : $clean;
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private static function sanitizeAbilities($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $granted = [];

        foreach (self::ABILITIES as $ability) {
            // Two shapes reach here: the form's ['create' => '1'] and a
            // filter's or WP-CLI's ['create', 'assign']. Both are accepted so
            // that a site can set this in code without knowing which the form
            // happens to send.
            $ticked = array_key_exists($ability, $value)
                ? self::truthy($value[$ability])
                : in_array($ability, $value, true);

            if ($ticked) {
                $granted[] = $ability;
            }
        }

        return $granted;
    }

    /**
     * @param mixed $value
     */
    private static function truthy($value): bool
    {
        if (is_string($value)) {
            return !in_array(strtolower($value), ['', '0', 'false', 'no', 'off'], true);
        }

        return (bool) $value;
    }

    /**
     * The stored settings, sanitised.
     *
     * Read on every media library request, so it is deliberately one autoloaded
     * option and no query beyond it.
     *
     * @return SettingsArray
     */
    public static function get(): array
    {
        $stored = get_option(self::OPTION, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        // Merged over the defaults rather than sanitised alone, so that a key
        // added in a later version is present for a site that saved before it
        // existed.
        /** @var array<string, mixed> $merged */
        $merged = array_merge(self::defaults(), $stored);

        /** @var SettingsArray $clean */
        $clean = apply_filters('folderfolio_settings', self::sanitize($merged));

        return $clean;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return SettingsArray
     */
    public static function save(array $raw): array
    {
        $clean = self::sanitize($raw);

        update_option(self::OPTION, $clean);

        return $clean;
    }
}
