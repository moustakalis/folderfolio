<?php

declare(strict_types=1);

namespace FolderFolio\Tests\Unit\Design;

use FolderFolio\Support\Swatches;
use PHPUnit\Framework\TestCase;

/**
 * Guards the colour tokens against one specific, repeatable mistake.
 *
 * _tokens.css declares a base block for Fresh and overrides it per scheme.
 * Modern differs from Fresh only in accent, so inheriting the rest is correct.
 * Midnight inverts the lightness, so *every* colour that is not deliberately
 * shared has to be redeclared there — and when one is missed it does not fail
 * loudly. It renders dark ink on a dark panel and simply looks wrong to
 * whoever happens to switch schemes.
 *
 * That is not hypothetical: --ff-accent-text, --ff-danger-text and --ff-danger
 * were all inherited into Midnight at 2.5:1, 2.3:1 and 3.1:1 against the
 * panel. The first was caught by eye while rendering the folder row; the other
 * two were only found by looking for more of the same. This test is what
 * finding them by eye is meant to be replaced by.
 *
 * It parses the stylesheet rather than rendering it, so it stays in the
 * WordPress-free unit suite and runs in milliseconds.
 */
final class TokensTest extends TestCase
{
    /**
     * Tokens Midnight deliberately shares with Fresh.
     *
     * --ff-off and --ff-field-line are #8c8f94 in every scheme by design —
     * the handoff's colour table lists them identically across all three —
     * and both clear 4.5:1 on Midnight's panel.
     */
    private const SHARED = [
        '--ff-off',
        '--ff-field-line',

        /*
         * The undo toast is a dark sheet on every scheme — chrome, not page —
         * so its five tokens are the same everywhere by design. They exist at
         * all because borrowing the page's tokens for that context produced
         * dark-on-dark on Midnight.
         */
        '--ff-toast-bg',
        '--ff-toast-ink',
        '--ff-toast-edge',
        '--ff-toast-accent',
        '--ff-toast-bar',
    ];

    /**
     * Tokens used as text, which must clear WCAG AA against the panel they
     * sit on.
     */
    private const INK = [
        '--ff-ink',
        '--ff-muted',
        '--ff-dim',
        '--ff-accent-text',
        '--ff-danger-text',
    ];

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

        // Anchored to the start of a line. Unanchored, `.folderfolio` also
        // matches the tail of `.admin-color-midnight .folderfolio`, so the
        // base lookup would silently return Midnight's values and the Fresh
        // contrast check would be measuring the wrong panel. That is exactly
        // what this file first did.
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

    public function test_midnight_overrides_every_colour_it_does_not_deliberately_share(): void
    {
        $css = self::css();
        $midnight = self::declarations($css, '.admin-color-midnight .folderfolio');

        $missing = [];

        foreach (array_keys(self::colourTokens($css)) as $token) {
            // Folder colours have their own Midnight block, so they are
            // covered by the same lookup.
            if (in_array($token, self::SHARED, true) || isset($midnight[$token])) {
                continue;
            }

            $missing[] = $token;
        }

        self::assertSame(
            [],
            $missing,
            "Midnight inverts the lightness, so these inherit a colour meant for a white "
            . "background and will render dark on dark: " . implode(', ', $missing)
        );
    }

    public function test_text_tokens_clear_wcag_aa_on_every_scheme(): void
    {
        $css = self::css();
        $base = self::colourTokens($css);
        $midnight = self::declarations($css, '.admin-color-midnight .folderfolio');

        $schemes = [
            'Fresh' => [$base, (string) $base['--ff-panel']],
            'Midnight' => [$midnight + $base, (string) $midnight['--ff-panel']],
        ];

        foreach ($schemes as $name => [$tokens, $panel]) {
            foreach (self::INK as $token) {
                $value = $tokens[$token] ?? null;

                self::assertNotNull($value, "{$token} is not defined for {$name}.");

                if (!str_starts_with($value, '#')) {
                    continue;
                }

                $ratio = self::contrast($value, $panel);

                self::assertGreaterThanOrEqual(
                    self::AA,
                    $ratio,
                    sprintf(
                        '%s: %s (%s) on the panel (%s) is %.2f:1, below AA.',
                        $name,
                        $token,
                        $value,
                        $panel,
                        $ratio
                    )
                );
            }
        }
    }

