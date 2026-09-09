<?php

declare(strict_types=1);

namespace FolderFolio\Domain;

use WP_Error;

class FolderService
{
    public function __construct(
        private readonly FolderRepository $folders = new FolderRepository(),
        private readonly AttachmentFolderRepository $assignments = new AttachmentFolderRepository()
    ) {
    }

    public function tree(): array
    {
        $items = $this->folders->all();
        $byParent = [];

        foreach ($items as $item) {
            $parentId = $item['parent_id'] === null ? 0 : (int) $item['parent_id'];
            $item['id'] = (int) $item['id'];
            $item['parent_id'] = $item['parent_id'] === null ? null : (int) $item['parent_id'];
            $item['children'] = [];
            $byParent[$parentId][] = $item;
        }

        $build = function (int $parentId) use (&$build, &$byParent): array {
            $nodes = $byParent[$parentId] ?? [];
            foreach ($nodes as &$node) {
                $node['children'] = $build($node['id']);
            }
            unset($node);
            return $nodes;
        };

        return $build(0);
    }

    public function create(array $data): int|WP_Error
    {
        $data = $this->sanitize($data);
        $validation = $this->validate($data, true);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $parentId = $data['parent_id'] ?? null;

        if ($parentId !== null && $this->folders->find($parentId) === null) {
            return new WP_Error('folderfolio_invalid_parent', __('The selected parent folder does not exist.', 'folderfolio'));
        }

        return $this->folders->create($data);
    }

    public function update(int $id, array $data): bool|WP_Error
    {
        if ($this->folders->find($id) === null) {
            return new WP_Error('folderfolio_folder_not_found', __('Folder not found.', 'folderfolio'));
        }

        $data = $this->sanitize($data, false);
        $validation = $this->validate($data, false);
        if (is_wp_error($validation)) {
            return $validation;
        }

        return $this->folders->update($id, $data);
    }

    public function move(int $id, ?int $parentId): bool|WP_Error
    {
        if ($this->folders->find($id) === null) {
            return new WP_Error('folderfolio_folder_not_found', __('Folder not found.', 'folderfolio'));
        }

        if ($parentId === $id) {
            return new WP_Error('folderfolio_circular_parent', __('A folder cannot be its own parent.', 'folderfolio'));
        }

        if ($parentId !== null) {
            if ($this->folders->find($parentId) === null) {
                return new WP_Error('folderfolio_invalid_parent', __('The selected parent folder does not exist.', 'folderfolio'));
            }

            if ($this->isDescendant($parentId, $id)) {
                return new WP_Error('folderfolio_circular_parent', __('A folder cannot be moved inside one of its descendants.', 'folderfolio'));
            }
        }

        return $this->folders->update($id, ['parent_id' => $parentId]);
    }

    public function delete(int $id, ?int $reassignTo = null): bool|WP_Error
    {
        if ($this->folders->find($id) === null) {
            return new WP_Error('folderfolio_folder_not_found', __('Folder not found.', 'folderfolio'));
        }

        if ($reassignTo !== null) {
            if ($reassignTo === $id || $this->folders->find($reassignTo) === null) {
                return new WP_Error('folderfolio_invalid_reassignment', __('Choose a valid destination folder.', 'folderfolio'));
            }

            foreach ($this->assignments->attachmentIdsForFolder($id) as $attachmentId) {
                $assigned = $this->assignments->assign($reassignTo, $attachmentId);
                if (is_wp_error($assigned)) {
                    return $assigned;
                }
            }
        }

        foreach ($this->folders->children($id) as $child) {
            $moved = $this->move((int) $child['id'], null);
            if (is_wp_error($moved)) {
                return $moved;
            }
        }

        $cleaned = $this->assignments->deleteForFolder($id);
        if (is_wp_error($cleaned)) {
            return $cleaned;
        }

        return $this->folders->delete($id);
    }

