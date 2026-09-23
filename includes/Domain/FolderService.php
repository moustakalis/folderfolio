<?php

declare(strict_types=1);

namespace FolderFolio\Domain;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Database\Transaction;
use FolderFolio\Support\Capabilities;
use FolderFolio\Support\Settings;
use FolderFolio\Support\Swatches;
use WP_Error;

/**
 * Every folder invariant lives here.
 *
 * ## Hooks
 *
 * The actions and filters below are part of the public API and are covered by
 * the compatibility promise in docs/architecture-plan.md §8.4. They fire from
 * this class rather than from the REST controllers on purpose: a folder
 * created by WP-CLI, by an importer, or by another plugin calling the facade
 * must fire the same hooks as one created by a click. Firing them at the edge
 * would mean three of those four paths silently skipped them.
 *
 * Actions:
 *   folderfolio_folder_created           (Folder $folder)
 *   folderfolio_folder_renamed           (Folder $folder, string $previousName)
 *   folderfolio_folder_moved             (Folder $folder, ?int $previousParentId)
 *   folderfolio_folder_deleted           (int $id, string $children, list<int> $deletedIds)
 *   folderfolio_folder_duplicated        (Folder $copy, Folder $source, array<int, int> $idMap, bool $withFiles)
 *   folderfolio_attachments_assigned     (list<int> $ids, int $folderId, string $mode)
 *   folderfolio_attachments_unassigned   (list<int> $ids, ?int $folderId)
 *
 * Filters:
 *   folderfolio_max_depth                (int)
 *   folderfolio_count_mode               (string 'inherited'|'direct')
 *
 * @phpstan-type FolderInput array{
 *     name?: mixed,
 *     parent_id?: int|string|null,
 *     color?: string|null,
 *     icon?: string|null,
 *     sort_order?: int|string,
 *     slug?: string|null,
 *     object_type?: string
 * }
 *
 * @phpstan-type FolderData array{
 *     name?: string,
 *     parent_id?: int|null,
 *     color?: string|null,
 *     icon?: string|null,
 *     sort_order?: int,
 *     slug?: string|null,
 *     object_type?: string
 * }
 */
class FolderService
{
    public const MODE_ADD = 'add';
    public const MODE_MOVE = 'move';

    public const CHILDREN_REPARENT = 'reparent';
    public const CHILDREN_CASCADE = 'cascade';

    public function __construct(
        private readonly FolderRepository $folders = new FolderRepository(),
        private readonly AttachmentFolderRepository $assignments = new AttachmentFolderRepository(),
        private readonly FolderSorts $sorts = new FolderSorts()
    ) {
    }

    /**
     * The folder tree, with counts.
     *
     * Two queries: the folders, and one GROUP BY for the counts. The roll-up
     * happens in PHP. Competitors either omit inherited counts or default to
     * direct-only; we default to inherited because a parent reading `0` while
     * holding a hundred files is worse than no badge at all.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(
        string $objectType = FolderRepository::DEFAULT_OBJECT_TYPE,
        ?string $countMode = null
    ): array {
        // Sorts before counts, and before the `none` branch below: a folder's
        // own order is not a count mode's business, and returning early
        // without it would hand the client a tree whose nodes are missing two
        // keys depending on a setting.
        $nodes = FolderTree::withSorts(
            FolderTree::fromRows($this->folders->all($objectType)),
            $this->sorts->all()
        );

        // No argument means "whatever the site is set to". The literal
        // default used to live here, which made the setting unreachable from
        // every caller that did not know to pass it.
        /** @var string $mode */
        $mode = apply_filters('folderfolio_count_mode', $countMode ?? Settings::get()['count_mode']);

        if ($mode === 'none') {
            return $nodes;
        }

