<?php

declare(strict_types=1);

namespace FolderFolio\Tests\Unit\Design;

use FolderFolio\Support\Swatches;
use PHPUnit\Framework\TestCase;

/**
 * Guards the colour tokens.
 *
 * ## What changed on 20 Sep
 *
 * _tokens.css used to declare a base palette for Fresh and override it per
 * scheme — a full dark surface set for Midnight and nothing at all for six of
 * the eight schemes. Most of this file existed to police that: that Midnight
 * overrode every colour it did not deliberately share, and that the media
 * modal restated Fresh exactly because it had to undo Midnight.
 *
 * wp-admin's schemes turned out not to work that way. All eight set
 * `body { background: #f0f0f0 }` and none of them touches a content surface —
 * they are menu-and-accent themes, not dark modes. So there is **one surface
 * palette** now, and the accent comes from **core's own**
 * `--wp-admin-theme-color`, published per scheme on `body`.
 *
 * That deleted three tests. What replaces them is the check the old shape
 * could not express: the accent is not ours to declare, and whatever core
 * hands us has to stay legible with the ink we put on it — in all eight
 * schemes, not the two we used to carry blocks for.
 *
 * It parses the stylesheet rather than rendering it, so it stays in the
 * WordPress-free unit suite and runs in milliseconds.
 */
final class TokensTest extends TestCase
{
    /**
     * Core's own accent per scheme, read from `--wp-admin-theme-color` on
     * `body` in a live admin page, 20 Sep 2026.
     *
     * Fresh ships no colours stylesheet, so it takes the value core falls back
     * to on `:root` — and core's Fresh `.button-primary` paints exactly that.
     * Our old `--ff-sel: #2271b1` was not it: the selection colour had been
     * quietly disagreeing with the button beside it.
     *
     * [theme colour, darker-20]
     */
    private const SCHEME_ACCENTS = [
        'Fresh' => ['#007cba', '#005a87'],
        'Blue' => ['#437aa8', '#346084'],
        'Coffee' => ['#916745', '#6e4e35'],
        'Ectoplasm' => ['#646c3e', '#464c2b'],
        'Light' => ['#007cba', '#005a87'],
        'Midnight' => ['#cf4339', '#ab322a'],
        'Modern' => ['#3858e9', '#183ad6'],
        'Ocean' => ['#567958', '#415b42'],
        'Sunrise' => ['#ad631e', '#824a16'],
    ];

    /**
     * Tokens used as text, which must clear WCAG AA against the panel.
     */
    private const INK = [
        '--ff-ink',
        '--ff-muted',
        '--ff-dim',
        '--ff-danger-text',
    ];

    /** Non-text contrast: an icon or a rule, not a word. */
    private const AA_LARGE = 3.0;

    private const AA = 4.5;

    private static function css(): string
    {
        $path = dirname(__DIR__, 3) . '/assets/src/core/_tokens.css';

        self::assertFileExists($path, '_tokens.css is the source of every colour in the plugin.');

        return (string) file_get_contents($path);
    }

    /**
     * @return array<string, string>
     */
    private static function declarations(string $css, string $selector): array
    {
        $found = [];

        // Anchored to the start of a line, so `.folderfolio` does not also
        // match the tail of a descendant selector.
        $pattern = '/^' . preg_quote($selector, '/') . '\s*\{(.*?)\}/ms';

        preg_match_all($pattern, $css, $blocks);

        foreach ($blocks[1] as $body) {
            preg_match_all('/(--[\w-]+)\s*:\s*([^;]+);/', $body, $pairs, PREG_SET_ORDER);

            foreach ($pairs as $pair) {
                $found[$pair[1]] = trim($pair[2]);
            }
        }

        return $found;
    }

    /**
     * @return array<string, string>
     */
    private static function colourTokens(string $css): array
    {
        $geometry = ['--ff-row-h', '--ff-indent', '--ff-rail-w', '--ff-switcher'];

        return array_diff_key(
            self::declarations($css, '.folderfolio'),
            array_flip($geometry)
        );
    }

