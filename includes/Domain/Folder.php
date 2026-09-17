<?php

declare(strict_types=1);

namespace FolderFolio\Domain;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Folder value object.
 *
 * This is what the public API hands out — the facade returns it, and every
 * action carries it. It is readonly so a hook callback cannot mutate a folder
 * by accident, and it is a class rather than an array so that adding a field
 * later does not silently change the shape other people's code reads.
 *
 * Property by property, not `readonly class`, which is PHP **8.2** — and this
 * plugin supports 8.1, where it is a parse error rather than a warning. Same
 * guarantee either way; `phpstan.neon` pins the version range so the analyser
 * catches the next one instead of the 8.1 leg of CI.
 */
final class Folder
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?int $parentId = null,
        public readonly string $path = '',
        public readonly int $depth = 0,
        public readonly string $objectType = FolderRepository::DEFAULT_OBJECT_TYPE,
        public readonly ?string $slug = null,
        public readonly ?string $color = null,
        public readonly ?string $icon = null,
        public readonly int $sortOrder = 0,
        public readonly ?int $createdBy = null,
        public readonly string $createdAt = '',
        public readonly string $updatedAt = '',
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            name: (string) $row['name'],
            parentId: isset($row['parent_id']) ? (int) $row['parent_id'] : null,
            path: (string) ($row['path'] ?? ''),
            depth: (int) ($row['depth'] ?? 0),
            objectType: (string) ($row['object_type'] ?? FolderRepository::DEFAULT_OBJECT_TYPE),
            slug: isset($row['slug']) ? (string) $row['slug'] : null,
            color: isset($row['color']) ? (string) $row['color'] : null,
            icon: isset($row['icon']) ? (string) $row['icon'] : null,
            sortOrder: (int) ($row['sort_order'] ?? 0),
            createdBy: isset($row['created_by']) ? (int) $row['created_by'] : null,
            createdAt: (string) ($row['created_at'] ?? ''),
            updatedAt: (string) ($row['updated_at'] ?? ''),
        );
    }

    /**
     * Ancestor ids, outermost first. Costs nothing — the answer is in the path.
     *
     * @return list<int>
     */
    public function ancestorIds(): array
    {
        return FolderPath::ancestorIds($this->path);
    }

    public function isRoot(): bool
    {
        return $this->parentId === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'parent_id'   => $this->parentId,
            'path'        => $this->path,
            'depth'       => $this->depth,
            'object_type' => $this->objectType,
            'slug'        => $this->slug,
            'color'       => $this->color,
            'icon'        => $this->icon,
            'sort_order'  => $this->sortOrder,
            'created_by'  => $this->createdBy,
            'created_at'  => $this->createdAt,
            'updated_at'  => $this->updatedAt,
        ];
    }
}
