<?php

namespace FolderFolio\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Every string the React apps show is one PHP translates, and says the same
 * thing in both places.
 *
 * `t('key', 'English')` renders the PHP config's `'key' => __('…')` when there
 * is one and its own English when there is not. Two ways that goes wrong, both
 * silent: a key in no PHP map is English for ever whatever the site's language
 * (trap 77 — ten rail strings on 23 Sep), and a fallback that has drifted from
 * the PHP string reads one way in the source and another on the screen (the
 * import report's "Skipped — %s files" beside a PHP "%s files skipped",
 * found in the 24 Sep copy check).
 *
 * Read from the source, like UninstallTest: a string added next month is held
 * to this without anyone listing it.
 */
class ScreenStringsTest extends TestCase
{
    private const S = "'((?:[^'\\\\]|\\\\.)*)'";

    private static function unquote(string $s): string
    {
        return str_replace(["\\'", '\\\\'], ["'", '\\'], $s);
    }

    /**
     * @return iterable<\SplFileInfo>
     */
    private static function files(string $dir, string $pattern): iterable
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($it as $file) {
            if ($file->isFile() && preg_match($pattern, $file->getFilename())) {
                yield $file;
            }
        }
    }

    /**
     * key => every PHP string registered for it (a post screen's "items" and
     * the media library's "files" share a key on purpose).
     *
     * @return array<string, list<string>>
     */
    private function phpStrings(): array
    {
        $out = [];
        $root = dirname(__DIR__, 3);

        foreach (self::files($root . '/includes', '/\.php$/') as $file) {
            $source = (string) file_get_contents($file->getPathname());

            preg_match_all("/'(\\w+)'\\s*=>\\s*(?:__|esc_html__|esc_attr__)\\(\\s*" . self::S . '/s', $source, $m, PREG_SET_ORDER);

            foreach ($m as $match) {
                $out[$match[1]][] = self::unquote($match[2]);
            }
        }

        return $out;
    }

    /**
     * [key, fallback, where] for every `t('key', 'fallback')` and both halves
     * of every `tn('one', 'many', n, 'One', 'Many')`.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function screenStrings(): array
    {
        $out = [];
        $root = dirname(__DIR__, 3);

        foreach (self::files($root . '/assets/src', '/\.tsx?$/') as $file) {
            $source = (string) file_get_contents($file->getPathname());
            $where = substr($file->getPathname(), strlen($root) + 1);

            preg_match_all("/\\bt\\(\\s*'(\\w+)'\\s*,\\s*" . self::S . '/s', $source, $m, PREG_SET_ORDER);

            foreach ($m as $match) {
                $out[] = [$match[1], self::unquote($match[2]), $where];
            }

            preg_match_all("/\\btn\\(\\s*'(\\w+)'\\s*,\\s*'(\\w+)'\\s*,[^,]+,\\s*" . self::S . '\\s*,\\s*' . self::S . '/s', $source, $m, PREG_SET_ORDER);

            foreach ($m as $match) {
                $out[] = [$match[1], self::unquote($match[3]), $where];
                $out[] = [$match[2], self::unquote($match[4]), $where];
            }
        }

        return $out;
    }

    public function test_every_screen_string_is_translated_and_says_what_php_says(): void
    {
        $php = $this->phpStrings();
        $screen = $this->screenStrings();

        $this->assertGreaterThan(300, count($screen), 'the pattern still finds the strings');

        $untranslated = [];
        $drifted = [];

        foreach ($screen as [$key, $fallback, $where]) {
            if (!isset($php[$key])) {
                $untranslated[] = "{$key} ({$where})";
                continue;
            }

            if (!in_array($fallback, $php[$key], true)) {
                $drifted[] = "{$key} ({$where}): \"{$fallback}\" — PHP says \"" . implode('" or "', array_unique($php[$key])) . '"';
            }
        }

        $this->assertSame([], $untranslated, 'shown by the React apps, in no PHP i18n map');
        $this->assertSame([], $drifted, 'the English fallback says something the PHP string does not');
    }
}