    /**
     * The base palette with one scheme's accent resolved into it.
     *
     * @return array<string, string>
     */
    private static function paletteFor(string $scheme): array
    {
        [$theme, $darker20] = self::SCHEME_ACCENTS[$scheme];

        return array_merge(
            self::colourTokens(self::css()),
            [
                '--ff-sel' => $theme,
                '--ff-sel-hover' => $darker20,
                '--ff-accent-text' => $darker20,
            ]
        );
    }

    /**
     * The accent is core's, and there is no scheme block of ours left.
     *
     * Four declarations cover eight schemes, and they cannot drift from what
     * the admin around us is doing — which is the whole point. A hex here
     * would be a seventh scheme's worth of wrong.
     */
    public function test_the_accent_comes_from_core_and_not_from_a_block_of_our_own(): void
    {
        $css = self::css();
        $base = self::colourTokens($css);

        $expected = [
            '--ff-sel' => '--wp-admin-theme-color',
            '--ff-sel-hover' => '--wp-admin-theme-color-darker-10',
            '--ff-accent-text' => '--wp-admin-theme-color-darker-20',
        ];

        foreach ($expected as $token => $coreVariable) {
            self::assertArrayHasKey($token, $base, "{$token} is not declared.");
            self::assertStringContainsString(
                "var({$coreVariable}",
                $base[$token],
                "{$token} has to read core's {$coreVariable}, not declare a colour of its own — "
                . "otherwise it is right in one scheme and wrong in the other seven."
            );
        }

        self::assertStringNotContainsString(
            '.admin-color-',
            $css,
            'A per-scheme block is back. The surfaces are one palette now and the accent comes '
            . "from core, so there is nothing a scheme block can say that is not a mistake."
        );

        self::assertStringNotContainsString(
            '.media-modal',
            $css,
            'The media-modal block existed only to undo Midnight\'s dark surfaces. There are '
            . 'none to undo.'
        );
    }

    /**
     * Whatever core's accent is, our ink has to survive it.
     *
     * --ff-on-sel is the ink that sits *on* --ff-sel and nothing else. This is
     * the assertion the old per-scheme shape could not make, because it only
     * knew two of the eight schemes.
     */
    public function test_the_ink_on_the_accent_clears_aa_in_every_scheme(): void
    {
        $onSel = self::colourTokens(self::css())['--ff-on-sel'] ?? null;

        self::assertNotNull($onSel, '--ff-on-sel is not declared.');

        foreach (self::SCHEME_ACCENTS as $scheme => [$theme, $_]) {
            $ratio = self::contrast($onSel, $theme);

            self::assertGreaterThanOrEqual(
                self::AA,
                $ratio,
                sprintf(
                    '%s: --ff-on-sel (%s) on core\'s accent (%s) is %.2f:1.',
                    $scheme,
                    $onSel,
                    $theme,
                    $ratio
                )
            );
        }
    }

    /**
     * And the accent used as body-size text has to clear the panel.
     *
     * That is what --ff-accent-text is for, and why it reads core's
     * `-darker-20` rather than the theme colour itself: the theme colour is
     * 4.57:1 at worst as text, which passes, but only just — the darker step
     * is 6.52:1 at worst and is what core uses for its own hover state.
     */
    public function test_the_accent_as_text_clears_aa_on_the_panel_in_every_scheme(): void
    {
        $panel = self::colourTokens(self::css())['--ff-panel'] ?? null;

        self::assertNotNull($panel, '--ff-panel is not declared.');

        foreach (self::SCHEME_ACCENTS as $scheme => [$_, $darker20]) {
            $ratio = self::contrast($darker20, $panel);

            self::assertGreaterThanOrEqual(
                self::AA,
                $ratio,
                sprintf(
                    '%s: the accent as text (%s) on the panel (%s) is %.2f:1.',
                    $scheme,
                    $darker20,
                    $panel,
                    $ratio
                )
            );
        }
    }

