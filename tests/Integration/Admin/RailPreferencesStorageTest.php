<?php

namespace FolderFolio\Tests\Integration\Admin;

use FolderFolio\Admin\RailPreferences;
use WP_UnitTestCase;

/**
 * Where a person's rail lives: a user option since review item #27, so each
 * site of a network has its own. The value written before that is plain user
 * meta and is still read — on a single site it is the person's rail.
 */
class RailPreferencesStorageTest extends WP_UnitTestCase
{
    /**
     * @test
     */
    public function a_value_stored_the_old_way_is_still_read(): void
    {
        $userId = self::factory()->user->create();
        update_user_meta($userId, RailPreferences::META_KEY, ['open' => true, 'width' => 310, 'stars' => [4]]);

        $prefs = RailPreferences::forUser($userId);

        $this->assertSame(310, $prefs['width']);
        $this->assertSame([4], $prefs['stars']);
    }

    /**
     * @test
     */
    public function a_save_writes_this_sites_key_and_keeps_what_it_merged(): void
    {
        global $wpdb;

        $userId = self::factory()->user->create();
        update_user_meta($userId, RailPreferences::META_KEY, ['open' => true, 'width' => 310]);

        RailPreferences::save($userId, array_merge(RailPreferences::forUser($userId), ['stars' => [9]]));

        $stored = get_user_meta($userId, $wpdb->get_blog_prefix() . RailPreferences::META_KEY, true);

        $this->assertIsArray($stored);
        $this->assertSame(310, $stored['width'], 'The width read from the old row survives the merge.');
        $this->assertSame([9], RailPreferences::forUser($userId)['stars']);

        if (!is_multisite()) {
            $this->assertSame('', get_user_meta($userId, RailPreferences::META_KEY, true), 'On one site the old row is spent.');
        }
    }
}
