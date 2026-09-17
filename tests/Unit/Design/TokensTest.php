<?php

declare(strict_types=1);

namespace FolderFolio\Tests\Unit\Design;

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
}