        return FolderTree::withCounts(
            $nodes,
            $this->assignments->directCounts(),
            $mode !== 'direct'
        );
    }

    public function get(int $id): ?Folder
    {
        $row = $this->folders->find($id);

        return $row === null ? null : Folder::fromRow($row);
    }

    /**
     * Ancestors of a folder, outermost first. No queries for the chain itself.
     *
     * @return list<Folder>
     */
    public function ancestors(int $id): array
    {
        $folder = $this->get($id);

        if ($folder === null) {
            return [];
        }

        $ancestors = [];

        foreach ($folder->ancestorIds() as $ancestorId) {
            $row = $this->folders->find($ancestorId);

            if ($row !== null) {
                $ancestors[] = Folder::fromRow($row);
            }
        }

        return $ancestors;
    }

    /**
     * Ids of a folder and every folder beneath it.
     *
     * @return list<int>
     */
    public function subtreeIds(int $id): array
    {
        $folder = $this->get($id);

        return $folder === null ? [] : $this->folders->subtreeIds($folder->path);
    }

    /**
     * Create a folder.
     *
     * @param FolderInput $data
     * @return Folder|WP_Error
     */
    public function create(array $data): Folder|WP_Error
    {
        $data = $this->sanitize($data);
        $validation = $this->validate($data, true);

        if (is_wp_error($validation)) {
            return $validation;
        }

        $parentId = $data['parent_id'] ?? null;
        $parentPath = null;

        if ($parentId !== null) {
            $parent = $this->folders->find($parentId);

            if ($parent === null) {
                return new WP_Error(
                    'folderfolio_invalid_parent',
                    __('The selected parent folder does not exist.', 'folderfolio')
                );
            }

            $parentPath = (string) $parent['path'];

            $tooDeep = $this->guardDepth(FolderPath::depth($parentPath) + 1);

            if (is_wp_error($tooDeep)) {
                return $tooDeep;
            }
        }

        $objectType = $data['object_type'] ?? FolderRepository::DEFAULT_OBJECT_TYPE;

        if ($this->folders->siblingNameExists($data['name'] ?? '', $parentId, null, $objectType)) {
            return new WP_Error(
                'folderfolio_duplicate_name',
                __('A folder with that name already exists here.', 'folderfolio')
            );
        }

        $id = $this->folders->create($data, $parentPath);

        if (is_wp_error($id)) {
            return $id;
        }

        $folder = $this->get($id);

        if ($folder === null) {
            return new WP_Error(
                'folderfolio_folder_create_failed',
                __('Unable to create the folder.', 'folderfolio')
            );
        }

        do_action('folderfolio_folder_created', $folder);

        return $folder;
    }

    /**
     * Update a folder's own attributes. Use move() to change its parent.
     *
     * @param FolderInput $data
     * @return Folder|WP_Error
     */
    public function update(int $id, array $data): Folder|WP_Error
    {
        $existing = $this->get($id);

        if ($existing === null) {
            return new WP_Error(
                'folderfolio_folder_not_found',
                __('Folder not found.', 'folderfolio')
            );
        }

        $data = $this->sanitize($data, false);
        unset($data['parent_id'], $data['object_type']);

        $validation = $this->validate($data, false);

        if (is_wp_error($validation)) {
            return $validation;
        }

        if (
            isset($data['name'])
            && $data['name'] !== $existing->name
            && $this->folders->siblingNameExists(
                $data['name'],
                $existing->parentId,
                $id,
                $existing->objectType
            )
        ) {
            return new WP_Error(
                'folderfolio_duplicate_name',
                __('A folder with that name already exists here.', 'folderfolio')
            );
        }

        $updated = $this->folders->update($id, $data);

        if (is_wp_error($updated)) {
            return $updated;
        }

        $folder = $this->get($id) ?? $existing;

        if (isset($data['name']) && $data['name'] !== $existing->name) {
            do_action('folderfolio_folder_renamed', $folder, $existing->name);
        }

        return $folder;
    }

    /**
     * Move a folder, and its whole subtree with it.
     *
     * The cycle check is a string prefix test rather than a walk up the tree,
     * and re-pointing the descendants is one UPDATE however deep the subtree
     * goes. Both are what the path column is for.
     *
     * @return Folder|WP_Error
     */
    public function move(int $id, ?int $parentId): Folder|WP_Error
    {
        $folder = $this->get($id);

        if ($folder === null) {
            return new WP_Error(
                'folderfolio_folder_not_found',
                __('Folder not found.', 'folderfolio')
            );
        }

        if ($parentId === $id) {
            return new WP_Error(
                'folderfolio_circular_parent',
                __('A folder cannot be its own parent.', 'folderfolio')
            );
        }

        if ($parentId === $folder->parentId) {
            return $folder;
        }

        $parentPath = null;
        $newDepth = 0;

        if ($parentId !== null) {
            $parent = $this->folders->find($parentId);

            if ($parent === null) {
                return new WP_Error(
                    'folderfolio_invalid_parent',
                    __('The selected parent folder does not exist.', 'folderfolio')
                );
            }

            $parentPath = (string) $parent['path'];

            // The whole cycle check: is the proposed parent inside the subtree
            // we are about to move? One prefix comparison, no queries.
            if (FolderPath::isWithin($parentPath, $folder->path)) {
                return new WP_Error(
                    'folderfolio_circular_parent',
                    __('A folder cannot be moved inside one of its descendants.', 'folderfolio')
                );
            }

            $newDepth = FolderPath::depth($parentPath) + 1;
        }

        $subtreeDepth = $this->deepestDepthIn($folder->path) - $folder->depth;

        $tooDeep = $this->guardDepth($newDepth + $subtreeDepth);

        if (is_wp_error($tooDeep)) {
            return $tooDeep;
        }

        if (
            $this->folders->siblingNameExists($folder->name, $parentId, $id, $folder->objectType)
        ) {
            return new WP_Error(
                'folderfolio_duplicate_name',
                __('A folder with that name already exists there.', 'folderfolio')
            );
        }

        $newPath = FolderPath::build($parentPath, $id);

        // Two statements, and the pair is the invariant: `path`/`depth` on the
        // whole subtree, then `parent_id` on this folder. Apply one without the
        // other and the derived columns disagree with the source of truth —
        // which every subtree read then trusts, because they are all
        // `WHERE path LIKE '<path>%'`.
        return Transaction::run(function () use ($id, $parentId, $folder, $newPath, $newDepth): Folder|WP_Error {
            $rewritten = $this->folders->rewriteSubtreePaths(
                $folder->path,
                $newPath,
                $newDepth - $folder->depth
            );

            if (is_wp_error($rewritten)) {
                return $rewritten;
            }

            $updated = $this->folders->update($id, ['parent_id' => $parentId]);

            if (is_wp_error($updated)) {
                return $updated;
            }

            $moved = $this->get($id) ?? $folder;

            Transaction::after(static function () use ($moved, $folder): void {
                do_action('folderfolio_folder_moved', $moved, $folder->parentId);
            });

            return $moved;
        });
    }

    /**
     * Arrange a set of siblings.
     *
     * Takes the whole level in its new order rather than a delta. Whole-list
     * is idempotent, needs no index arithmetic, and cannot half-apply: a
     * client working from a stale tree gets a rejection instead of an order
     * that silently drops the folder somebody else just created.
     *
     * A drag that crosses parents is a move *and* a reorder, so the move is
     * folded in here rather than left to a second request. Two requests leave
     * the folder in its new parent at the wrong position when the second one
     * fails, and that wrong position is already on the screen.
     *
     * The move is delegated to move() rather than reimplemented: the cycle
     * check, the depth guard, the duplicate-name check and the subtree path
     * rewrite all live there, and Transaction nests by savepoint so the two
     * are still one unit of work.
     *
     * @param list<int> $ids The level's folders, in the order they should sit.
     * @return int|WP_Error Size of the level that was arranged.
     */
    public function reorder(?int $parentId, array $ids): int|WP_Error
    {
        $ids = array_values(array_map('intval', $ids));

        if ($ids === []) {
            return new WP_Error(
                'folderfolio_reorder_empty',
                __('No folders were given to arrange.', 'folderfolio')
            );
        }

        if (count($ids) !== count(array_unique($ids))) {
            return new WP_Error(
                'folderfolio_reorder_duplicate',
                __('The same folder was listed more than once.', 'folderfolio')
            );
        }

        if ($parentId !== null && in_array($parentId, $ids, true)) {
            return new WP_Error(
                'folderfolio_circular_parent',
                __('A folder cannot be its own parent.', 'folderfolio')
            );
        }

        /** @var array<int, Folder> $folders */
        $folders = [];

        foreach ($ids as $id) {
            $folder = $this->get($id);

            if ($folder === null) {
                return new WP_Error(
                    'folderfolio_folder_not_found',
                    __('Folder not found.', 'folderfolio')
                );
            }

            $folders[$id] = $folder;
        }

        $objectType = $folders[$ids[0]]->objectType;

        if ($parentId !== null) {
            $parent = $this->folders->find($parentId);

            if ($parent === null) {
                return new WP_Error(
                    'folderfolio_invalid_parent',
                    __('The selected parent folder does not exist.', 'folderfolio')
                );
            }

            $objectType = (string) $parent['object_type'];
        }

        foreach ($folders as $folder) {
            if ($folder->objectType !== $objectType) {
                return new WP_Error(
                    'folderfolio_reorder_mixed',
                    __('Those folders do not all belong to the same level.', 'folderfolio')
                );
            }
        }

        // The list has to describe the level as it will be, not a subset of
        // it. Anything the caller left out would keep whatever sort_order it
        // has and land somewhere nobody chose.
        $existing = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->folders->siblingsOf($parentId, $objectType)
        );

        if (array_values(array_diff($existing, $ids)) !== []) {
            return new WP_Error(
                'folderfolio_reorder_stale',
                __('This folder list is out of date. Reload the page and try again.', 'folderfolio')
            );
        }

        $incoming = array_values(array_diff($ids, $existing));

        return Transaction::run(function () use ($ids, $parentId, $incoming): int|WP_Error {
            foreach ($incoming as $id) {
                $moved = $this->move($id, $parentId);

                if (is_wp_error($moved)) {
                    return $moved;
                }
            }

            $written = $this->folders->applySortOrder($ids);

            if (is_wp_error($written)) {
                return $written;
            }

            Transaction::after(static function () use ($ids, $parentId): void {
                do_action('folderfolio_folders_reordered', $ids, $parentId);
            });

            return $written;
        });
    }

    /**
     * Paste a copy of a folder, and everything beneath it.
     *
     * Names, colours, icons, each folder's place among its siblings and both
     * of its own orders, at every depth — and, when `$withFiles`, every file
     * filed in the same folders of the copy, in the same positions. Nothing is
     * duplicated in the media library itself: membership is many-to-many, so
     * "with files" means the same attachments filed in two places.
     *
     * Everything that can refuse the paste is asked **before** the transaction
     * opens, the way `FolderBulk::plan()` does: the parent, pasting a folder
     * inside itself, the depth of the whole subtree at its new home, and — with
     * files — whether this person may file every one of them. A paste that is
     * going to be refused should not cost a half-built tree and a rollback.
     * Then one `Transaction`, the rule every batch write here follows: a
     * failure at the fortieth folder undoes the first thirty-nine.
     *
     * Only the top folder's name can collide — `FolderCopy::name()` — because
     * every folder beneath it lands under a parent that did not exist a moment
     * ago. It goes last in its level unless `$order` places it: the level as
     * the person sees it with a `0` where the copy goes, which is handed to
     * `reorder()` inside the same unit of work. The client sends that only
     * when the level is in Custom order, the one sort in which a position is
     * something a person can see.
     *
     * @param list<int>|null $order
     * @return Folder|WP_Error The copy of the top folder.
     */
    public function duplicate(
        int $id,
        ?int $parentId,
        bool $withFiles = false,
        ?array $order = null
    ): Folder|WP_Error {
        $source = $this->get($id);

        if ($source === null) {
            return new WP_Error(
                'folderfolio_folder_not_found',
                __('Folder not found.', 'folderfolio')
            );
        }

        $objectType = $source->objectType;
        $newDepth = 0;

        if ($parentId !== null) {
            $parent = $this->folders->find($parentId);

            if ($parent === null || (string) $parent['object_type'] !== $objectType) {
                return new WP_Error(
                    'folderfolio_invalid_parent',
                    __('The selected parent folder does not exist.', 'folderfolio')
                );
            }

            $parentPath = (string) $parent['path'];

            // Finder refuses this too. A copy taken as a snapshot could be
            // pasted inside itself, but "Brand inside Brand/Logos" is almost
            // always a slip of the pointer, and refusing it keeps one rule for
            // cut and copy alike.
            if (FolderPath::isWithin($parentPath, $source->path)) {
                return new WP_Error(
                    'folderfolio_paste_into_itself',
                    __('A folder cannot be pasted inside itself.', 'folderfolio')
                );
            }

            $newDepth = FolderPath::depth($parentPath) + 1;
        }

        // Depth ascending, so every folder's parent has been copied before it
        // is — the id map below is always ready when a child asks it.
        $rows = $this->folders->subtree($source->path);
        $deepest = $source->depth;

        foreach ($rows as $row) {
            $deepest = max($deepest, (int) $row['depth']);
        }

        $tooDeep = $this->guardDepth($newDepth + ($deepest - $source->depth));

        if (is_wp_error($tooDeep)) {
            return $tooDeep;
        }

        if ($order !== null) {
            $order = array_values(array_map('intval', $order));

            if (FolderCopy::place($order, PHP_INT_MAX) === null) {
                return new WP_Error(
                    'folderfolio_reorder_malformed',
                    __('This folder list is out of date. Reload the page and try again.', 'folderfolio')
                );
            }
        }

        /** @var array<int, list<int>> $files */
        $files = [];

        if ($withFiles) {
            $everything = [];

            foreach ($rows as $row) {
                $files[(int) $row['id']] = $this->assignments->attachmentIdsForFolder((int) $row['id']);
                array_push($everything, ...$files[(int) $row['id']]);
            }

            // The same question assignAttachments() asks, over the whole
            // subtree at once and before anything is written: one file this
            // person may not organise refuses the paste, rather than a copy
            // that silently leaves it out and looks complete.
            $permitted = $this->guardAttachments(array_values(array_unique($everything)));

            if (is_wp_error($permitted)) {
                return $permitted;
            }
        }

        $name = FolderCopy::name(
            $source->name,
            fn (string $candidate): bool => $this->folders->siblingNameExists(
                $candidate,
                $parentId,
                null,
                $objectType
            ),
            /* translators: %s: the name of the folder that was copied. */
            __('%s copy', 'folderfolio'),
            /* translators: 1: the name of the folder that was copied, 2: a number, 2 or more. */
            __('%1$s copy %2$d', 'folderfolio')
        );

        // Last in its level: one past the highest sort_order there. Under any
        // sort but Custom the position is not visible and this is harmless;
        // under Custom it is what "paste inside" says.
        $last = 0;

        foreach ($this->folders->siblingsOf($parentId, $objectType) as $sibling) {
            $last = max($last, (int) $sibling['sort_order'] + 1);
        }

        $sorts = $this->sorts->all();

        return Transaction::run(function () use (
            $rows,
            $source,
            $parentId,
            $objectType,
            $name,
            $last,
            $sorts,
            $files,
            $withFiles,
            $order
        ): Folder|WP_Error {
            /** @var array<int, int> $map source id => copy id */
            $map = [];

            foreach ($rows as $row) {
                $oldId = (int) $row['id'];
                $isTop = $oldId === $source->id;

                $created = $this->create([
                    'name'        => $isTop ? $name : (string) $row['name'],
                    'parent_id'   => $isTop ? $parentId : $map[(int) $row['parent_id']],
                    'object_type' => $objectType,
                    'color'       => $row['color'] ?? null,
                    'icon'        => $row['icon'] ?? null,
                    'sort_order'  => $isTop ? $last : (int) $row['sort_order'],
                ]);

                if (is_wp_error($created)) {
                    return $created;
                }

                $map[$oldId] = $created->id;

                foreach (FolderSorts::SCOPES as $scope) {
                    $chosen = $sorts[$oldId][$scope] ?? null;

                    if ($chosen === null) {
                        continue;
                    }

                    $written = $this->sorts->set($created->id, $scope, $chosen);

                    // A stored order that is no longer one of ours is not a
                    // reason to refuse the paste — the client already ignores
                    // it (isSortOrder) and the copy simply follows the global
                    // sort. A write that failed is.
                    if (is_wp_error($written) && $written->get_error_code() === 'folderfolio_sort_failed') {
                        return $written;
                    }
                }

                if ($withFiles && ($files[$oldId] ?? []) !== []) {
                    $copied = $this->assignments->copyFolder($oldId, $created->id);

                    if (is_wp_error($copied)) {
                        return $copied;
                    }
                }
            }

            $copyId = $map[$source->id];

            if ($order !== null) {
                $placed = FolderCopy::place($order, $copyId);
                $arranged = $placed === null
                    ? new WP_Error(
                        'folderfolio_reorder_malformed',
                        __('This folder list is out of date. Reload the page and try again.', 'folderfolio')
                    )
                    : $this->reorder($parentId, $placed);

                if (is_wp_error($arranged)) {
                    return $arranged;
                }
            }

            $copy = $this->get($copyId);

            if ($copy === null) {
                return new WP_Error(
                    'folderfolio_folder_create_failed',
                    __('Unable to create the folder.', 'folderfolio')
                );
            }

            Transaction::after(static function () use ($copy, $source, $map, $files, $withFiles): void {
                // The filing hook once per folder that received files, so a
                // listener keeping its own index sees every row this wrote —
                // the same contract a bulk Add to folder keeps.
                foreach ($files as $oldId => $ids) {
                    if ($ids !== []) {
                        do_action('folderfolio_attachments_assigned', $ids, $map[$oldId], self::MODE_ADD);
                    }
                }

                do_action('folderfolio_folder_duplicated', $copy, $source, $map, $withFiles);
            });

            return $copy;
        });
    }

    /**
     * Delete a folder.
     *
     * $children is required by the REST route rather than defaulted there:
     * "what happens to my subfolders" is not a question to answer silently.
     *
     * @param string $children One of the CHILDREN_* constants; validated below.
     * @return int|WP_Error Number of folders deleted.
     */
    public function delete(
        int $id,
        string $children = self::CHILDREN_REPARENT,
        ?int $reassignAttachmentsTo = null
    ): int|WP_Error {
        $folder = $this->get($id);

        if ($folder === null) {
            return new WP_Error(
                'folderfolio_folder_not_found',
                __('Folder not found.', 'folderfolio')
            );
        }

        if (!in_array($children, [self::CHILDREN_REPARENT, self::CHILDREN_CASCADE], true)) {
            return new WP_Error(
                'folderfolio_invalid_children_strategy',
                __('Choose whether subfolders are kept or deleted.', 'folderfolio')
            );
        }

        // Validated before the transaction opens: nothing here writes, and a
        // request that is going to be refused should not cost a transaction.
        if (
            $reassignAttachmentsTo !== null
            && ($reassignAttachmentsTo === $id || $this->folders->find($reassignAttachmentsTo) === null)
        ) {
            return new WP_Error(
                'folderfolio_invalid_reassignment',
                __('Choose a valid destination folder.', 'folderfolio')
            );
        }

        /*
         * The whole delete is one unit, reassignment included. Both branches
         * below remove the assignment rows before the folders, and the rows are
         * the only record of what was in them — so a failure between the two
         * statements used to leave folders standing and empty, with nothing
         * anywhere able to say what they had held. Reassignment is inside the
         * same unit rather than before it: a delete that fails after moving
         * someone's files out of the folder they were looking at is not a
         * delete that failed, it is a move they did not ask for.
         */
        return Transaction::run(function () use (
            $id,
            $children,
            $reassignAttachmentsTo,
            $folder
        ): int|WP_Error {
            if ($reassignAttachmentsTo !== null) {
                $source = $children === self::CHILDREN_CASCADE
                    ? $this->assignments->subtreeAttachmentIds($folder->path)
                    : $this->assignments->attachmentIdsForFolder($id);

                $reassigned = $this->assignAttachments($reassignAttachmentsTo, $source, self::MODE_ADD);

                if (is_wp_error($reassigned)) {
                    return $reassigned;
                }
            }

            if ($children === self::CHILDREN_CASCADE) {
                $ids = $this->folders->subtreeIds($folder->path);

                $cleaned = $this->assignments->deleteForFolders($ids);

                if (is_wp_error($cleaned)) {
                    return $cleaned;
                }

                $deleted = $this->folders->deleteSubtree($folder->path);

                if (is_wp_error($deleted)) {
                    return $deleted;
                }

                Transaction::after(static function () use ($id, $children, $ids): void {
                    do_action('folderfolio_folder_deleted', $id, $children, $ids);
                });

                return $deleted;
            }

            // Reparent: each child takes this folder's place in the hierarchy.
            // Each move() opens a savepoint inside this transaction, so a
            // failure at the fourth child undoes the first three as well —
            // before, they stayed moved and the folder stayed put.
            foreach ($this->folders->children($id) as $child) {
                $moved = $this->move((int) $child['id'], $folder->parentId);

                if (is_wp_error($moved)) {
                    return $moved;
                }
            }

            $cleaned = $this->assignments->deleteForFolder($id);

            if (is_wp_error($cleaned)) {
                return $cleaned;
            }

            $removed = $this->folders->delete($id);

            if (is_wp_error($removed)) {
                return $removed;
            }

            Transaction::after(static function () use ($id, $children): void {
                do_action('folderfolio_folder_deleted', $id, $children, [$id]);
            });

            return 1;
        });
    }

    /**
     * File attachments into a folder.
     *
     * MODE_ADD leaves every existing assignment alone — this is what makes the
     * migration wizard safe, and it is the behaviour no competitor offers:
     * all four examined implement assignment as delete-then-insert, so
     * importing takes a file out of whatever folder its owner had put it in.
     *
     * MODE_MOVE is the drag gesture: file here, and nowhere else.
     *
     * $importRun marks the rows as one import's work, so that undoing that
     * import removes exactly them and leaves anything a person filed — before
     * it, or during it — where it is. Null for every other caller, which is
     * all of them but the migration wizard.
     *
     * @param list<int|string> $attachmentIds
     * @param string           $mode One of the MODE_* constants; validated below.
     * @return int|WP_Error Number of attachments filed.
     */
    public function assignAttachments(
        int $folderId,
        array $attachmentIds,
        string $mode = self::MODE_ADD,
        ?string $importRun = null
    ): int|WP_Error {
        if ($this->folders->find($folderId) === null) {
            return new WP_Error(
                'folderfolio_folder_not_found',
                __('Folder not found.', 'folderfolio')
            );
        }

        if (!in_array($mode, [self::MODE_ADD, self::MODE_MOVE], true)) {
            return new WP_Error(
                'folderfolio_invalid_mode',
                __('Choose whether to add to the folder or move into it.', 'folderfolio')
            );
        }

        $ids = array_values(array_unique(array_map('intval', $attachmentIds)));

        $permitted = $this->guardAttachments($ids);

        if (is_wp_error($permitted)) {
            return $permitted;
        }

        if ($ids === []) {
            return 0;
        }

        /*
         * All or nothing, for two reasons.
         *
         * MODE_MOVE is the sharp one: it deletes an attachment's existing rows
         * and then writes the new one, so a failure between those two left the
         * file in no folder at all — its previous filing gone and the new one
         * never written. That is the drag gesture, and it failed silently.
         *
         * The batch as a whole matters too. A bulk action that failed at item
         * seven of twenty used to leave six filed and report an error, so the
         * undo toast offered to undo work that had only partly happened. Now
         * the error means nothing was done, which is a sentence the UI can tell
         * the truth with.
         */
        return Transaction::run(function () use ($ids, $folderId, $mode, $importRun): int|WP_Error {
            $assigned = 0;

            foreach ($ids as $position => $attachmentId) {
                if ($mode === self::MODE_MOVE) {
                    $cleared = $this->assignments->deleteForAttachment($attachmentId);

                    if (is_wp_error($cleared)) {
                        return $cleared;
                    }
                }

                $result = $this->assignments->assign($folderId, $attachmentId, $position, $importRun);

                if (is_wp_error($result)) {
                    return $result;
                }

                $assigned++;
            }

            Transaction::after(static function () use ($ids, $folderId, $mode): void {
                do_action('folderfolio_attachments_assigned', $ids, $folderId, $mode);
            });

            return $assigned;
        });
    }

    /**
     * Remove attachments from a folder, or from every folder when null.
     *
     * @param list<int|string> $attachmentIds
     * @return int|WP_Error
     */
    public function unassignAttachments(?int $folderId, array $attachmentIds): int|WP_Error
    {
        $ids = array_values(array_unique(array_map('intval', $attachmentIds)));

        $permitted = $this->guardAttachments($ids, false);

        if (is_wp_error($permitted)) {
            return $permitted;
        }

        if ($ids === []) {
            return 0;
        }

        return Transaction::run(function () use ($ids, $folderId): int|WP_Error {
            $removed = 0;

            foreach ($ids as $attachmentId) {
                $result = $folderId === null
                    ? $this->assignments->deleteForAttachment($attachmentId)
                    : $this->assignments->unassign($folderId, $attachmentId);

                if (is_wp_error($result)) {
                    return $result;
                }

                $removed++;
            }

            Transaction::after(static function () use ($ids, $folderId): void {
                do_action('folderfolio_attachments_unassigned', $ids, $folderId);
            });

            return $removed;
        });
    }

    /**
     * Move attachments from one folder to another.
     *
     * @param list<int|string> $attachmentIds
     * @return int|WP_Error
     */
    public function moveAttachments(
        int $sourceFolderId,
        int $destinationFolderId,
        array $attachmentIds
    ): int|WP_Error {
        if ($sourceFolderId === $destinationFolderId) {
            return new WP_Error(
                'folderfolio_same_folder',
                __('Choose a different destination folder.', 'folderfolio')
            );
        }

        // Add first, then remove — so the failure this used to have left a file
        // in both folders rather than neither, which is the recoverable
        // direction. It is still wrong: the return value counted the additions
        // and said nothing about a removal that had not happened.
        return Transaction::run(function () use (
            $sourceFolderId,
            $destinationFolderId,
            $attachmentIds
        ): int|WP_Error {
            $assigned = $this->assignAttachments($destinationFolderId, $attachmentIds, self::MODE_ADD);

            if (is_wp_error($assigned)) {
                return $assigned;
            }

            $removed = $this->unassignAttachments($sourceFolderId, $attachmentIds);

            if (is_wp_error($removed)) {
                return $removed;
            }

            return $assigned;
        });
    }

    /**
     * Attachment ids in a folder, optionally including its descendants.
     *
     * @return list<int>
     */
    public function attachmentIds(int $folderId, bool $includeDescendants = false): array
    {
        if (!$includeDescendants) {
            return $this->assignments->attachmentIdsForFolder($folderId);
        }

        $folder = $this->get($folderId);

        return $folder === null
            ? []
            : $this->assignments->subtreeAttachmentIds($folder->path);
    }

    /**
     * Exact attachment count for a folder, optionally including descendants.
     */
    public function countAttachments(int $folderId, bool $includeDescendants = true): int
    {
        $folder = $this->get($folderId);

        if ($folder === null) {
            return 0;
        }

        if (!$includeDescendants) {
            return count($this->assignments->attachmentIdsForFolder($folderId));
        }

        return $this->assignments->subtreeCount($folder->path);
    }

    /**
     * Deepest depth value anywhere in a subtree.
     */
    private function deepestDepthIn(string $path): int
    {
        $deepest = 0;

        foreach ($this->folders->subtree($path) as $row) {
            $deepest = max($deepest, (int) $row['depth']);
        }

        return $deepest;
    }

    /**
     * Native `bool|WP_Error`, docblock `true|WP_Error`.
     *
     * A standalone `true` in a type position is PHP **8.2**, and this plugin
     * supports 8.1 — where the parser reads it as a class name and fatals with
     * "Cannot use 'FolderFolio\Domain\true' as class name as it is reserved",
     * before a single test runs. The docblock keeps the precision for the
     * analyser; phpstan.neon pins the version range so the next one is caught
     * here rather than by the 8.1 leg of CI.
     *
     * @return true|WP_Error
     */
    private function guardDepth(int $depth): bool|WP_Error
    {
        /** @var int $max */
        $max = apply_filters('folderfolio_max_depth', FolderPath::MAX_DEPTH);

        if ($depth > $max) {
            return new WP_Error(
                'folderfolio_max_depth_exceeded',
                sprintf(
                    /* translators: %d: maximum nesting depth. */
                    __('Folders can be nested up to %d levels deep.', 'folderfolio'),
                    $max
                )
            );
        }

        return true;
    }

    /**
     * @param list<int> $ids
     * @return true|WP_Error
     */
    private function guardAttachments(array $ids, bool $requireAttachment = true): bool|WP_Error
    {
        foreach ($ids as $attachmentId) {
            // wp_attachment_is_image() implied the post type anyway; the only
            // thing that ever asserted was "is an attachment".
            if ($requireAttachment && get_post_type($attachmentId) !== 'attachment') {
                return new WP_Error(
                    'folderfolio_invalid_attachment',
                    __('One or more selected media items are invalid.', 'folderfolio')
                );
            }

            if (!Capabilities::canEditAttachment($attachmentId)) {
                return new WP_Error(
                    'folderfolio_attachment_forbidden',
                    __('You are not allowed to organize one or more of the selected media items.', 'folderfolio')
                );
            }
        }

        return true;
    }

    /**
     * Normalize folder input for creation or update.
     *
     * @param FolderInput $data
     * @return FolderData
     */
    private function sanitize(array $data, bool $creating = true): array
    {
        $result = [];

        if ($creating || array_key_exists('name', $data)) {
            $result['name'] = sanitize_text_field((string) ($data['name'] ?? ''));
        }

        if ($creating || array_key_exists('parent_id', $data)) {
            $parentId = $data['parent_id'] ?? null;
            $result['parent_id'] = $parentId === null || $parentId === ''
                ? null
                : absint($parentId);
        }

        if (array_key_exists('object_type', $data)) {
            $result['object_type'] = sanitize_key((string) $data['object_type']);
        }

        if (array_key_exists('color', $data)) {
            if ($data['color'] === null || $data['color'] === '') {
                $result['color'] = null;
            } else {
                // A swatch name, or a hex snapped to the nearest one — see
                // Support\Swatches. Keep the raw value when it is neither, so
                // validate() can report the problem; nulling it here would turn
                // a bad colour into a silent no-op.
                $raw = (string) $data['color'];
                $result['color'] = Swatches::normalize($raw) ?? $raw;
            }
        }

        if (array_key_exists('icon', $data)) {
            $result['icon'] = $data['icon'] === null || $data['icon'] === ''
                ? null
                : sanitize_key((string) $data['icon']);
        }

        if (array_key_exists('sort_order', $data)) {
            $result['sort_order'] = (int) $data['sort_order'];
        }

        return $result;
    }

    /**
     * Validate sanitized folder data.
     *
     * @param FolderData $data
     * @return true|WP_Error
     */
    private function validate(array $data, bool $creating): bool|WP_Error
    {
        if ($creating && ($data['name'] ?? '') === '') {
            return new WP_Error(
                'folderfolio_name_required',
                __('A folder name is required.', 'folderfolio')
            );
        }

        if (isset($data['name']) && $data['name'] === '') {
            return new WP_Error(
                'folderfolio_name_required',
                __('A folder name is required.', 'folderfolio')
            );
        }

        if (isset($data['name']) && mb_strlen($data['name']) > 191) {
            return new WP_Error(
                'folderfolio_name_too_long',
                __('Folder names must be 191 characters or fewer.', 'folderfolio')
            );
        }

        if (
            array_key_exists('color', $data)
            && $data['color'] !== null
            && !Swatches::isKey($data['color'])
        ) {
            return new WP_Error(
                'folderfolio_invalid_color',
                sprintf(
                    /* translators: %s: comma-separated list of the ten folder colour names. */
                    __('Choose one of the folder colors: %s.', 'folderfolio'),
                    implode(', ', Swatches::keys())
                )
            );
        }

        return true;
    }

    /**
     * Direct children of a folder.
     *
     * @return list<Folder>
     */
    public function children(int $id): array
    {
        return array_map(
            static fn (array $row): Folder => Folder::fromRow($row),
            $this->folders->children($id)
        );
    }

    /**
     * Folders an attachment is filed in.
     *
     * @return list<Folder>
     */
    public function foldersOf(int $attachmentId): array
    {
        $folders = [];

        foreach ($this->assignments->folderIdsForAttachment($attachmentId) as $folderId) {
            $row = $this->folders->find($folderId);

            if ($row !== null) {
                $folders[] = Folder::fromRow($row);
            }
        }

        return $folders;
    }

    /**
     * Resolve a human path such as "Brand/Logos/Primary".
     *
     * Names, not ids — this is the path a person types, not the internal id
     * path stored in the `path` column.
     */
    public function findByPath(
        string $path,
        string $objectType = FolderRepository::DEFAULT_OBJECT_TYPE
    ): ?Folder {
        $parentId = null;
        $found = null;

        foreach ($this->splitPath($path) as $segment) {
            $row = $this->folders->findByName($segment, $parentId, $objectType);

            if ($row === null) {
                return null;
            }

            $found = Folder::fromRow($row);
            $parentId = $found->id;
        }

        return $found;
    }

    /**
     * Resolve a human path, creating whatever part of it does not exist yet.
     *
     * Idempotent, so calling it on every upload is fine. This is the method
     * most integrations actually want.
     *
     * `$parentId` is where the path starts, and it defaults to the top level,
     * which is what every caller before `Domain\FolderBulk` wanted. It is a
     * seed for the same walk rather than a mode: with it set, "Logos/Primary"
     * means those two under that folder, and the method is otherwise
     * unchanged. Nothing validates it here — `create()` rejects a parent that
     * does not exist, on the first segment, before anything is written.
     *
     * @return Folder|WP_Error
     */
    public function getOrCreateByPath(
        string $path,
        string $objectType = FolderRepository::DEFAULT_OBJECT_TYPE,
        ?int $parentId = null
    ): Folder|WP_Error {
        $segments = $this->splitPath($path);

        if ($segments === []) {
            return new WP_Error(
                'folderfolio_name_required',
                __('A folder name is required.', 'folderfolio')
            );
        }

        $current = null;

        foreach ($segments as $segment) {
            $row = $this->folders->findByName($segment, $parentId, $objectType);

            if ($row !== null) {
                $current = Folder::fromRow($row);
                $parentId = $current->id;
                continue;
            }

            $created = $this->create([
                'name'        => $segment,
                'parent_id'   => $parentId,
                'object_type' => $objectType,
            ]);

            if (is_wp_error($created)) {
                return $created;
            }

            $current = $created;
            $parentId = $created->id;
        }

        // Always set: $segments is non-empty, and every path through the loop
        // either assigns $current or returns early.
        return $current;
    }

    /**
     * Split a human path into its non-empty segments.
     *
     * Tolerates leading, trailing and doubled separators, so "/a//b/" and
     * "a/b" mean the same thing.
     *
     * @return list<string>
     */
    private function splitPath(string $path): array
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            $segment = trim($segment);

            if ($segment !== '') {
                $segments[] = $segment;
            }
        }

        return $segments;
    }

    /**
     * The subtree under one folder, as nested nodes with counts.
     *
     * @return list<array<string, mixed>>
     */
    public function subtree(int $rootId, string $objectType = FolderRepository::DEFAULT_OBJECT_TYPE): array
    {
        $found = [];

        FolderTree::walk(
            $this->tree($objectType),
            static function (array $node) use (&$found, $rootId): void {
                if ((int) $node['id'] === $rootId) {
                    $found = [$node];
                }
            }
        );

        return $found;
    }
}
