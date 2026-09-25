<?php

namespace FolderFolio\Tests\Integration\Database;

use FolderFolio\Database\Transaction;
use WP_Error;
use WP_UnitTestCase;

/**
 * Review M4: the cache key moves on once the work is visible.
 *
 * Every write bumps `last_changed('folderfolio')` inside the open
 * transaction. Another request reading during it sees the old rows under the
 * new key and caches them; without a bump after the commit, a persistent
 * object cache kept serving them until the next write.
 */
class TransactionCacheTest extends WP_UnitTestCase
{
    /**
     * @test
     */
    public function the_key_changes_after_the_commit(): void
    {
        $inside = null;

        Transaction::run(static function () use (&$inside): bool {
            wp_cache_set_last_changed('folderfolio');
            $inside = wp_cache_get_last_changed('folderfolio');
            usleep(2);

            return true;
        });

        $this->assertNotNull($inside);
        $this->assertNotSame($inside, wp_cache_get_last_changed('folderfolio'));
    }

    /**
     * @test
     */
    public function the_key_changes_after_a_rollback(): void
    {
        $inside = null;

        Transaction::run(static function () use (&$inside): WP_Error {
            wp_cache_set_last_changed('folderfolio');
            $inside = wp_cache_get_last_changed('folderfolio');
            usleep(2);

            return new WP_Error('nope', 'nope');
        });

        $this->assertNotSame($inside, wp_cache_get_last_changed('folderfolio'));
    }

    /**
     * @test
     *
     * An inner block is not the end of the work: the key moves once, at the
     * outermost, not at every savepoint.
     */
    public function a_hook_after_the_commit_reads_the_new_key(): void
    {
        $inside = null;
        $seen = null;

        Transaction::run(static function () use (&$inside, &$seen): bool {
            wp_cache_set_last_changed('folderfolio');
            $inside = wp_cache_get_last_changed('folderfolio');
            usleep(2);
            Transaction::after(static function () use (&$seen): void {
                $seen = wp_cache_get_last_changed('folderfolio');
            });

            return true;
        });

        $this->assertNotSame($inside, $seen);
    }
}