    public function test_text_tokens_clear_wcag_aa_on_the_panel(): void
    {
        $tokens = self::colourTokens(self::css());
        $panel = (string) $tokens['--ff-panel'];

        foreach (self::INK as $token) {
            $value = $tokens[$token] ?? null;

            self::assertNotNull($value, "{$token} is not defined.");

            if (!str_starts_with($value, '#')) {
                continue;
            }

            $ratio = self::contrast($value, $panel);

            self::assertGreaterThanOrEqual(
                self::AA,
                $ratio,
                sprintf('%s (%s) on the panel (%s) is %.2f:1, below AA.', $token, $value, $panel, $ratio)
            );
        }
    }

    /**
     * Every rule that paints text on a fill, checked as a pair.
     *
     * Four times a component has painted one token on another that is not its
     * pair, and got away with it because the two happen to agree on Fresh.
     * --ff-accent-text was the first. The undo toast was the second, which is
     * why it has five tokens of its own. The settings screen's segmented
     * control was the third and the import wizard's current-step badge the
     * fourth: both set `background: var(--ff-bar)` with
     * `color: var(--ff-on-sel)` — the admin menu's chrome ground under the ink
     * that belongs on --ff-sel — and both were 1.09:1 on Midnight.
     *
     * The other tests here check tokens one at a time against the panel, which
     * cannot see a wrong pairing: both halves are individually fine. This one
     * reads the pairs the stylesheets actually declare, in all eight schemes.
     */
    public function test_every_declared_foreground_on_background_pair_clears_aa(): void
    {
        $pairs = self::declaredPairs();

        self::assertNotEmpty(
            $pairs,
            'No rule was found setting both a colour and a background from tokens, which means '
            . 'the scan is broken rather than the stylesheets being clean.'
        );

        $failures = [];

        foreach (array_keys(self::SCHEME_ACCENTS) as $scheme) {
            $values = self::paletteFor($scheme);

            foreach ($pairs as [$selector, $ink, $ground]) {
                $fg = $values[$ink] ?? null;
                $bg = $values[$ground] ?? null;

                if ($fg === null || $bg === null) {
                    $failures[] = sprintf(
                        '%s: %s paints with %s on %s and one of them is not declared.',
                        $scheme,
                        $selector,
                        $ink,
                        $ground
                    );

                    continue;
                }

                if (!str_starts_with($fg, '#') || !str_starts_with($bg, '#')) {
                    continue;
                }

                $ratio = self::contrast($fg, $bg);

                if ($ratio >= self::AA) {
                    continue;
                }

                $failures[] = sprintf(
                    '%s: %s paints %s (%s) on %s (%s) at %.2f:1.',
                    $scheme,
                    $selector,
                    $ink,
                    $fg,
                    $ground,
                    $bg,
                    $ratio
                );
            }
        }

        self::assertSame([], $failures, implode("\n", $failures));
    }

    /**
     * Every rule in the component stylesheets that sets both a colour and a
     * background from a token.
     *
     * @return list<array{0: string, 1: string, 2: string}> selector, ink, ground
     */
    private static function declaredPairs(): array
    {
        $dir = dirname(__DIR__, 3) . '/assets/src/core';
        $files = glob($dir . '/*.css');

        self::assertNotFalse($files, "No component stylesheets found in {$dir}.");

        $pairs = [];

        foreach ($files as $file) {
            // _tokens.css declares the tokens; it does not paint with them.
            if (basename($file) === '_tokens.css') {
                continue;
            }

            $css = (string) file_get_contents($file);

            // Comments first: one of them quotes a rule it is describing, and
            // a scan that reads comments finds pairs nobody declared.
            $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

            preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER);

            foreach ($rules as $rule) {
                $selector = trim((string) preg_replace('/\s+/', ' ', $rule[1]));
                $body = $rule[2];

                if (
                    preg_match('/(?<![-\w])color\s*:\s*var\((--ff-[\w-]+)\)\s*;/', $body, $ink) !== 1
                    || preg_match(
                        '/background(?:-color)?\s*:\s*var\((--ff-[\w-]+)\)\s*;/',
                        $body,
                        $ground
                    ) !== 1
                ) {
                    continue;
                }

                $pairs[] = [basename($file) . ' ' . $selector, $ink[1], $ground[1]];
            }
        }

