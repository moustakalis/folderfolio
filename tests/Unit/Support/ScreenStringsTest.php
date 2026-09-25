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
     * the media library's "files" share a key on purpose), and key => every
     * [singular, plural] registered through `Plurals::forms(_n_noop(…))`.
     *
     * @return array{0: array<string, list<string>>, 1: array<string, list<array{0: string, 1: string}>>}
     */
    private function phpStrings(): array
    {
        $plain = [];
        $plural = [];
        $root = dirname(__DIR__, 3);

        foreach (self::files($root . '/includes', '/\.php$/') as $file) {
            $source = (string) file_get_contents($file->getPathname());

            preg_match_all("/'(\\w+)'\\s*=>\\s*(?:__|esc_html__|esc_attr__)\\(\\s*" . self::S . '/s', $source, $m, PREG_SET_ORDER);

            foreach ($m as $match) {
                $plain[$match[1]][] = self::unquote($match[2]);
            }

            preg_match_all("/'(\\w+)'\\s*=>\\s*Plurals::forms\\(\\s*_n_noop\\(\\s*" . self::S . '\\s*,\\s*' . self::S . '/s', $source, $m, PREG_SET_ORDER);

            foreach ($m as $match) {
                $plural[$match[1]][] = [self::unquote($match[2]), self::unquote($match[3])];
            }
        }

        return [$plain, $plural];
    }

    /**
     * Every `t('key', 'fallback')` as [key, fallback, where], and every
     * `tn('one', 'many', n, 'One', 'Many')` as [one, One, Many, where].
     *
     * @return array{0: list<array{0: string, 1: string, 2: string}>, 1: list<array{0: string, 1: string, 2: string, 3: string}>}
     */
    private function screenStrings(): array
    {
        $plain = [];
        $plural = [];
        $root = dirname(__DIR__, 3);

        foreach (self::files($root . '/assets/src', '/\.tsx?$/') as $file) {
            $source = (string) file_get_contents($file->getPathname());
            $where = substr($file->getPathname(), strlen($root) + 1);

            preg_match_all("/\\bt\\(\\s*'(\\w+)'\\s*,\\s*" . self::S . '/s', $source, $m, PREG_SET_ORDER);

            foreach ($m as $match) {
                $plain[] = [$match[1], self::unquote($match[2]), $where];
            }

            preg_match_all("/\\btn\\(\\s*'(\\w+)'\\s*,\\s*'(\\w+)'\\s*,[^,]+,\\s*" . self::S . '\\s*,\\s*' . self::S . '/s', $source, $m, PREG_SET_ORDER);

            foreach ($m as $match) {
                $plural[] = [$match[1], self::unquote($match[3]), self::unquote($match[4]), $where];
            }
        }

        return [$plain, $plural];
    }

    public function test_every_screen_string_is_translated_and_says_what_php_says(): void
    {
        [$php, $phpPlural] = $this->phpStrings();
        [$screen] = $this->screenStrings();

        $this->assertGreaterThan(300, count($screen), 'the pattern still finds the strings');

        $untranslated = [];
        $drifted = [];
        $readAsPlain = [];

        foreach ($screen as [$key, $fallback, $where]) {
            if (isset($phpPlural[$key])) {
                $readAsPlain[] = "{$key} ({$where})";
                continue;
            }

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
        $this->assertSame([], $readAsPlain, 't() on a plural entry renders its fallback for ever — it wants tn()');
    }

    /**
     * A counted label is one gettext plural with all of its forms, not two
     * keys chosen by `count === 1` (Support\Plurals). So every `tn()` names
     * a `Plurals::forms(_n_noop())` entry, and its fallbacks are that entry's
     * English.
     */
    public function test_every_counted_label_is_a_gettext_plural_and_says_what_php_says(): void
    {
        [$php, $phpPlural] = $this->phpStrings();
        [, $screen] = $this->screenStrings();

        $this->assertGreaterThan(35, count($screen), 'the pattern still finds the counted labels');

        $notPlural = [];
        $drifted = [];

        foreach ($screen as [$key, $one, $many, $where]) {
            if (!isset($phpPlural[$key])) {
                $notPlural[] = "{$key} ({$where})" . (isset($php[$key]) ? ' — registered with __(), one form' : '');
                continue;
            }

            if (!in_array([$one, $many], $phpPlural[$key], true)) {
                $pairs = array_map(static fn(array $p): string => "\"{$p[0]}\" / \"{$p[1]}\"", $phpPlural[$key]);
                $drifted[] = "{$key} ({$where}): \"{$one}\" / \"{$many}\" — PHP says " . implode(' or ', $pairs);
            }
        }

        $this->assertSame([], $notPlural, 'a counted label that is not one gettext plural');
        $this->assertSame([], $drifted, 'the English fallbacks say something the PHP plural does not');
    }

    /**
     * The singular is a form, not the number one: Russian's first form is
     * also 21 and 31, French's is also 0. A singular with no placeholder
     * ("In 1 folder.", "One line cannot be used") reads "1" for all of them.
     */
    public function test_every_singular_carries_its_count(): void
    {
        [, $phpPlural] = $this->phpStrings();

        $this->assertNotEmpty($phpPlural);

        $numberless = [];

        foreach ($phpPlural as $key => $pairs) {
            foreach ($pairs as [$one]) {
                if (!preg_match('/%(\d+\$)?s/', $one)) {
                    $numberless[] = "{$key}: \"{$one}\"";
                }
            }
        }

        $this->assertSame([], $numberless);
    }
}
