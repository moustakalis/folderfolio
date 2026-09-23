<?php

declare(strict_types=1);

namespace FolderFolio\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Who may do what.
 *
 * Every folder route used to sit behind upload_files, which Contributors and
 * Authors hold - so any of them could rename, move or delete folders belonging
 * to anyone, and reassign media they did not own.
 *
 * The four abilities now come from the roles matrix on the settings screen,
 * which the site owner can edit. Two rules hold whatever that table says:
 *
 * 1. The content's own capability first — upload_files for media, and since
 *    tier 3 item 12 each post type's `edit_posts` for its folders
 *    (`PostTypes::baseCap()`). The matrix narrows, it never widens: a role
 *    that cannot open the media library cannot be handed the folder tree by
 *    ticking a box. So a Contributor row with `assign` ticked - which is the
 *    shipped default, and what screen 08 draws - grants nothing on media until
 *    that site also gives Contributors upload_files. One matrix for every
 *    type (Nick's 12e): what a role may do to folders does not change with
 *    what is in them; whether it can reach them does.
 * 2. Administrators are pinned. A matrix that can lock every administrator out
 *    of the folder tree leaves nobody able to open the screen that would undo
 *    it.
 *
 * Nothing here writes to wp_user_roles. The matrix is read at the moment of
 * the check, so there is no role migration on activation, nothing to clean up
 * on uninstall, and a site that deactivates the plugin gets its roles back
 * exactly as they were. Every answer is filterable.
 */
final class Capabilities
{
    /**
     * Explicit capability a site can grant to widen access, e.g. to let a
     * specific Author manage the folder tree. Bypasses the matrix, because it
     * is a grant made deliberately in code about one user.
     */
    public const MANAGE = 'folderfolio_manage_folders';

    /**
     * See a type's folders, and file its items into them.
     *
     * @param string $objectType `attachment` for media, or a post type.
     */
    public static function canUseFolders(string $objectType = PostTypes::MEDIA): bool
    {
        return (bool) apply_filters(
            'folderfolio_user_can_use_folders',
            self::reaches($objectType),
            $objectType
        );
    }

    /**
     * Rule 1: the type has folders, and this person can open its screen.
     */
    private static function reaches(string $objectType): bool
    {
        // Media first and without reading the settings: it is always enabled,
        // and this is asked on every media request.
        if ($objectType === PostTypes::MEDIA) {
            return current_user_can('upload_files');
        }

        return PostTypes::isEnabled($objectType) && current_user_can(PostTypes::baseCap($objectType));
    }

    /**
     * May the current user do one of the things the matrix names?
     *
     * @param string $ability    One of Settings::ABILITIES.
     * @param string $objectType Whose folders — `attachment`, or a post type.
     */
    public static function can(string $ability, string $objectType = PostTypes::MEDIA): bool
    {
        $allowed = self::resolve($ability, $objectType);

        /**
         * Filters one ability for the current user.
         *
         * @param bool   $allowed    Whether the matrix and the two rules allow it.
         * @param string $ability    One of Settings::ABILITIES.
         * @param string $objectType `attachment`, or a post type.
         */
        return (bool) apply_filters('folderfolio_user_can', $allowed, $ability, $objectType);
    }

    private static function resolve(string $ability, string $objectType): bool
    {
        if (!in_array($ability, Settings::ABILITIES, true)) {
            return false;
        }

        // A ZIP is files; a folder of posts has none to put in one.
        if ($ability === 'download' && $objectType !== PostTypes::MEDIA) {
            return false;
        }

        // Rule 1. Also the cheap check: an unauthenticated REST request stops
        // here without reading an option or loading the user's roles.
        if (!self::reaches($objectType)) {
            return false;
        }

        // Rule 2, and the deliberate per-user grant.
        if (current_user_can('manage_options') || current_user_can(self::MANAGE)) {
            return true;
        }

        $matrix = Settings::get()['roles'];
        $user = wp_get_current_user();

        /** @var list<string> $roles */
        $roles = array_values($user->roles);

        foreach ($roles as $role) {
            $role = (string) $role;

            if (isset($matrix[$role])) {
                if (in_array($ability, $matrix[$role], true)) {
                    return true;
                }

                continue;
            }

            // A role the matrix has never heard of - Shop Manager, or anything
            // a plugin registers. Denying it would quietly break sites on
            // upgrade, so it keeps the capability the route used before this
            // table existed.
            if (self::legacyFallback($ability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the route would have asked before the matrix existed.
     *
     * Structural changes were edit_others_posts - Editors and Administrators,
     * not Authors or Contributors. Assignment was upload_files, which rule 1
     * has already established.
     */
    private static function legacyFallback(string $ability): bool
    {
        if ('assign' === $ability) {
            return true;
        }

        // A role the matrix has never heard of does not hold a lock that
        // exists to stop exactly such roles. Administrators have it anyway,
        // by rule 2.
        if ('lock' === $ability) {
            return false;
        }

        // Downloading a folder is reading files this person can already open
        // one at a time — upload_files, which rule 1 has established.
        if ('download' === $ability) {
            return true;
        }

        return current_user_can('edit_others_posts');
    }

    /**
     * Create, rename, move or delete folders.
     *
     * Kept as the coarse answer for callers that are asking "should this user
     * see the folder management UI at all" rather than authorising one act.
     * A user who can do any one of the three sees the toolbar; each button
     * still checks its own ability.
     */
    public static function canManageFolders(string $objectType = PostTypes::MEDIA): bool
    {
        $can = self::can('create', $objectType)
            || self::can('rename', $objectType)
            || self::can('delete', $objectType);

        return (bool) apply_filters('folderfolio_user_can_manage_folders', $can);
    }

    /**
     * May the current user organize this particular attachment — or, since
     * item 12, this particular post? `edit_post` is the same question for both.
     *
     * Assignment changes a post's relationships, so it needs the same
     * permission as editing it. Without this an Author could file another
     * user's private media into their own folder. This is on top of the
     * matrix's `assign`, not instead of it: the matrix says whether this role
     * files media at all, this says whether this file is theirs to file.
     */
    public static function canEditAttachment(int $attachmentId): bool
    {
        return (bool) apply_filters(
            'folderfolio_user_can_edit_attachment',
            current_user_can('edit_post', $attachmentId),
            $attachmentId
        );
    }
}
