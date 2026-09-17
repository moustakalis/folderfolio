<?php

declare(strict_types=1);

namespace FolderFolio\Domain;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Support\Capabilities;
use FolderFolio\Support\Settings;
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
        private readonly AttachmentFolderRepository $assignments = new AttachmentFolderRepository()
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
        $nodes = FolderTree::fromRows($this->folders->all($objectType));

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

        do_action('folderfolio_folder_moved', $moved, $folder->parentId);

        return $moved;
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

        if ($reassignAttachmentsTo !== null) {
            if ($reassignAttachmentsTo === $id || $this->folders->find($reassignAttachmentsTo) === null) {
                return new WP_Error(
                    'folderfolio_invalid_reassignment',
                    __('Choose a valid destination folder.', 'folderfolio')
                );
            }

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

            do_action('folderfolio_folder_deleted', $id, $children, $ids);

            return $deleted;
        }

        // Reparent: each child takes this folder's place in the hierarchy.
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

        do_action('folderfolio_folder_deleted', $id, $children, [$id]);

        return 1;
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
     * @param list<int|string> $attachmentIds
     * @param string           $mode One of the MODE_* constants; validated below.
     * @return int|WP_Error Number of attachments filed.
     */
    public function assignAttachments(
        int $folderId,
        array $attachmentIds,
        string $mode = self::MODE_ADD
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

        $assigned = 0;

        foreach ($ids as $position => $attachmentId) {
            if ($mode === self::MODE_MOVE) {
                $cleared = $this->assignments->deleteForAttachment($attachmentId);

                if (is_wp_error($cleared)) {
                    return $cleared;
                }
            }

            $result = $this->assignments->assign($folderId, $attachmentId, $position);

            if (is_wp_error($result)) {
                return $result;
            }

            $assigned++;
        }

        if ($ids !== []) {
            do_action('folderfolio_attachments_assigned', $ids, $folderId, $mode);
        }

        return $assigned;
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

        if ($ids !== []) {
            do_action('folderfolio_attachments_unassigned', $ids, $folderId);
        }

        return $removed;
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

        $assigned = $this->assignAttachments($destinationFolderId, $attachmentIds, self::MODE_ADD);

        if (is_wp_error($assigned)) {
            return $assigned;
        }

        $removed = $this->unassignAttachments($sourceFolderId, $attachmentIds);

        if (is_wp_error($removed)) {
            return $removed;
        }

        return $assigned;
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
     * @return true|WP_Error
     */
    private function guardDepth(int $depth): true|WP_Error
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
    private function guardAttachments(array $ids, bool $requireAttachment = true): true|WP_Error
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
                // Keep the raw value when sanitize_hex_color() rejects it, so
                // validate() can report the problem. Nulling it here would turn
                // a bad colour into a silent no-op.
                $raw = (string) $data['color'];
                $result['color'] = sanitize_hex_color($raw) ?? $raw;
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
    private function validate(array $data, bool $creating): true|WP_Error
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
            && !preg_match('/^#[a-fA-F0-9]{6}$/', $data['color'])
        ) {
            return new WP_Error(
                'folderfolio_invalid_color',
                __('Choose a valid folder color.', 'folderfolio')
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
     * @return Folder|WP_Error
     */
    public function getOrCreateByPath(
        string $path,
        string $objectType = FolderRepository::DEFAULT_OBJECT_TYPE
    ): Folder|WP_Error {
        $segments = $this->splitPath($path);

        if ($segments === []) {
            return new WP_Error(
                'folderfolio_name_required',
                __('A folder name is required.', 'folderfolio')
            );
        }

        $parentId = null;
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
