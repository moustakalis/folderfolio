<?php

namespace FolderFolio\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FolderService business logic.
 *
 * These tests verify validation and hierarchy rules without requiring WordPress.
 */
class FolderServiceTest extends TestCase
{
    /**
     * @test
     */
    public function folder_name_is_required(): void
    {
        $this->markTestIncomplete('FolderService validation tests to be implemented');
    }

    /**
     * @test
     */
    public function folder_name_must_be_191_chars_or_less(): void
    {
        $this->markTestIncomplete('FolderService validation tests to be implemented');
    }

    /**
     * @test
     */
    public function folder_cannot_be_its_own_parent(): void
    {
        $this->markTestIncomplete('FolderService hierarchy tests to be implemented');
    }

    /**
     * @test
     */
    public function folder_cannot_be_moved_into_descendant(): void
    {
        $this->markTestIncomplete('FolderService hierarchy tests to be implemented');
    }
}
