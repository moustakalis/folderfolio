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
 *     startup_folder: int|null,
 *     undo_window: int,
 *     roles: Matrix,
 *     post_types: list<string>
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
     *
     * `custom` is the odd one and belongs here all the same. The other four
     * are views over the same data; custom is the arrangement the folders
     * carry themselves, which is exactly what this setting's own reasoning
     * calls "a property of how the site is organised". A site whose tree is
     * arranged by hand is the site that wants to open on it — and without
     * this, a folder dragged into place is only visible until the next page
     * load.
     */
    public const SORTS = ['name-asc', 'name-desc', 'newest', 'oldest', 'custom'];

    /**
     * The columns of the roles matrix, in the order screen 08 draws them.
     *
     * `lock` is the fifth, since tier 2 item 10: who may lock and unlock a
     * folder and is not stopped by a lock (`Domain\FolderLocks`). It is not in
     * any stored role option, so on a site that saved the matrix before, no
     * role has it and only administrators — who pass every ability,
     * `Capabilities` rule 2 — can lock. That is also the default below.
     *
     * `rename` covers moving a folder as well: both edit a folder that already
     * exists, and a fifth column for "move" would be a distinction the person
     * filling in this table does not think in. By 22 Sep it had also grown
     * reordering, both per-folder sorts and cut/paste, which is why the column
     * is *headed* **Organise** on the settings screen — see
     * `SettingsPage::abilityLabels()`. The key stays `rename`: it is what
     * every stored role option holds, and renaming it would be a migration
     * bought for a word.
     */
    public const ABILITIES = ['create', 'rename', 'delete', 'assign', 'lock', 'download'];

    /**
     * The abilities a matrix saved before 23 Sep 2026 knew about.
     *
     * A saved matrix lists what each role was given, not what it was
     * refused, so an ability added later is absent from every stored row —
     * which would read as "nobody but administrators" the moment it shipped.
     * `get()` fills a newer ability in from its default for a matrix saved
     * before it existed; `save()` records which abilities the form showed.
     */
    private const ABILITIES_BEFORE_DOWNLOAD = ['create', 'rename', 'delete', 'assign', 'lock'];

    /**
     * The folder the media library opens in — tier 1 item 7.
     *
     * Three values, and they are the same three the query var and the rail
     * use: `null` is no startup folder, `0` is Unassigned, and a positive id
     * is a folder. `0` has to be selectable — "show me what is not filed yet"
     * is the arrival a person who files media actually wants — which is why
     * this cannot be an int with 0 meaning off.
     *
     * **It never filters a query.** `Admin\StartupFolder` turns it into a
     * redirect, so the request that renders the library carries the folder in
     * its URL exactly as a click would. The readme's one claim — that this
     * plugin never filters your media library unless you pick a folder — is
     * defended by a negative control asserting the whole `posts_clauses` array
     * is untouched when no folder is asked for, and defaulting an absent
     * parameter here would have made that claim false while leaving the test
     * green, because the test asks the filter and the filter would not have
     * been the thing that changed.
     */
    public const STARTUP_NONE = null;

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
     * The post types with folders out of the box — tier 3 item 12.
     *
     * Posts and Pages (Nick's 12a, board KZsHhrffzKQYqUjTvdFszK): the two
     * every site has and the two a person looks for folders on first. Any
     * other type — a shop's products, a page builder's templates — is one
     * tick away under *Folders for*, and off until then: a folder tree turning
     * up uninvited on a plugin's own screens is the surprise this avoids.
     * Media is not in the list because it is not optional; it is the product.
     */
    public const DEFAULT_POST_TYPES = ['post', 'page'];

    /**
     * @return SettingsArray
     */
    public static function defaults(): array
    {
        return [
            'count_mode' => 'inherited',
            'default_sort' => 'name-asc',
            'startup_folder' => self::STARTUP_NONE,
            'undo_window' => self::DEFAULT_UNDO,
            'roles' => self::defaultRoles(),
            'post_types' => self::DEFAULT_POST_TYPES,
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
            // Everything but `lock` (Nick's answer 5, board
            // 3ZU8VGkJemznTvKp8tNnvY). Authors and Contributors cannot
            // rename or delete out of the box, so a lock only means something
            // if it stops the people who otherwise could — Editors.
            'editor' => ['create', 'rename', 'delete', 'assign', 'download'],
            // Download goes to everyone who sees the rail (Nick's answer 2,
            // board PpiAmXsixk3sG9yygJQnw5): every file is already a download
            // one at a time; the ZIP adds convenience, not access.
            'author' => ['create', 'assign', 'download'],
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
            'startup_folder' => self::startupFolder($raw['startup_folder'] ?? null),
            'undo_window' => self::clampUndo($raw['undo_window'] ?? null),
            'roles' => self::sanitizeRoles($raw['roles'] ?? null),
            'post_types' => self::sanitizePostTypes($raw['post_types'] ?? null),
        ];
    }

    /**
     * Which post types have folders, as slugs.
     *
     * Not checked against the registered types here — this runs in the unit
     * suite, and a type whose plugin is switched off for a week should come
     * back with its folders when the plugin does. `Support\PostTypes` drops
     * what is not registered at the moment it is asked. An empty list is a
     * real answer (media only), which is why the form sends a blank entry
     * alongside the ticks: a form with every box cleared still names the key.
     *
     * @param mixed $value
     *
     * @return list<string>
     */
    public static function sanitizePostTypes($value): array
    {
        if (!is_array($value)) {
            return self::DEFAULT_POST_TYPES;
        }

        $clean = [];

        foreach ($value as $key => $type) {
            // The form's ['product' => '1'] or a list (['post', 'page']).
            if (is_string($key)) {
                if (!self::truthy($type)) {
                    continue;
                }

                $type = $key;
            }

            if (!is_string($type)) {
                continue;
            }

            $type = strtolower(trim($type));

            // A post type slug is at most 20 characters of [a-z0-9_-], and 20
            // is also the width of the folders table's object_type column.
            if ('' === $type || 'attachment' === $type || 1 !== preg_match('/^[a-z0-9_-]{1,20}$/', $type)) {
                continue;
            }

            $clean[] = $type;
        }

        return array_values(array_unique($clean));
    }

    /**
     * The startup folder, in the three-way every folder value here uses.
     *
     * Deliberately the same shape as `Admin\MediaLibraryFilter::normalizeFolderId()`
     * and deliberately not a call to it: that one is in the admin layer and
     * runs `wp_unslash()` and `sanitize_text_field()` on a raw request value,
     * and this class is sanitised in a unit suite with no WordPress loaded.
     * The contract they share is the part worth stating — **`'0'` is
     * Unassigned and `''` is absent** — and it is asserted in `SettingsTest`,
     * because the failure mode is one of the two quietly starting to treat
     * `0` as nothing and the Unassigned row becoming unpickable on the one
     * screen that offers it.
     *
     * @param mixed $value
     */
    private static function startupFolder($value): ?int
    {
        if (null === $value || '' === $value || is_array($value) || !is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
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
        if (isset($stored['roles']) && is_array($stored['roles'])) {
            $seen = isset($stored['abilities']) && is_array($stored['abilities'])
                ? $stored['abilities']
                : self::ABILITIES_BEFORE_DOWNLOAD;
            $stored['roles'] = self::withNewAbilities($stored['roles'], $seen);
        }

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

        update_option(self::OPTION, $clean + ['abilities' => self::ABILITIES]);

        return $clean;
    }

    /**
     * Change some settings and keep the rest — WP-CLI's `settings set` and
     * `POST /settings` (24 Sep, alignment audit item C).
     *
     * The form posts every field, so `save()` can sanitise the whole post.
     * These change one key, or a few, so the stored settings are the starting
     * point — as stored, without the `folderfolio_settings` filter, which is
     * a site's code speaking and would be written into the option if it were
     * read back through `get()`.
     *
     * The same `sanitize()` decides every value. What it would quietly turn
     * into something else — a count mode it does not know becomes the
     * default, an undo window of 90 becomes 60 — is refused here instead,
     * with the values it takes: a form cannot send those, and a script that
     * did would otherwise be told it succeeded.
     *
     * `roles` is merged a role at a time: `['editor' => [...]]` changes the
     * Editor row and leaves every other.
     *
     * @param array<string, mixed> $changes
     *
     * @return SettingsArray|\WP_Error
     */
    public static function change(array $changes): array|\WP_Error
    {
        $known = array_keys(self::defaults());
        $unknown = array_diff(array_keys($changes), $known);

        if ([] !== $unknown) {
            return new \WP_Error(
                'folderfolio_setting_unknown',
                sprintf(
                    /* translators: 1: the names given, 2: the names there are. */
                    __('There is no setting called %1$s. The settings are %2$s.', 'folderfolio'),
                    implode(', ', $unknown),
                    implode(', ', $known)
                ),
                ['status' => 400]
            );
        }

        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        if (isset($stored['roles']) && is_array($stored['roles'])) {
            $seen = isset($stored['abilities']) && is_array($stored['abilities'])
                ? $stored['abilities']
                : self::ABILITIES_BEFORE_DOWNLOAD;
            $stored['roles'] = self::withNewAbilities($stored['roles'], $seen);
        }

        $current = self::sanitize(array_merge(self::defaults(), $stored));
        $next = $current;

        foreach ($changes as $key => $value) {
            $refused = match ($key) {
                'count_mode' => self::refuseOneOf($key, $value, self::COUNT_MODES),
                'default_sort' => self::refuseOneOf($key, $value, self::SORTS),
                'undo_window' => is_numeric($value) && (int) $value >= self::MIN_UNDO && (int) $value <= self::MAX_UNDO
                    ? null
                    : sprintf(
                        /* translators: 1: the fewest seconds, 2: the most. */
                        __('undo_window is a number of seconds from %1$d to %2$d.', 'folderfolio'),
                        self::MIN_UNDO,
                        self::MAX_UNDO
                    ),
                'startup_folder' => self::refuseStartupFolder($value),
                'post_types' => self::refusePostTypes($value, $current['post_types']),
                'roles' => self::refuseRoles($value),
                default => null,
            };

            if (null !== $refused) {
                return new \WP_Error('folderfolio_setting_invalid', $refused, ['status' => 400]);
            }

            $next[$key] = 'roles' === $key && is_array($value)
                ? array_merge($current['roles'], $value)
                : $value;
        }

        return self::save($next);
    }

    /**
     * @param mixed        $value
     * @param list<string> $allowed
     */
    private static function refuseOneOf(string $key, $value, array $allowed): ?string
    {
        return is_string($value) && in_array(strtolower(trim($value)), $allowed, true)
            ? null
            : sprintf(
                /* translators: 1: a setting's name, 2: the values it takes. */
                __('%1$s is one of %2$s.', 'folderfolio'),
                $key,
                implode(', ', $allowed)
            );
    }

    /**
     * Null (no startup folder), 0 (Unassigned) or a media folder that exists.
     *
     * @param mixed $value
     */
    private static function refuseStartupFolder($value): ?string
    {
        if (null === $value || '' === $value || 0 === $value || '0' === $value) {
            return null;
        }

        $id = is_numeric($value) ? (int) $value : 0;
        $folder = $id > 0 ? (new \FolderFolio\Domain\FolderService())->get($id) : null;

        return null !== $folder && PostTypes::MEDIA === $folder->objectType
            ? null
            : __('startup_folder is a media folder’s id, 0 for Unassigned, or empty for none.', 'folderfolio');
    }

    /**
     * A list of post types the site has — or had, for one already ticked
     * whose plugin is off for now (`sanitizePostTypes()` keeps those).
     *
     * @param mixed        $value
     * @param list<string> $ticked
     */
    private static function refusePostTypes($value, array $ticked): ?string
    {
        if (!is_array($value) || array_values($value) !== $value) {
            return __('post_types is a list of post type names, such as post and page. An empty list means media only.', 'folderfolio');
        }

        foreach ($value as $type) {
            if (!is_string($type) || (!in_array($type, $ticked, true) && (!post_type_exists($type) || 'attachment' === $type))) {
                return sprintf(
                    /* translators: %s: what was given as a post type. */
                    __('There is no post type called %s that can have folders.', 'folderfolio'),
                    is_string($type) ? $type : (string) wp_json_encode($type)
                );
            }
        }

        return null;
    }

    /**
     * Each role one the site has, each ability one of ours.
     *
     * @param mixed $value
     */
    private static function refuseRoles($value): ?string
    {
        if (!is_array($value) || [] === $value) {
            return __('roles maps a role to the abilities it has, such as {"editor": ["create", "assign"]}.', 'folderfolio');
        }

        $roles = array_keys(wp_roles()->get_names());

        foreach ($value as $role => $abilities) {
            if (!is_string($role) || !in_array($role, $roles, true)) {
                return sprintf(
                    /* translators: 1: a role name, 2: the roles the site has. */
                    __('There is no role called %1$s on this site. Its roles are %2$s.', 'folderfolio'),
                    is_string($role) ? $role : (string) wp_json_encode($role),
                    implode(', ', $roles)
                );
            }

            $list = is_array($abilities) ? $abilities : [];
            $list = array_values($list) === $list ? $list : array_keys(array_filter($list, [self::class, 'truthy']));
            $unknown = array_diff($list, self::ABILITIES);

            if (!is_array($abilities) || [] !== $unknown) {
                return sprintf(
                    /* translators: 1: a role name, 2: the abilities there are. */
                    __('%1$s is given a list of abilities from %2$s. An empty list takes them all away.', 'folderfolio'),
                    $role,
                    implode(', ', self::ABILITIES)
                );
            }
        }

        return null;
    }

    /**
     * Give each role an ability the saved matrix never showed, as its default.
     *
     * A core role takes the default row's answer. A role the defaults do not
     * know — Shop Manager, anything a plugin registered — takes `download`
     * when it was given `assign`, which is the ability that already means
     * "works with files in folders".
     *
     * @param array<mixed>  $roles As stored.
     * @param array<mixed>  $seen  The abilities the form showed when it was saved.
     *
     * @return array<mixed>
     */
    public static function withNewAbilities(array $roles, array $seen): array
    {
        $new = array_diff(self::ABILITIES, array_filter($seen, 'is_string'));

        if ([] === $new) {
            return $roles;
        }

        $defaults = self::defaultRoles();

        foreach ($roles as $role => $abilities) {
            if (!is_string($role) || !is_array($abilities)) {
                continue;
            }

            // The form's shape (['create' => '1']) or a list (['create']).
            $list = array_values($abilities) === $abilities ? $abilities : array_keys(array_filter($abilities));

            foreach ($new as $ability) {
                $grant = isset($defaults[$role])
                    ? in_array($ability, $defaults[$role], true)
                    : ('download' === $ability && in_array('assign', $list, true));

                if ($grant && !in_array($ability, $list, true)) {
                    $list[] = $ability;
                }
            }

            $roles[$role] = $list;
        }

        return $roles;
    }
}
