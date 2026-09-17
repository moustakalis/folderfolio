<?php

namespace FolderFolio\Tests\Integration\Support;

use FolderFolio\Support\Capabilities;
use FolderFolio\Support\Settings;
use WP_UnitTestCase;

/**
 * The roles matrix, against real WordPress roles.
 *
 * This is the one part of the settings screen with a security surface: the
 * table decides who may delete a folder somebody else built. It needs real
 * roles rather than a stub, because half the answer comes from WordPress's
 * own capabilities — `upload_files` is the floor, and an unknown role falls
 * back to `edit_others_posts`.
 *
 * Administrators are the uninteresting row and the only one a browser session
 * can show you, since every check is made as one. Everything below is a role
 * that can actually be denied.
 */
class CapabilitiesTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        delete_option(Settings::OPTION);
    }

    public function tearDown(): void
    {
        delete_option(Settings::OPTION);

        parent::tearDown();
    }

    private function asRole(string $role): void
    {
        wp_set_current_user($this->factory->user->create(['role' => $role]));
    }

    /**
     * @param array<string, list<string>> $roles
     */
    private function matrix(array $roles): void
    {
        update_option(Settings::OPTION, Settings::sanitize([
            'roles' => $roles + Settings::defaultRoles(),
        ]));
    }

    /**
     * @test
     */
    public function a_role_without_upload_files_is_denied_whatever_the_matrix_says(): void
    {
        // Contributors ship without upload_files, and the default matrix
        // gives them `assign`. The floor wins: a table of tick boxes must not
        // be able to hand the media library to somebody WordPress keeps out
        // of it.
        $this->matrix(['contributor' => ['create', 'rename', 'delete', 'assign']]);
        $this->asRole('contributor');

        foreach (Settings::ABILITIES as $ability) {
            $this->assertFalse(
                Capabilities::can($ability),
                sprintf('A contributor without upload_files must not be able to %s.', $ability)
            );
        }
    }

    /**
     * @test
     */
    public function administrators_are_pinned_against_a_matrix_that_denies_them(): void
    {
        // An administrator locked out of the folder tree cannot open the
        // screen that would let them back in.
        $this->matrix(['administrator' => []]);
        $this->asRole('administrator');

        foreach (Settings::ABILITIES as $ability) {
            $this->assertTrue(Capabilities::can($ability), 'Administrators keep every folder ability.');
        }
    }

    /**
     * @test
     */
    public function the_shipped_defaults_match_the_row_screen_08_draws_for_an_author(): void
    {
        $this->asRole('author');

        $this->assertTrue(Capabilities::can('create'));
        $this->assertFalse(Capabilities::can('rename'));
        $this->assertFalse(Capabilities::can('delete'));
        $this->assertTrue(Capabilities::can('assign'));
    }

    /**
     * @test
     */
    public function the_matrix_revokes(): void
    {
        $this->asRole('editor');
        $this->assertTrue(Capabilities::can('delete'), 'Editors delete by default.');

        $this->matrix(['editor' => ['create', 'assign']]);

        $this->assertTrue(Capabilities::can('create'));
        $this->assertFalse(Capabilities::can('delete'));
        $this->assertFalse(Capabilities::can('rename'));
    }

    /**
     * @test
     */
    public function the_matrix_grants(): void
    {
        $this->matrix(['author' => ['create', 'rename', 'delete', 'assign']]);
        $this->asRole('author');

        $this->assertTrue(Capabilities::can('delete'), 'A site may hand deletion to Authors.');
    }

    /**
     * @test
     */
    public function a_role_the_matrix_has_never_heard_of_keeps_what_the_route_asked_before(): void
    {
        // Shop Manager, or anything a membership plugin registers. Denying it
        // outright would break those sites silently on upgrade.
        add_role('folderfolio_test_manager', 'Test Manager', [
            'read' => true,
            'upload_files' => true,
            'edit_others_posts' => true,
        ]);
        add_role('folderfolio_test_helper', 'Test Helper', [
            'read' => true,
            'upload_files' => true,
        ]);

        try {
            $this->asRole('folderfolio_test_manager');
            $this->assertTrue(Capabilities::can('delete'), 'edit_others_posts is what delete used to require.');

            $this->asRole('folderfolio_test_helper');
            $this->assertFalse(Capabilities::can('delete'));
            $this->assertTrue(Capabilities::can('assign'), 'Assignment used to require only upload_files.');
        } finally {
            remove_role('folderfolio_test_manager');
            remove_role('folderfolio_test_helper');
        }
    }

    /**
     * @test
     */
    public function the_explicit_capability_bypasses_the_matrix(): void
    {
        // The escape hatch for one user, granted in code rather than in the
        // table: it is deliberate, so it does not need the table's permission.
        $userId = $this->factory->user->create(['role' => 'subscriber']);
        $user = get_user_by('id', $userId);

        $this->assertNotFalse($user);

        $user->add_cap('upload_files');
        $user->add_cap(Capabilities::MANAGE);

        wp_set_current_user($userId);

        $this->assertTrue(Capabilities::can('delete'));
    }

    /**
     * @test
     */
    public function an_ability_nobody_defined_is_false_rather_than_an_error(): void
    {
        $this->asRole('administrator');

        $this->assertFalse(Capabilities::can('publish_nukes'));
    }

    /**
     * @test
     */
    public function the_coarse_answer_is_any_of_the_three_structural_abilities(): void
    {
        // What the toolbar asks: "is there anything here this user could do?"
        $this->asRole('author');
        $this->assertTrue(Capabilities::canManageFolders(), 'Authors create, so the toolbar is worth showing.');

        $this->matrix(['author' => ['assign']]);
        $this->assertFalse(Capabilities::canManageFolders(), 'Filing media is not managing folders.');
    }

    /**
     * @test
     */
    public function every_answer_is_filterable(): void
    {
        $this->asRole('editor');

        $this->assertTrue(Capabilities::can('delete'));

        $filter = static fn (bool $allowed, string $ability): bool => 'delete' !== $ability && $allowed;

        add_filter('folderfolio_user_can', $filter, 10, 2);

        try {
            // The filter is the last word, after the matrix and after both
            // rules — it is code on the site, which is a higher authority than
            // a table in the admin.
            $this->assertFalse(Capabilities::can('delete'));
            $this->assertTrue(Capabilities::can('rename'));
        } finally {
            remove_filter('folderfolio_user_can', $filter, 10);
        }
    }
}
