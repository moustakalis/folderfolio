<?php

declare(strict_types=1);

namespace FolderFolio\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The folder a request says its uploads belong in.
 *
 * Screen 10 draws the sentence this implements — "Uploads go to the selected
 * folder." — in the media modal's footer. What makes it true is one parameter
 * on the upload request, read back here.
 *
 * ## Why a parameter and not a watcher
 *
 * 0.2.0 tried to watch the media grid for new attachments and file them
 * afterwards, and it never once worked: the container it watched is rendered
 * by Backbone after `wp.media` boots, and the watcher was installed at
 * `DOMContentLoaded`. Nothing failed, nothing logged, and no upload was ever
 * filed. The module is deleted.
 *
 * A parameter cannot fail that way, and it is also what the market does —
 * FileBird (`fbv`), CatFolders (`catf`), Real Media Library (`rmlFolder`) and
 * Premio's Folders (`folder_for_media`) all send one and all read it on
 * `add_attachment`. See `research-05-upload-to-folder.md` in the Claude
 * project. It covers the grid, the media modal, drag-and-drop and
 * `media-new.php` in one path, because all four end in the same hook.
 *
 * ## An id, never a path
 *
 * FileBird and CatFolders both accept `12/Brand/Logos` and *create* the
 * segments that are missing. That makes an upload parameter a way to write
 * folders, so a typo in a URL leaves new folders behind — and the same
 * reasoning that made the gallery shortcode resolve with `findByPath()`
 * rather than `getOrCreateByPath()` applies here with more force, because
 * nobody typed this parameter on purpose. An id, or nothing.
 *
 * ## Where the permission check is
 *
 * Not here. This answers *which folder*, and `UploadRouter` hands the answer
 * to `FolderService::assignAttachments()`, which checks the `assign` ability
 * and `edit_post` on the attachment. Doing it twice, in two places, is how
 * the two get to disagree.
 */
final class UploadTarget
{
    /**
     * The parameter's name — the same one the library filters by.
     *
     * One name for "which folder this request is about". A second name would
     * mean a link that opens a folder and an upload into that folder
     * disagreeing about what to call it, and a reader of a request log having
     * to know both.
     */
    public const PARAM = 'folderfolio_folder';

    public function register(): void
    {
        /*
         * Priority 5, below the default.
         *
         * A site's own callback on this filter is a standing rule — "product
         * images go to /Products" — and a rule that a person's current folder
         * could silently override would be no rule at all. Running first means
         * anything at the default priority sees this answer and may replace
         * it.
         */
        add_filter('folderfolio_default_folder_for_upload', [$this, 'fromRequest'], 5, 2);
    }

    /**
     * `mixed`, not `?int`, deliberately.
     *
     * A filter's arguments are whatever the callback before it returned, and
     * a third party is free to return junk. UploadRouter already guards
     * against that on the way out; a typed signature here would turn the same
     * junk into a fatal on the way in, before that guard ever runs.
     *
     * @param mixed $folderId What the filter has been told so far.
     */
    public function fromRequest(mixed $folderId, int $attachmentId): mixed
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress has already authorised the upload that created this attachment; this only reads which folder it named, and the assignment is capability-checked in FolderService.
        $named = self::read($_REQUEST);

        return $named ?? $folderId;
    }

    /**
     * The folder id a request names, or null.
     *
     * Pure, and separate from the hook, so the unit suite can cover the
     * parsing without WordPress. Null for every shape that is not a positive
     * integer — including `0`, which is Unassigned, and an absent parameter,
     * which is All media. Both of those mean "do not file", and they mean it
     * for the same reason: neither is a folder.
     *
     * @param array<string, mixed> $request
     */
    public static function read(array $request): ?int
    {
        if (!isset($request[self::PARAM])) {
            return null;
        }

        $raw = $request[self::PARAM];

        // is_bool as well as is_scalar: `true` stringifies to '1', and a
        // boolean in a request parameter is never somebody naming folder 1.
        if (!is_scalar($raw) || is_bool($raw)) {
            return null;
        }

        $raw = trim((string) $raw);

        // ctype_digit rather than is_numeric: '3.7', '1e3', ' 3' and '-1' are
        // all numeric and none of them is a folder id. A path like
        // '12/Brand/Logos' fails here too, which is the point — see the class
        // docblock.
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }
}
