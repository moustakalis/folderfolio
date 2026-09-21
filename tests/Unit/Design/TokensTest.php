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
     * Components that paint on a ground of their own rather than the panel.
     *
     * There is one surface palette, so the panel is the ground almost
     * everywhere. The undo toast is the deliberate exception — it is chrome
     * rather than page, and carries five tokens of its own.
     *
     * Keyed by the block prefix a selector has to contain. The test asserts
     * every key still matches something, so this cannot rot into a lie.
     */
    private const GROUNDS = [
        '.folderfolio-toast' => '--ff-toast-bg',
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
        $geometry = [
            '--ff-row-h',
            '--ff-indent',
            '--ff-rail-w',
            '--ff-switcher',
            '--ff-rail-gap',
        ];

        // Both scopes. Most tokens live on `.folderfolio`, but a rule that
        // paints an element *outside* the component — #wpcontent, since the
        // settings screen's ground became the page — cannot see them there, so
        // those are declared on `:root`. Reading only one block would leave
        // every token in the other one guarded by nothing, which is the shape
        // of mistake the derived ink list was written to stop.
        return array_diff_key(
            array_merge(
                self::declarations($css, ':root'),
                self::declarations($css, '.folderfolio')
            ),
            array_flip($geometry)
        );
    }

    /**
     * A token belongs to one scope.
     *
     * Declaring the same colour in both `:root` and `.folderfolio` is two
     * sources of truth for one value, and the second one is the one that goes
     * stale. `colourTokens()` merges the blocks with `.folderfolio` winning,
     * so a drift would resolve silently rather than fail.
     */
    public function test_no_colour_token_is_declared_in_both_scopes(): void
    {
        $css = self::css();

        $both = array_intersect_key(
            self::declarations($css, ':root'),
            self::declarations($css, '.folderfolio')
        );

        self::assertSame(
            [],
            array_keys($both),
            'Declared in both :root and .folderfolio: ' . implode(', ', array_keys($both))
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

    /**
     * Every token painted as text, found in the stylesheets rather than listed
     * here, and checked against the ground it actually lands on.
     *
     * ## Why this is no longer a constant
     *
     * It used to be four token names kept by hand beside the stylesheets that
     * paint with them: --ff-ink, --ff-muted, --ff-dim, --ff-danger-text. On
     * 21 Sep --ff-off was found painting live text in four places at 3.24:1 on
     * the panel — the crumb separator, the folder separator, a folder row's
     * subtree count, and the one sentence that tells you an import source
     * holds nothing. None of them had ever failed a test, because no test had
     * ever been told to look at that token.
     *
     * A guard whose scope is a constant guards only that constant. So the
     * scope is derived: every rule that sets `color: var(--ff-…)` and declares
     * no background of its own paints on whatever surface it sits on, and this
     * checks all of them, in all nine schemes.
     *
     * Two exemptions, both narrow and both stated:
     *
     * - Inactive controls. WCAG 1.4.3 excludes them by name, and that is what
     *   --ff-off is for now.
     * - Components with a ground of their own — GROUNDS, above.
     *
     * Rules that declare a background as well are the pair test's, below.
     */
    public function test_every_token_painted_as_text_clears_aa_on_its_ground(): void
    {
        $inks = self::inksInUse();

        self::assertNotEmpty(
            $inks,
            'No rule was found setting a colour from a token without a background of its own, '
            . 'which means the scan is broken rather than the stylesheets being clean.'
        );

        foreach (array_keys(self::GROUNDS) as $prefix) {
            $matched = false;

            foreach ($inks as [$selector]) {
                if (str_contains($selector, $prefix)) {
                    $matched = true;

                    break;
                }
            }

            self::assertTrue(
                $matched,
                "GROUNDS names {$prefix}, which no longer matches any rule. Either the component "
                . 'was renamed or it is gone — either way this map is now describing something '
                . 'that does not exist.'
            );
        }

        $failures = [];

        foreach (array_keys(self::SCHEME_ACCENTS) as $scheme) {
            $values = self::paletteFor($scheme);

            foreach ($inks as [$selector, $token, $ground]) {
                $fg = $values[$token] ?? null;
                $bg = $values[$ground] ?? null;

                if ($fg === null || $bg === null) {
                    $failures[] = sprintf(
                        '%s: %s paints with %s on %s and one of them is not declared.',
                        $scheme,
                        $selector,
                        $token,
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
                    '%s: %s paints %s (%s) on %s (%s) at %.2f:1, below AA.',
                    $scheme,
                    $selector,
                    $token,
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
     * Every rule in the component stylesheets that paints text from a token
     * and leaves the ground to whatever it is sitting on.
     *
     * @return list<array{0: string, 1: string, 2: string}> selector, ink, ground
     */
    private static function inksInUse(): array
    {
        $dir = dirname(__DIR__, 3) . '/assets/src/core';
        $files = glob($dir . '/*.css');

        self::assertNotFalse($files, "No component stylesheets found in {$dir}.");

        $inks = [];

        foreach ($files as $file) {
            // _tokens.css declares the tokens; it does not paint with them.
            if (basename($file) === '_tokens.css') {
                continue;
            }

            $css = (string) file_get_contents($file);

            // Comments first: one of them quotes a rule it is describing.
            $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

            preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER);

            foreach ($rules as $rule) {
                $selector = trim((string) preg_replace('/\s+/', ' ', $rule[1]));
                $body = $rule[2];

                if (preg_match('/(?<![-\w])color\s*:\s*var\((--ff-[\w-]+)\)\s*;/', $body, $ink) !== 1) {
                    continue;
                }

                // A rule that names its own ground is the pair test's.
                if (
                    preg_match(
                        '/background(?:-color)?\s*:\s*var\((--ff-[\w-]+)\)\s*;/',
                        $body,
                        $ignored
                    ) === 1
                ) {
                    continue;
                }

                // WCAG 1.4.3 exempts inactive user interface components.
                if (preg_match('/:disabled|\[disabled\]|aria-disabled/', $selector) === 1) {
                    continue;
                }

                $ground = '--ff-panel';

                foreach (self::GROUNDS as $prefix => $token) {
                    if (str_contains($selector, $prefix)) {
                        $ground = $token;

                        break;
                    }
                }

                $inks[] = [basename($file) . ' ' . $selector, $ink[1], $ground];
            }
        }

        return $inks;
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
     * --ff-on-sel is the ink for --ff-sel, and for nothing else.
     *
     * The codebase has painted --ff-on-sel on --ff-bar four times — the undo
     * toast, --ff-accent-text's original sin, the settings screen's segmented
     * control and the wizard's current-step badge — and every time it was
     * 1.09:1 on Midnight, because --ff-on-sel flipped dark with the accent
     * while the bar stayed dark.
     *
     * **The contrast guard no longer catches it.** Since the theme rework
     * --ff-on-sel is #fff in every scheme, so white on #1d2327 measures
     * 15.89:1 and passes. The pairing is still wrong — --ff-bar is the admin
     * menu's chrome ground and this ink belongs to the accent — it is just no
     * longer wrong in a way a ratio can see. So it is asserted by name.
     *
     * Noticed on 21 Sep while writing a negative control for A11 that did not
     * fail.
     */
    public function test_the_accent_ink_is_never_painted_on_the_chrome_ground(): void
    {
        $offenders = [];

        foreach (self::declaredPairs() as [$selector, $ink, $ground]) {
            if ($ink === '--ff-on-sel' && $ground !== '--ff-sel') {
                $offenders[] = sprintf('%s paints --ff-on-sel on %s', $selector, $ground);
            }
        }

        self::assertSame(
            [],
            $offenders,
            "--ff-on-sel is the ink that sits on --ff-sel and nothing else:\n"
            . implode("\n", $offenders)
        );
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
