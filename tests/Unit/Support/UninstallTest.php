<?php

namespace FolderFolio\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * `uninstall.php` removes everything the plugin stores — the readme says
 * "every table, option and transient", and on 24 Sep five were left behind:
 * the settings, both import options, a transient and every person's rail.
 *
 * Read from the source rather than listed here, so a key added next month
 * fails this test until uninstall.php knows about it. Every stored key in
 * this codebase is a class constant whose value starts `folderfolio_` or
 * `_folderfolio_` — option names, transients, user and post meta — and the
 * constants that name other things (hooks, nonces, REST args, meta keys
 * inside our own tables) are listed below with the reason they are not
 * stored in WordPress.
 */
class UninstallTest extends TestCase
{
    /**
     * Constants that are not WordPress storage.
     *
     * @var array<string, string>
     */
    private const NOT_STORED = [
        'folderfolio_folder' => 'the folder query var / upload parameter',
        'folderfolio_smart' => 'the smart folder query var',
        'folderfolio_smart_rules' => 'an internal query var',
        'folderfolio_startup' => 'a URL marker',
        'folderfolio_manage_folders' => 'a capability name, not stored by us',
        'folderfolio_zip' => 'an admin-post action',
        'folderfolio_save_settings' => 'an admin-post action',
        'folderfolio_run_tool' => 'an admin-post action',
        'folderfolio_order' => 'a query var',
        'folderfolio_gallery' => 'the shortcode\'s name',
    ];

    /**
     * @return array<string, string> value => where it was declared
     */
    private function storedKeys(): array
    {
        $root = dirname(__DIR__, 3);
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/includes'));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            preg_match_all("/const\\s+[A-Z_]+\\s*=\\s*'(_?folderfolio_[a-z0-9_]+)'/", $source, $matches);

            foreach ($matches[1] as $value) {
                $found[$value] = basename($file->getPathname());
            }
        }

        return array_diff_key($found, self::NOT_STORED);
    }

    public function test_every_stored_key_is_removed_on_uninstall(): void
    {
        $uninstall = (string) file_get_contents(dirname(__DIR__, 3) . '/uninstall.php');
        $keys = $this->storedKeys();

        $this->assertNotEmpty($keys);

        $missing = [];

        foreach ($keys as $key => $where) {
            if (!str_contains($uninstall, "'{$key}'")) {
                $missing[] = "{$key} ({$where})";
            }
        }

        $this->assertSame([], $missing, 'stored by the plugin, left behind by uninstall.php');
    }
}
