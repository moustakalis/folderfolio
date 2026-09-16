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
 * Reading folders and filing your own media is still upload_files. Changing the
 * folder structure is a separate, higher bar. Both are filterable, and neither
 * writes to the roles table, so there is nothing to migrate or clean up.
 */
final class Capabilities
{
    /**
     * Explicit capability a site can grant to widen access, e.g. to let a
     * specific Author manage the folder tree.
     */
    public const MANAGE = 'folderfolio_manage_folders';

    /**
     * See folders, and file media into them.
     */
    public static function canUseFolders(): bool
    {
        return (bool) apply_filters(
            'folderfolio_user_can_use_folders',
            current_user_can('upload_files')
        );
    }

    /**
     * Create, rename, move or delete folders.
     *
     * Falls back to edit_others_posts - held by Editors and Administrators,
     * not by Authors or Contributors - so the default is sane without a role
     * migration on activation.
     */
    public static function canManageFolders(): bool
    {
        $can = current_user_can(self::MANAGE) || current_user_can('edit_others_posts');

        return (bool) apply_filters('folderfolio_user_can_manage_folders', $can);
    }

    /**
     * May the current user organize this particular attachment?
     *
     * Assignment changes a post's relationships, so it needs the same
     * permission as editing it. Without this an Author could file another
     * user's private media into their own folder.
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
