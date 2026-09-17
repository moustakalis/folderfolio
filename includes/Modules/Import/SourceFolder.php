<?php

declare(strict_types=1);

namespace FolderFolio\Modules\Import;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One folder as the source plugin stored it, in FolderFolio's terms.
 *
 * The interesting field is `parentId`, because **three different root markers
 * are in use** across the sources examined: FileBird and CatFolders write `0`,
 * Real Media Library writes `-1`, WordPress taxonomy terms write `0`, and
 * FolderFolio writes `NULL`. Each reader normalises its own convention to
 * `null` here, once, at the edge — so nothing downstream has to know which
 * plugin it came from, and a missed conversion produces a folder whose parent
 * does not exist rather than a silently reparented tree.
 */
final class SourceFolder
{
    /**
     * @param int      $id        The source's own id. Kept, because it becomes
     *                            the folder's provenance and is what makes a
     *                            second import reconcile instead of duplicate.
     * @param int|null $parentId  Normalised: null means top level.
     * @param string   $name      Raw, as stored. Sanitised when written.
     * @param int      $sortOrder The source's own ordering, where it had one.
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $parentId,
        public readonly string $name,
        public readonly int $sortOrder = 0
    ) {
    }
}