        return $pairs;
    }

    /**
     * The ten folder colours paint a 16px icon, not a word, so the bar is the
     * non-text one. They sit on the panel in every scheme now — there is only
     * one panel.
     */
    public function test_the_folder_swatches_read_against_the_panel(): void
    {
        $tokens = self::colourTokens(self::css());
        $panel = (string) $tokens['--ff-panel'];

        $swatches = array_filter(
            $tokens,
            static fn (string $t): bool => str_starts_with($t, '--ff-folder-'),
            ARRAY_FILTER_USE_KEY
        );

        self::assertCount(10, $swatches, 'Ten fixed swatches, no free picker.');

        foreach ($swatches as $token => $hex) {
            $ratio = self::contrast($hex, $panel);

            self::assertGreaterThanOrEqual(
                self::AA_LARGE,
                $ratio,
                sprintf('%s (%s) on the panel is %.2f:1, below the 3:1 non-text floor.', $token, $hex, $ratio)
            );
        }
    }

    private static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return ($la > $lb)
            ? ($la + 0.05) / ($lb + 0.05)
            : ($lb + 0.05) / ($la + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $channel = static function (float $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel((float) hexdec(substr($hex, 0, 2)))
            + 0.7152 * $channel((float) hexdec(substr($hex, 2, 2)))
            + 0.0722 * $channel((float) hexdec(substr($hex, 4, 2)));
    }

    /**
     * The ten folder colours are one decision written in three places.
     *
     * `Support\Swatches::HEX` is what the server validates against and what
     * the import wizard files a colour as; the `--ff-folder-*` block in
     * _tokens.css is what actually paints; `lib/swatches.ts` is what the
     * picker draws and what the row interpolates into its style attribute. A
     * name present in one and missing from another does not fail: the row
     * sets `--ff-folder: var(--ff-folder-mauve)`, the property resolves to
     * nothing, and the icon renders in the default colour as though the
     * folder had never been given one.
     */
    public function test_the_ten_swatches_agree_across_php_css_and_typescript(): void
    {
        $css = self::css();
        $base = self::declarations($css, '.folderfolio');

        foreach (Swatches::HEX as $key => $hex) {
            $token = "--ff-folder-{$key}";

            self::assertArrayHasKey($token, $base, "{$token} is not declared in _tokens.css.");
            self::assertSame(
                $hex,
                $base[$token],
                "Swatches::HEX['{$key}'] and {$token} disagree; the PHP copy is the one "
                . "imports and the nearest-swatch migration read."
            );
        }

        // And nothing in the stylesheet that PHP has never heard of.
        $declared = array_filter(
            array_keys($base),
            static fn (string $token): bool => str_starts_with($token, '--ff-folder-')
        );

        sort($declared);
        $expected = array_map(static fn (string $key): string => "--ff-folder-{$key}", Swatches::keys());
        sort($expected);

        self::assertSame($expected, $declared, '_tokens.css declares a swatch PHP does not have.');

        // The TypeScript list, read as text: the picker and the row are the
        // only things that consume it, and neither is in this suite.
        $ts = (string) file_get_contents(dirname(__DIR__, 3) . '/assets/src/lib/swatches.ts');

        preg_match('/export const SWATCHES = \[(.*?)\]/s', $ts, $match);
        self::assertNotEmpty($match, 'SWATCHES is not declared in lib/swatches.ts.');

        preg_match_all("/'([a-z]+)'/", $match[1], $names);

        self::assertSame(
            Swatches::keys(),
            $names[1],
            'lib/swatches.ts and Swatches::keys() disagree, including in order — the picker '
            . 'draws them in the order that array gives.'
        );
    }
}