    public function assignAttachments(int $folderId, array $attachmentIds): int|WP_Error
    {
        if ($this->folders->find($folderId) === null) {
            return new WP_Error('folderfolio_folder_not_found', __('Folder not found.', 'folderfolio'));
        }

        $assigned = 0;
        foreach (array_unique(array_map('intval', $attachmentIds)) as $position => $attachmentId) {
            if (!wp_attachment_is_image($attachmentId) && get_post_type($attachmentId) !== 'attachment') {
                return new WP_Error('folderfolio_invalid_attachment', __('One or more selected media items are invalid.', 'folderfolio'));
            }

            $result = $this->assignments->assign($folderId, $attachmentId, $position);
            if (is_wp_error($result)) {
                return $result;
            }
            $assigned++;
        }

        return $assigned;
    }

    public function unassignAttachments(int $folderId, array $attachmentIds): int|WP_Error
    {
        $removed = 0;
        foreach (array_unique(array_map('intval', $attachmentIds)) as $attachmentId) {
            $result = $this->assignments->unassign($folderId, $attachmentId);
            if (is_wp_error($result)) {
                return $result;
            }
            $removed++;
        }

        return $removed;
    }

    public function moveAttachments(int $sourceFolderId, int $destinationFolderId, array $attachmentIds): int|WP_Error
    {
        if ($sourceFolderId === $destinationFolderId) {
            return new WP_Error('folderfolio_same_folder', __('Choose a different destination folder.', 'folderfolio'));
        }

        $assigned = $this->assignAttachments($destinationFolderId, $attachmentIds);
        if (is_wp_error($assigned)) {
            return $assigned;
        }

        $removed = $this->unassignAttachments($sourceFolderId, $attachmentIds);
        if (is_wp_error($removed)) {
            return $removed;
        }

        return $assigned;
    }

    public function attachmentIds(int $folderId): array
    {
        return $this->assignments->attachmentIdsForFolder($folderId);
    }

    private function isDescendant(int $candidateId, int $ancestorId): bool
    {
        $candidate = $this->folders->find($candidateId);
        while ($candidate !== null && $candidate['parent_id'] !== null) {
            if ((int) $candidate['parent_id'] === $ancestorId) {
                return true;
            }
            $candidate = $this->folders->find((int) $candidate['parent_id']);
        }

        return false;
    }

    private function sanitize(array $data, bool $creating = true): array
    {
        $result = [];

        if ($creating || array_key_exists('name', $data)) {
            $result['name'] = sanitize_text_field((string) ($data['name'] ?? ''));
        }
        if ($creating || array_key_exists('parent_id', $data)) {
            $parentId = $data['parent_id'] ?? null;
            $result['parent_id'] = $parentId === null || $parentId === '' ? null : absint($parentId);
        }
        if (array_key_exists('color', $data)) {
            $result['color'] = $data['color'] === null || $data['color'] === '' ? null : sanitize_hex_color($data['color']);
        }
        if (array_key_exists('icon', $data)) {
            $result['icon'] = $data['icon'] === null || $data['icon'] === '' ? null : sanitize_key($data['icon']);
        }
        if (array_key_exists('sort_order', $data)) {
            $result['sort_order'] = (int) $data['sort_order'];
        }

        return $result;
    }

    private function validate(array $data, bool $creating): true|WP_Error
    {
        if ($creating && ($data['name'] ?? '') === '') {
            return new WP_Error('folderfolio_name_required', __('A folder name is required.', 'folderfolio'));
        }
        if (isset($data['name']) && mb_strlen($data['name']) > 191) {
            return new WP_Error('folderfolio_name_too_long', __('Folder names must be 191 characters or fewer.', 'folderfolio'));
        }
        if (array_key_exists('color', $data) && $data['color'] === null && !empty($data['color'])) {
            return new WP_Error('folderfolio_invalid_color', __('Choose a valid folder color.', 'folderfolio'));
        }

        return true;
    }
}