    /**
     * Finding 11 of the settings audit, generalised.
     *
     * Three times now a component has painted text on a fill using two tokens
     * that are not a pair, and got away with it because they happen to agree
     * on Fresh. --ff-accent-text was the first. The undo toast was the second,
     * which is why it has five tokens of its own. The third was the settings
     * screen's segmented control, which set `background: var(--ff-bar)` with
     * `color: var(--ff-on-sel)` — the admin menu's chrome ground under the ink
     * that belongs on --ff-sel. Both are near-black on Fresh. On Midnight
     * --ff-on-sel correctly flips dark, because --ff-sel became a light blue,
     * while --ff-bar stayed dark: #1d2327 on #26292c, 1.09:1, with the
     * *unchecked* label at 14.97. The selected option was the invisible one.
     *
     * The other two tests in this file check tokens one at a time against the
     * panel. That cannot see a wrong pairing, because both halves of this one
     * are individually fine. So this reads the component stylesheets instead
     * and checks the pairs they actually declare together.
     *
     * It only looks at rules that set both properties from tokens, which is
     * the house rule anyway — a hex literal in component CSS is already a
     * failure elsewhere in this class.
     */
    public function test_every_declared_foreground_on_background_pair_clears_aa(): void
    {
        $tokens = self::css();
        $base = self::colourTokens($tokens);
        $midnight = self::declarations($tokens, '.admin-color-midnight .folderfolio') + $base;

        $pairs = self::declaredPairs();

        self::assertNotEmpty(
            $pairs,
            'No rule was found setting both a colour and a background from tokens, which means '
            . 'the scan is broken rather than the stylesheets being clean.'
        );

        $failures = [];

        foreach (['Fresh' => $base, 'Midnight' => $midnight] as $scheme => $values) {
            foreach ($pairs as [$selector, $ink, $ground]) {
                $fg = $values[$ink] ?? null;
                $bg = $values[$ground] ?? null;

                if ($fg === null || $bg === null) {
                    // The v0.2.0 block in admin.css, which declares a parallel
                    // palette of its own. It is pinned below rather than
                    // checked here: its hexes are fixed, so a contrast figure
                    // against a scheme panel would be meaningless.
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
     * The v0.2.0 palette in admin.css, pinned so it cannot grow.
     *
     * admin.css still carries a `:root` block from before the rail was
     * rebuilt, declaring --ff-brand-*, --ff-ink-900, --ff-ink-700,
     * --ff-surface-* and friends as fixed hexes. Its own comment says what is
     * wrong with it — "it hard-codes hexes that are wrong in seven of core's
     * eight admin colour schemes" — and sets the condition for its removal:
     * "it survives only until the rail is rebuilt on the designed components".
     * The rail has been rebuilt. Nothing outside admin.css uses those tokens,
     * and no PHP, TSX or block markup uses the classes they style.
     *
     * So it is dead, and it is on the theme rework's list. Until then this
     * test holds its surface exactly where it is: the pair check above skips
     * rules whose tokens _tokens.css does not declare, and this makes sure
     * that skip can never quietly cover a *new* rule.
     */
    public function test_the_v0_2_0_palette_does_not_grow(): void
    {
        $known = [
            'admin.css .folderfolio-folder-count',
        ];

        $declared = array_keys(self::declarations(self::css(), '.folderfolio'));
        $midnight = array_keys(
            self::declarations(self::css(), '.admin-color-midnight .folderfolio')
        );
        $defined = array_flip(array_merge($declared, $midnight));

        $strays = [];

        foreach (self::declaredPairs() as [$selector, $ink, $ground]) {
            if (isset($defined[$ink]) && isset($defined[$ground])) {
                continue;
            }

            $strays[] = $selector;
        }

        sort($strays);
        sort($known);

        self::assertSame(
            $known,
            $strays,
            "A rule is painting with a token _tokens.css does not declare. Every colour in a "
            . "component stylesheet has to resolve through the scheme blocks, or it is wrong in "
            . "seven of core's eight admin colour schemes."
        );
    }

    /**
     * The media modal is a white sheet in every admin colour scheme, so the
     * folder column inside it uses the light palette whatever the scheme says.
     * That is a copy of the Fresh block, and a copy drifts: add a token to one
     * and forget the other and the modal renders a Midnight colour on white,
     * which is the failure this file exists to catch, one context along.
     *
     * Geometry is excluded because it is inherited, not restated — the modal
     * changes its own row height in _row.css, not here. So are the SHARED
     * tokens: they are the same in every scheme, so there is nothing for the
     * modal to undo, and the undo toast they describe is portaled to the body,
     * outside the modal entirely.
     */
    public function test_the_media_modal_restates_fresh_exactly(): void
    {
        $css = self::css();
        $base = array_diff_key(self::colourTokens($css), array_flip(self::SHARED));
        $modal = self::declarations($css, '.media-modal .folderfolio');

        self::assertNotSame([], $modal, 'The media modal has no palette of its own.');

        $missing = array_diff_key($base, $modal);

        self::assertSame(
            [],
            array_keys($missing),
            'The media modal inherits these from whichever scheme is active, and on '
            . 'Midnight that is a dark value on core\'s white dialog: '
            . implode(', ', array_keys($missing))
        );

        $extra = array_diff_key($modal, $base);

        self::assertSame(
            [],
            array_keys($extra),
            'These exist only in the media modal block, so nothing defines them '
            . 'anywhere else: ' . implode(', ', array_keys($extra))
        );

        foreach ($base as $token => $value) {
            self::assertSame(
                $value,
                $modal[$token],
                "{$token} differs between Fresh and the media modal; the modal is Fresh."
            );
        }
    }

    public function test_the_folder_swatches_exist_in_both_light_and_dark(): void
    {
        $css = self::css();
        $base = self::colourTokens($css);
        $midnight = self::declarations($css, '.admin-color-midnight .folderfolio');

        $swatches = array_filter(
            array_keys($base),
            static fn (string $t): bool => str_starts_with($t, '--ff-folder-')
        );

        self::assertCount(10, $swatches, 'Ten fixed swatches, no free picker.');

        foreach ($swatches as $swatch) {
            self::assertArrayHasKey(
                $swatch,
                $midnight,
                "{$swatch} has no dark-row pair; a folder colour has to stay legible in Midnight."
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
     *
     * Midnight is in here too, because a swatch declared only in the base
     * block is the original bug this file was written for, one context along.
     */
    public function test_the_ten_swatches_agree_across_php_css_and_typescript(): void
    {
        $css = self::css();
        $base = self::declarations($css, '.folderfolio');
        $midnight = self::declarations($css, '.admin-color-midnight .folderfolio');

        foreach (Swatches::HEX as $key => $hex) {
            $token = "--ff-folder-{$key}";

            self::assertArrayHasKey($token, $base, "{$token} is not declared in _tokens.css.");
            self::assertSame(
                $hex,
                $base[$token],
                "Swatches::HEX['{$key}'] and {$token} disagree; the PHP copy is the one "
                . "imports and the nearest-swatch migration read."
            );
            self::assertArrayHasKey(
                $token,
                $midnight,
                "{$token} has no Midnight pair, so a {$key} folder renders a colour chosen "
                . "for a white panel on a dark one."
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
