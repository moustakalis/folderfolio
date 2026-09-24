<?php

namespace FolderFolio\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * WP-CLI reads an option's description from one `: ` line. A second `: ` line
 * is read as more synopsis, so `wp folderfolio` printed "assign <ids>
 * --folder=<id> [--mode=<mode>]  `add` files it here…" and `folder delete`
 * lost its `[--yes]` behind "What happens to its" — both found on 24 Sep
 * building `wp folderfolio import`. A description continues on plain lines.
 */
class CliHelpTest extends TestCase
{
    public function test_no_option_description_runs_onto_a_second_colon_line(): void
    {
        $found = [];

        foreach (glob(dirname(__DIR__, 3) . '/includes/Cli/*.php') ?: [] as $file) {
            $lines = explode("\n", (string) file_get_contents($file));

            foreach ($lines as $i => $line) {
                if ($i > 0 && preg_match('/^\s*\* : /', $line) && preg_match('/^\s*\* : /', $lines[$i - 1])) {
                    $found[] = basename($file) . ':' . ($i + 1);
                }
            }
        }

        $this->assertSame([], $found, 'a continuation line starting ": " is read as synopsis');
    }
}
