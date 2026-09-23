<?php

declare(strict_types=1);

namespace FolderFolio\Domain;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The names inside a folder's ZIP — the pure half of tier 2 item 11.
 *
 * Every name in the archive comes from something a person typed (a folder
 * name) or uploaded (a file name), and the archive is unpacked onto somebody
 * else's disk. So a name here is made safe to *extract*, on every system that
 * will open it, before it is made to read well:
 *
 * - **Nothing can climb out.** No `/` or `\` inside a segment, no `.` or `..`
 *   as a whole segment, no control characters. A folder named `../../etc`
 *   arrives as a folder named `.._.._etc`, inside the archive's own root.
 * - **Nothing Windows refuses.** `: * ? " < > |` become `_`; a trailing dot
 *   or space is trimmed (Explorer drops it and then cannot find the file);
 *   `CON`, `NUL`, `COM1` and the rest get a `_`, because Windows will not
 *   create a file by those names in any directory.
 * - **No two entries collide on a case-insensitive disk.** macOS and Windows
 *   treat `Logo.png` and `logo.png` as one file, so the second would silently
 *   overwrite the first. The second becomes `logo (2).png`.
 *
 * Pure, so every rule is unit tested without WordPress.
 */
final class ArchiveNames
{
    /** Bytes a segment may take — well inside every file system's 255. */
    private const MAX_BYTES = 200;

    private const RESERVED = '/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\..*)?$/i';

    /** @var array<string, true> Taken names, lower-cased, per directory. */
    private array $taken = [];

    /**
     * One path segment, safe to extract anywhere.
     *
     * @param string $fallback Used when nothing of the name survives.
     */
    public static function clean(string $name, string $fallback): string
    {
        // Invalid UTF-8 would make the whole name unreadable to a reader that
        // honours the UTF-8 flag; replace the bad bytes rather than drop them.
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        }

        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = strtr($name, ['/' => '_', '\\' => '_', ':' => '_', '*' => '_', '?' => '_', '"' => '_', '<' => '_', '>' => '_', '|' => '_']);
        $name = rtrim(trim($name), '. ');

        if ('' === $name) {
            $name = $fallback;
        }

        if (1 === preg_match(self::RESERVED, $name)) {
            $name = '_' . $name;
        }

        if (strlen($name) > self::MAX_BYTES) {
            // Keep the extension: a JPEG that loses `.jpg` opens in nothing.
            $dot = strrpos($name, '.');
            $extension = false !== $dot && strlen($name) - $dot <= 10 ? substr($name, $dot) : '';
            $name = mb_strcut($name, 0, self::MAX_BYTES - strlen($extension), 'UTF-8') . $extension;
        }

        return $name;
    }

    /**
     * A name no other entry in the same directory has — `Logo (2).png` for the
     * second `logo.png`, `Web (2)` for the second `Web`.
     *
     * @param string $directory The directory's own path in the archive, `''` for the root.
     */
    public function unique(string $directory, string $name, bool $isDirectory = false): string
    {
        $candidate = $name;
        $n = 1;

        while (isset($this->taken[$this->key($directory, $candidate)])) {
            $n++;
            $candidate = self::numbered($name, $n, $isDirectory);
        }

        $this->taken[$this->key($directory, $candidate)] = true;

        return $candidate;
    }

    private static function numbered(string $name, int $n, bool $isDirectory): string
    {
        $dot = $isDirectory ? false : strrpos($name, '.');

        // A leading dot is a hidden file's whole name, not its extension.
        if (false === $dot || 0 === $dot) {
            return sprintf('%s (%d)', $name, $n);
        }

        return sprintf('%s (%d)%s', substr($name, 0, $dot), $n, substr($name, $dot));
    }

    private function key(string $directory, string $name): string
    {
        return mb_strtolower($directory . "\0" . $name, 'UTF-8');
    }
}
