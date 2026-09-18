<?php

declare(strict_types=1);

namespace FolderFolio\Support;

/**
 * The ten folder colours.
 *
 * A folder stores a swatch *name* — `steel`, `plum` — and never a hex. The
 * hex a swatch resolves to is a property of the admin colour scheme, not of
 * the folder: `--ff-folder-teal` is #135e96 on Fresh and #9ec8d6 on Midnight,
 * because a colour that is legible on a white panel is not legible on a dark
 * one. A stored hex can only ever be one of those two, so on the other scheme
 * it is wrong — which is exactly what this class exists to stop.
 *
 * Ten fixed swatches rather than a picker, for the same reason: eight colour
 * schemes ship with core, and a hex a user chose while looking at one of them
 * cannot be guaranteed to clear contrast on the other seven.
 *
 * The map below is the Fresh column of `assets/src/core/_tokens.css`, and
 * TokensTest asserts the two agree — this file and that stylesheet are one
 * decision written twice, so a drift between them is a test failure rather
 * than a colour that renders as nothing.
 *
 * No WordPress functions: this is pure so the unit suite covers it without a
 * bootstrap.
 */
final class Swatches
{
    /**
     * Swatch name to its Fresh hex.
     *
     * @var array<string, string>
     */
    public const HEX = [
        'slate'  => '#50575e',
        'red'    => '#d63638',
        'clay'   => '#9e3526',
        'ochre'  => '#8a6a12',
        'moss'   => '#008a20',
        'teal'   => '#135e96',
        'steel'  => '#2271b1',
        'indigo' => '#3858e9',
        'plum'   => '#6d2a58',
        'ink'    => '#1d2327',
    ];

    /**
     * The swatch names, in the order the picker shows them.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::HEX);
    }

    public static function isKey(string $value): bool
    {
        return array_key_exists($value, self::HEX);
    }

    /**
     * A caller's colour as a swatch name, or null if it is neither.
     *
     * Three inputs are accepted, and the second is why this is not simply an
     * `in_array`:
     *
     * - a swatch name, in any case;
     * - a `#rrggbb` or `#rgb` hex, snapped to the nearest swatch. 0.2.0 stored
     *   free hexes and its REST route accepted them, so anything written
     *   against that version keeps working — and the folders already in a
     *   live database are migrated through this same method;
     * - anything else, which is null and becomes a 400 rather than a silently
     *   dropped field.
     */
    public static function normalize(string $value): ?string
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return null;
        }

        if (self::isKey($value)) {
            return $value;
        }

        return self::nearest($value);
    }

    /**
     * The swatch closest to a hex colour, or null if it is not a hex.
     *
     * Distance is "redmean" — a cheap approximation of perceptual distance
     * that weights the channels by how red the pair is. Plain Euclidean RGB
     * puts #008a20 (moss) and #135e96 (teal) closer together than either is to
     * a mid green, which would file a green folder under blue. Redmean is one
     * expression and needs no colour-space conversion; the exactness that
     * matters is that each of the ten maps to itself, which the test asserts.
     */
    public static function nearest(string $hex): ?string
    {
        $rgb = self::toRgb($hex);

        if ($rgb === null) {
            return null;
        }

        $best = null;
        $bestDistance = PHP_FLOAT_MAX;

        foreach (self::HEX as $key => $candidate) {
            $other = self::toRgb($candidate);

            if ($other === null) {
                continue;
            }

            $distance = self::distance($rgb, $other);

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $key;
            }
        }

        return $best;
    }

    /**
     * @param array{int, int, int} $a
     * @param array{int, int, int} $b
     */
    private static function distance(array $a, array $b): float
    {
        $meanRed = ($a[0] + $b[0]) / 2;

        $dR = $a[0] - $b[0];
        $dG = $a[1] - $b[1];
        $dB = $a[2] - $b[2];

        return (2 + $meanRed / 256) * $dR * $dR
            + 4 * $dG * $dG
            + (2 + (255 - $meanRed) / 256) * $dB * $dB;
    }

    /**
     * @return array{int, int, int}|null
     */
    private static function toRgb(string $hex): ?array
    {
        $hex = ltrim(strtolower(trim($hex)), '#');

        if (strlen($hex) === 3 && ctype_xdigit($hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return null;
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
