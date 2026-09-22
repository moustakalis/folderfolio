<?php

declare(strict_types=1);

namespace FolderFolio\Tests\Unit\Admin;

use FolderFolio\Admin\RailPreferences;
use PHPUnit\Framework\TestCase;

/**
 * The half of RailPreferences that has to survive arbitrary input.
 *
 * The width arrives over HTTP from a drag handle, and it is written straight
 * into a CSS custom property on the server-rendered markup. An unclamped value
 * there is not a cosmetic problem: a width of 4000 pushes the media grid off
 * the screen on every subsequent page load, and the control that would fix it
 * has gone with it.
 *
 * WordPress is not loaded here — sanitize() and clampWidth() are static and
 * touch nothing.
 */
final class RailPreferencesTest extends TestCase
{
    public function test_a_sensible_width_survives_untouched(): void
    {
        self::assertSame(320, RailPreferences::clampWidth(320));
        self::assertSame(RailPreferences::MIN_WIDTH, RailPreferences::clampWidth(RailPreferences::MIN_WIDTH));
        self::assertSame(RailPreferences::MAX_WIDTH, RailPreferences::clampWidth(RailPreferences::MAX_WIDTH));
    }

    public function test_widths_outside_the_range_are_pulled_back_to_it(): void
    {
        self::assertSame(RailPreferences::MIN_WIDTH, RailPreferences::clampWidth(10));
        self::assertSame(RailPreferences::MIN_WIDTH, RailPreferences::clampWidth(-9999));
        self::assertSame(RailPreferences::MAX_WIDTH, RailPreferences::clampWidth(4000));
    }

    /**
     * The trap this method exists for.
     *
     * (int) 'wide' is 0, and 0 clamps to the minimum — so a garbage value
     * would come back as a legitimate-looking narrow rail rather than as the
     * default. Anything non-numeric has to miss the clamp entirely.
     */
    public function test_non_numeric_input_falls_back_to_the_designed_width(): void
    {
        foreach (['wide', '', null, [], true, 'NaN'] as $junk) {
            self::assertSame(
                RailPreferences::DEFAULT_WIDTH,
                RailPreferences::clampWidth($junk),
                'non-numeric input must not cast to 0 and then clamp to the minimum'
            );
        }
    }

    public function test_numeric_strings_are_accepted_because_that_is_how_they_arrive(): void
    {
        // A form-encoded request body has no integers in it.
        self::assertSame(320, RailPreferences::clampWidth('320'));
        self::assertSame(320, RailPreferences::clampWidth('320.4'));
    }

    /**
     * The other trap: (bool) '0' is true, and (bool) 'false' is true.
     */
    public function test_string_falsehoods_close_the_rail(): void
    {
        foreach (['0', 'false', 'FALSE', 'no', 'off', ''] as $falsey) {
            self::assertFalse(
                RailPreferences::sanitizeOpen($falsey),
                sprintf('"%s" arrived from a form body and must not read as open', $falsey)
            );
        }

        foreach (['1', 'true', 'yes', 'on'] as $truthy) {
            self::assertTrue(RailPreferences::sanitizeOpen($truthy));
        }

        self::assertTrue(RailPreferences::sanitizeOpen(true));
        self::assertFalse(RailPreferences::sanitizeOpen(false));
        self::assertFalse(RailPreferences::sanitizeOpen(0));
    }

    public function test_an_empty_payload_is_an_open_rail_at_the_designed_width(): void
    {
        self::assertSame(
            [
                'open' => true,
                'width' => RailPreferences::DEFAULT_WIDTH,
                // Nobody has a startup folder until they press Start here.
                'startup' => null,
            ],
            RailPreferences::sanitize([])
        );
    }

    public function test_sanitize_returns_only_the_three_keys_it_owns(): void
    {
        $clean = RailPreferences::sanitize([
            'open' => false,
            'width' => 400,
            'startup' => 12,
            'admin' => true,
            'user_id' => 1,
        ]);

        self::assertSame(['open' => false, 'width' => 400, 'startup' => 12], $clean);
    }

    /**
     * The startup folder's three-way, which is the whole of its contract.
     *
     * `Support\Settings::sanitize()` reads the site-wide half of the same
     * question and has the same table, and
     * `Admin\MediaLibraryFilter::normalizeFolderId()` reads it off the query
     * string. Three copies, because two of them have to work without
     * WordPress loaded and the third runs `wp_unslash()` — so what they share
     * is asserted rather than shared. The failure this guards is the quiet
     * one: any of them starting to read `0` as nothing, which would make
     * Unassigned unchoosable while everything else went on working.
     *
     * @dataProvider startupFolders
     *
     * @param mixed $input
     */
    public function test_the_startup_folder_keeps_zero_and_drops_nothing($input, ?int $expected): void
    {
        self::assertSame($expected, RailPreferences::startupFolder($input));
        self::assertSame($expected, RailPreferences::sanitize(['startup' => $input])['startup']);
    }

    /**
     * @return array<string, array{0: mixed, 1: ?int}>
     */
    public static function startupFolders(): array
    {
        return [
            'absent is no personal choice' => [null, null],
            'and so is an empty string' => ['', null],
            'zero is Unassigned, not nothing' => [0, 0],
            'and so is the string a form posts' => ['0', 0],
            'a folder id survives as an int' => ['12', 12],
            'a negative id is floored, never stored' => [-4, 0],
            'a word is not a folder' => ['brand', null],
            'and neither is an array' => [['12'], null],
        ];
    }

    /**
     * Clearing it is a write of null, not an omission.
     *
     * `Rest\PreferenceController::write()` merges the incoming keys onto what
     * is stored, so a key left out keeps its value — which is what lets the
     * toggle send `startup` alone without restating the rail's width. It also
     * means turning the toggle OFF has to send an explicit null, and that
     * null has to survive sanitising rather than being read as "absent, so
     * keep what you had".
     */
    public function test_an_explicit_null_clears_the_startup_folder(): void
    {
        $stored = RailPreferences::sanitize(['open' => true, 'width' => 320, 'startup' => 12]);

        self::assertSame(12, $stored['startup']);

        $cleared = RailPreferences::sanitize(array_merge($stored, ['startup' => null]));

        self::assertNull($cleared['startup']);
        // And nothing else moved with it.
        self::assertSame(320, $cleared['width']);
        self::assertTrue($cleared['open']);
    }

    /**
     * The minimum is not an arbitrary number: below it the four labelled
     * toolbar buttons stop fitting, and at the maximum the rail must still
     * leave the library usable. Both are asserted so that moving one is a
     * deliberate act with a test to update.
     */
    public function test_the_bounds_still_contain_the_designed_width(): void
    {
        self::assertGreaterThan(RailPreferences::MIN_WIDTH, RailPreferences::DEFAULT_WIDTH);
        self::assertLessThan(RailPreferences::MAX_WIDTH, RailPreferences::DEFAULT_WIDTH);
        self::assertSame(300, RailPreferences::DEFAULT_WIDTH, 'the handoff geometry is measured at 300px');
    }
}
