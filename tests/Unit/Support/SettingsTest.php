<?php

declare(strict_types=1);

namespace FolderFolio\Tests\Unit\Support;

use FolderFolio\Support\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Settings::sanitize() and the pieces under it.
 *
 * This is the function standing between a form post and an option row that
 * decides, among other things, who may delete a folder. Everything here is
 * the shape a browser, a filter or WP-CLI can actually send — including the
 * shapes nobody would send on purpose.
 */
final class SettingsTest extends TestCase
{
    public function test_an_empty_post_is_the_defaults(): void
    {
        $this->assertSame(Settings::defaults(), Settings::sanitize([]));
    }

    public function test_an_unknown_count_mode_falls_back_rather_than_storing_itself(): void
    {
        $this->assertSame('inherited', Settings::sanitize(['count_mode' => 'sideways'])['count_mode']);
        $this->assertSame('direct', Settings::sanitize(['count_mode' => 'direct'])['count_mode']);
    }

    public function test_an_unknown_sort_falls_back(): void
    {
        // A sort the rail's menu does not have is a menu with nothing
        // selected, which is why this cannot simply be stored as given.
        $this->assertSame('name-asc', Settings::sanitize(['default_sort' => 'size'])['default_sort']);
        $this->assertSame('oldest', Settings::sanitize(['default_sort' => 'oldest'])['default_sort']);
        // The arrangement the folders carry. Without this a dragged folder is
        // only in place until the next page load.
        $this->assertSame('custom', Settings::sanitize(['default_sort' => 'custom'])['default_sort']);
    }

    /**
     * @dataProvider undoWindows
     *
     * @param mixed $input
     */
    public function test_the_undo_window_is_clamped($input, int $expected): void
    {
        $this->assertSame($expected, Settings::clampUndo($input));
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function undoWindows(): array
    {
        return [
            'inside the range' => [10, 10],
            'a numeric string, which is what a form sends' => ['12', 12],
            'below the floor' => [1, Settings::MIN_UNDO],
            'zero is not "no undo"' => [0, Settings::MIN_UNDO],
            'negative' => [-30, Settings::MIN_UNDO],
            'above the ceiling' => [600, Settings::MAX_UNDO],
            // (int) 'soon' is 0, which would silently become the floor. The
            // default is the honest answer for input that is not a number.
            'not a number at all' => ['soon', Settings::DEFAULT_UNDO],
            'null' => [null, Settings::DEFAULT_UNDO],
            'an array' => [[5], Settings::DEFAULT_UNDO],
        ];
    }

    public function test_the_form_shape_of_the_matrix_is_read(): void
    {
        // Checkboxes: ticked boxes post '1', unticked ones post nothing.
        $roles = Settings::sanitizeRoles([
            'editor' => ['create' => '1', 'delete' => '1'],
        ]);

        $this->assertSame(['create', 'delete'], $roles['editor']);
    }

    public function test_the_list_shape_of_the_matrix_is_read(): void
    {
        // What a filter or WP-CLI would pass, rather than what the form sends.
        $roles = Settings::sanitizeRoles(['author' => ['assign', 'create']]);

        // Stored in ABILITIES order, not in the order they arrived, so two
        // equivalent matrices compare equal.
        $this->assertSame(['create', 'assign'], $roles['author']);
    }

    public function test_a_role_with_nothing_ticked_keeps_an_empty_row(): void
    {
        $roles = Settings::sanitizeRoles(['editor' => ['create' => '1'], 'author' => []]);

        $this->assertSame([], $roles['author']);
        $this->assertArrayHasKey('author', $roles, 'An untouched role must stay in the matrix, not fall back to its default.');
    }

    public function test_an_ability_nobody_has_heard_of_is_dropped(): void
    {
        $roles = Settings::sanitizeRoles(['editor' => ['create' => '1', 'publish_nukes' => '1']]);

        $this->assertSame(['create'], $roles['editor']);
    }

    public function test_a_role_slug_that_did_not_come_from_the_form_is_dropped(): void
    {
        $roles = Settings::sanitizeRoles([
            'editor' => ['create' => '1'],
            'drop table; --' => ['delete' => '1'],
            '' => ['delete' => '1'],
            7 => ['delete' => '1'],
        ]);

        $this->assertSame(['editor'], array_keys($roles));
    }

    public function test_a_matrix_that_posted_nothing_is_a_broken_request_not_a_lockout(): void
    {
        // An empty array here means no checkbox and no hidden field arrived at
        // all — a truncated POST or a form that never rendered. Storing it
        // would read as "nobody may do anything".
        $this->assertSame(Settings::defaultRoles(), Settings::sanitizeRoles([]));
        $this->assertSame(Settings::defaultRoles(), Settings::sanitizeRoles(null));
        $this->assertSame(Settings::defaultRoles(), Settings::sanitizeRoles('yes'));
    }

    public function test_the_shipped_matrix_matches_the_design(): void
    {
        // Screen 08 draws these five rows. If the defaults change, the screen
        // and this test have to change together.
        $this->assertSame(
            [
                'administrator' => ['create', 'rename', 'delete', 'assign'],
                'editor' => ['create', 'rename', 'delete', 'assign'],
                'author' => ['create', 'assign'],
                'contributor' => ['assign'],
                'subscriber' => [],
            ],
            Settings::defaultRoles()
        );
    }

    public function test_falsey_strings_do_not_tick_a_box(): void
    {
        // '0' and 'false' are truthy strings. A matrix that read them as
        // ticked would grant every ability a filter tried to revoke.
        $roles = Settings::sanitizeRoles([
            'editor' => ['create' => '0', 'rename' => 'false', 'delete' => '', 'assign' => 'off'],
        ]);

        $this->assertSame([], $roles['editor']);
    }
}
