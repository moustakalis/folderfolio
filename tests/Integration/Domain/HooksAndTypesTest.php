<?php

namespace FolderFolio\Tests\Integration\Domain;

use FolderFolio\Database\Schema;
use FolderFolio\Database\Transaction;
use FolderFolio\Domain\Folder;
use FolderFolio\Domain\FolderService;
use WP_Error;
use WP_UnitTestCase;

/**
 * Review M12 (the created hook waits for the commit) and L6 (no folders for a
 * type that has folders turned off).
 */
class HooksAndTypesTest extends WP_UnitTestCase
{
    private FolderService $service;

    public function setUp(): void
    {
        parent::setUp();
        (new Schema())->migrate();
        $this->service = new FolderService();

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_attachment_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folders");

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    /**
     * @test
     */
    public function the_created_hook_is_not_heard_for_folders_a_rollback_undid(): void
    {
        $heard = [];
        $listen = static function (Folder $folder) use (&$heard): void {
            $heard[] = $folder->name;
        };
        add_action('folderfolio_folder_created', $listen);

        Transaction::run(function (): WP_Error {
            $this->service->create(['name' => 'One']);
            $this->service->create(['name' => 'Two']);

            return new WP_Error('refused', 'the third line was refused');
        });

        $this->assertSame([], $heard, 'nothing was announced that does not exist');

        // After a commit, each is heard once; outside a transaction, at once.
        Transaction::run(function (): bool {
            $this->service->create(['name' => 'Three']);

            return true;
        });
        $this->service->create(['name' => 'Four']);

        remove_action('folderfolio_folder_created', $listen);
        $this->assertSame(['Three', 'Four'], $heard);
    }

    /**
     * @test
     */
    public function no_folder_is_made_for_a_type_without_folders(): void
    {
        register_post_type('ff_nope', ['public' => true, 'show_ui' => true]);

        $refused = \FolderFolio::createFolder('Nowhere', null, 'ff_nope');
        $this->assertInstanceOf(WP_Error::class, $refused);
        $this->assertSame('folderfolio_type_without_folders', $refused->get_error_code());

        $ghost = $this->service->create(['name' => 'Ghost', 'object_type' => 'ff_ghost']);
        $this->assertInstanceOf(WP_Error::class, $ghost);

        // Media and Posts have folders.
        $this->assertInstanceOf(Folder::class, \FolderFolio::createFolder('Here'));
        $this->assertInstanceOf(Folder::class, \FolderFolio::createFolder('Posts here', null, 'post'));

        unregister_post_type('ff_nope');
    }
}
