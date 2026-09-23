<?php

declare(strict_types=1);

namespace FolderFolio\Tests\Unit\Domain;

use FolderFolio\Domain\ArchiveNames;
use PHPUnit\Framework\TestCase;

/**
 * Names inside a folder's ZIP (tier 2 item 11): safe to extract on any disk
 * first, readable second.
 */
final class ArchiveNamesTest extends TestCase
{
    public function test_nothing_can_climb_out_of_the_archive(): void
    {
        $this->assertSame('.._.._etc', ArchiveNames::clean('../../etc', 'x'));
        $this->assertSame('x', ArchiveNames::clean('..', 'x'));
        $this->assertSame('x', ArchiveNames::clean('.', 'x'));
        $this->assertSame('a_b_c', ArchiveNames::clean('a/b\\c', 'x'));
        $this->assertSame('ab', ArchiveNames::clean("a\x00\x1Fb", 'x'));
    }

    public function test_nothing_windows_refuses(): void
    {
        $this->assertSame('a_b_c_d_e_f_g_', ArchiveNames::clean('a:b*c?d"e<f>g|', 'x'));
        // A trailing dot or space: Explorer drops it and then cannot find the file.
        $this->assertSame('Logos', ArchiveNames::clean('Logos. ', 'x'));
        $this->assertSame('_CON', ArchiveNames::clean('CON', 'x'));
        $this->assertSame('_nul.txt', ArchiveNames::clean('nul.txt', 'x'));
        $this->assertSame('Console', ArchiveNames::clean('Console', 'x'));
    }

    public function test_a_greek_name_is_kept_as_itself(): void
    {
        $this->assertSame('Φωτογραφίες 2024', ArchiveNames::clean('Φωτογραφίες 2024', 'x'));
    }

    public function test_a_long_name_keeps_its_extension_and_whole_characters(): void
    {
        $name = ArchiveNames::clean(str_repeat('φ', 150) . '.jpeg', 'x');

        $this->assertStringEndsWith('.jpeg', $name);
        $this->assertLessThanOrEqual(200, strlen($name));
        $this->assertTrue(mb_check_encoding($name, 'UTF-8'), 'A cut mid-character is not a name.');
    }

    public function test_no_two_entries_collide_on_a_case_insensitive_disk(): void
    {
        $names = new ArchiveNames();

        $this->assertSame('logo.png', $names->unique('Brand/', 'logo.png'));
        $this->assertSame('Logo (2).png', $names->unique('Brand/', 'Logo.png'));
        $this->assertSame('logo (3).png', $names->unique('Brand/', 'logo.png'));
        // Another directory is another namespace.
        $this->assertSame('logo.png', $names->unique('Brand/Web/', 'logo.png'));
        // A directory's name is not split at a dot; a hidden file's dot is not an extension.
        $this->assertSame('v1.2', $names->unique('Brand/', 'v1.2', true));
        $this->assertSame('V1.2 (2)', $names->unique('Brand/', 'V1.2', true));
        $this->assertSame('.env', $names->unique('Brand/', '.env'));
        $this->assertSame('.env (2)', $names->unique('Brand/', '.env'));
    }
}
