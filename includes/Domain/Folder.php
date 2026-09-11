<?php

declare(strict_types=1);

namespace FolderFolio\Domain;

/**
 * Folder domain entity.
 */
final class Folder
{
    /**
     * @var int
     */
    private int $id;

    /**
     * @var string
     */
    private string $name;

    /**
     * @var int|null
     */
    private ?int $parent_id;

    /**
     * @param int $id
     * @param string $name
     * @param int|null $parent_id
     */
    public function __construct(int $id, string $name, ?int $parent_id = null)
    {
        $this->id = $id;
        $this->name = $name;
        $this->parent_id = $parent_id;
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return int|null
     */
    public function getParentId(): ?int
    {
        return $this->parent_id;
    }
}
