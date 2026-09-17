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
            ['open' => true, 'width' => RailPreferences::DEFAULT_WIDTH],
            RailPreferences::sanitize([])
        );
    }

    public function test_sanitize_returns_only_the_two_keys_it_owns(): void
    {
        $clean = RailPreferences::sanitize([
            'open' => false,
            'width' => 400,
            'admin' => true,
            'user_id' => 1,
        ]);

        self::assertSame(['open' => false, 'width' => 400], $clean);
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
