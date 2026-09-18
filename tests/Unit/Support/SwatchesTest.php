<?php

declare(strict_types=1);

namespace FolderFolio\Tests\Unit\Support;

use FolderFolio\Support\Swatches;
use PHPUnit\Framework\TestCase;

/**
 * The ten folder colours, and the one-way door in front of them.
 *
 * `normalize()` is what stands between a caller and the `color` column. It has
 * to accept three things and be strict about the fourth: a swatch name, a hex
 * from 0.2.0's API, the same hex written any of the ways a hex can be written,
 * and nothing else. The interesting half is `nearest()` — a mapping that is
 * only ever wrong quietly, because a green folder filed under 'teal' still
 * renders a colour and nobody reports it as a bug.
 */
final class SwatchesTest extends TestCase
{
    public function test_every_swatch_maps_to_itself(): void
    {
        foreach (Swatches::HEX as $key => $hex) {
            self::assertSame(
                $key,
                Swatches::nearest($hex),
                "{$hex} is {$key}'s own hex and must come back as {$key}."
            );
        }
    }

    public function test_a_swatch_name_passes_through(): void
    {
        self::assertSame('steel', Swatches::normalize('steel'));
        self::assertSame('plum', Swatches::normalize('PLUM'));
        self::assertSame('ink', Swatches::normalize('  ink  '));
    }

    /**
     * @dataProvider nearbyColours
     */
    public function test_a_hex_snaps_to_the_swatch_a_person_would_name(
        string $hex,
        string $expected
    ): void {
        self::assertSame($expected, Swatches::normalize($hex), "{$hex} should read as {$expected}.");
    }

    /**
     * Hues rather than near-misses: the point is that the *family* survives,
     * which is what a user notices. A red folder that comes back clay is a
     * shrug; a red folder that comes back teal is a bug report.
     *
     * @return array<string, array{string, string}>
     */
    public static function nearbyColours(): array
    {
        return [
            'pure red' => ['#ff0000', 'red'],
            'pure green' => ['#00ff00', 'moss'],
            'pure blue' => ['#0000ff', 'indigo'],
            'black' => ['#000000', 'ink'],
            'mid grey' => ['#808080', 'slate'],
            'core danger red' => ['#d63638', 'red'],
            // Two of the ten are blues a few points apart — teal #135e96
            // and steel #2271b1 — so a blue between them can only land on
            // one, and which one is not interesting. What is asserted is
            // that a dark blue reads dark and a mid blue reads mid, because
            // that is the difference a person sees in a 16px icon.
            'core link blue' => ['#0073aa', 'steel'],
            'a darker azure' => ['#0a4a78', 'teal'],
            'a burnt orange' => ['#b04a20', 'clay'],
            'a mustard' => ['#c9a227', 'ochre'],
            'an aubergine' => ['#7b2d6b', 'plum'],
        ];
    }

    public function test_short_hex_and_missing_hash_are_read(): void
    {
        self::assertSame('red', Swatches::normalize('#f00'));
        self::assertSame('red', Swatches::normalize('ff0000'));
        self::assertSame('red', Swatches::normalize('#FF0000'));
    }

    /**
     * @dataProvider rejections
     */
    public function test_anything_else_is_null(string $value): void
    {
        self::assertNull(Swatches::normalize($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejections(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'a colour word that is not a swatch' => ['turquoise'],
            'a CSS colour function' => ['rgb(255, 0, 0)'],
            'a token reference' => ['var(--ff-folder-red)'],
            'a hex of the wrong length' => ['#ff00'],
            'not hex digits' => ['#gggggg'],
            // The one that matters: an injected declaration must not survive
            // into a style attribute, and it does not reach one because it is
            // not a swatch name.
            'a closing brace' => ['red; background: url(x)'],
        ];
    }

    public function test_keys_are_in_the_order_the_picker_shows_them(): void
    {
        self::assertSame(
            ['slate', 'red', 'clay', 'ochre', 'moss', 'teal', 'steel', 'indigo', 'plum', 'ink'],
            Swatches::keys()
        );
    }

    public function test_is_key_is_exact(): void
    {
        self::assertTrue(Swatches::isKey('moss'));
        self::assertFalse(Swatches::isKey('MOSS'), 'isKey() answers about a stored value, which is already normalized.');
        self::assertFalse(Swatches::isKey('#008a20'));
        self::assertFalse(Swatches::isKey(''));
    }
}
