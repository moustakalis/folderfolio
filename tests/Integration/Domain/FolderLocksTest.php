<?php

namespace FolderFolio\Tests\Integration\Domain;

use FolderFolio\Database\Schema;
use FolderFolio\Domain\AttachmentFolderRepository;
use FolderFolio\Domain\Folder;
use FolderFolio\Domain\FolderBulk;
use FolderFolio\Domain\FolderLocks;
use FolderFolio\Domain\FolderRepository;
use FolderFolio\Domain\FolderService;
use FolderFolio\Domain\FolderSorts;
use FolderFolio\Support\Capabilities;
use WP_UnitTestCase;

/**
 * Lock and pin — tier 2 item 10, against a real database.
 *
 * A lock protects a folder's shape and its whole subtree's; colour and Sort
 * inside stay allowed; files still go in and out; the `lock` ability is
 * exempt (Nick's answers, board 3ZU8VGkJemznTvKp8tNnvY). The service is built
 * with an explicit bypass so each test says whether the person asking may lock.
 */
class FolderLocksTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        (new Schema())->migrate();

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_attachment_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folder_meta");
    }

    private function service(bool $mayLock): FolderService
    {
        return new FolderService(
            new FolderRepository(),
            new AttachmentFolderRepository(),
            new FolderSorts(),
            new FolderLocks(static fn (): bool => $mayLock)
        );
    }

    /**
     * @return array{0: Folder, 1: Folder, 2: Folder}
     */
    private function acme(FolderService $admin): array
    {
        $clients = $admin->create(['name' => 'Clients']);
        $acme = $admin->create(['name' => 'Acme', 'parent_id' => $clients->id]);
        $logos = $admin->create(['name' => 'Logos', 'parent_id' => $acme->id]);
        $this->assertInstanceOf(Folder::class, $logos);
        $this->assertInstanceOf(Folder::class, $admin->mark($acme->id, FolderLocks::LOCKED, true));

        return [$clients, $acme, $logos];
    }

    private function assertLocked(mixed $result, string $name = 'Acme'): void
    {
        $this->assertWPError($result);
        $this->assertSame('folderfolio_locked', $result->get_error_code());
        $this->assertStringContainsString($name, $result->get_error_message());
    }

    /** @test */
    public function a_locked_folder_keeps_its_shape_and_its_subtree_s(): void
    {
        [$clients, $acme, $logos] = $this->acme($this->service(true));
        $user = $this->service(false);

        $this->assertLocked($user->update($acme->id, ['name' => 'Acme Ltd']));
        $this->assertLocked($user->update($logos->id, ['name' => 'Marks']), 'Acme');
        $this->assertLocked($user->move($acme->id, null));
        $this->assertLocked($user->move($logos->id, $clients->id));
        $this->assertLocked($user->delete($logos->id, FolderService::CHILDREN_CASCADE));
        $this->assertLocked($user->create(['name' => 'New', 'parent_id' => $acme->id]));
        $this->assertLocked($user->create(['name' => 'Deeper', 'parent_id' => $logos->id]));
        $this->assertLocked($user->getOrCreateByPath('Brand', FolderRepository::DEFAULT_OBJECT_TYPE, $logos->id));
        $this->assertWPError((new FolderBulk($user))->run("Brand\nOther", $acme->id));
        $this->assertLocked($user->duplicate($clients->id, $logos->id));

        // Nothing moved.
        $this->assertSame('Logos', $user->get($logos->id)?->name);
        $this->assertSame($acme->id, $user->get($logos->id)?->parentId);
    }

    /** @test */
    public function a_parent_with_a_locked_folder_inside_cannot_be_deleted(): void
    {
        [$clients] = $this->acme($this->service(true));
        $user = $this->service(false);

        $this->assertLocked($user->delete($clients->id, FolderService::CHILDREN_CASCADE));
        $this->assertLocked($user->delete($clients->id, FolderService::CHILDREN_REPARENT));
        $this->assertNotNull($user->get($clients->id));
    }

    /**
     * Colour, Sort inside, files, and copying out stay allowed (answer 6).
     *
     * @test
     */
    public function a_lock_does_not_stop_what_does_not_change_its_shape(): void
    {
        [, $acme, $logos] = $this->acme($this->service(true));
        $user = $this->service(false);

        $this->assertInstanceOf(Folder::class, $user->update($acme->id, ['color' => 'teal']));
        $this->assertTrue((new FolderSorts())->set($acme->id, 'folders', 'name-desc'));

        $file = self::factory()->attachment->create();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertSame(1, $user->assignAttachments($logos->id, [$file]));

        $copy = $user->duplicate($acme->id, null);
        $this->assertInstanceOf(Folder::class, $copy);

        $locked = (new FolderLocks())->locked();
        $this->assertArrayNotHasKey($copy->id, $locked, 'a copy comes out unlocked');
    }

    /**
     * Someone who may lock is not stopped by one (answer: a permission).
     *
     * @test
     */
    public function the_lock_ability_is_exempt(): void
    {
        [$clients, $acme, $logos] = $this->acme($this->service(true));
        $admin = $this->service(true);

        $this->assertInstanceOf(Folder::class, $admin->update($logos->id, ['name' => 'Marks']));
        $this->assertInstanceOf(Folder::class, $admin->create(['name' => 'New', 'parent_id' => $acme->id]));
        $this->assertInstanceOf(Folder::class, $admin->move($acme->id, null));
        $this->assertIsInt($admin->delete($clients->id, FolderService::CHILDREN_CASCADE));
    }

    /**
     * The locked folder itself moving in its level is refused; another folder
     * moving past it is not.
     *
     * @test
     */
    public function a_reorder_may_move_others_past_a_locked_folder_but_not_move_it(): void
    {
        $admin = $this->service(true);
        $a = $admin->create(['name' => 'A']);
        $b = $admin->create(['name' => 'B']);
        $c = $admin->create(['name' => 'C']);
        $this->assertSame(3, $admin->reorder(null, [$a->id, $b->id, $c->id]));
        $admin->mark($b->id, FolderLocks::LOCKED, true);

        $user = $this->service(false);

        $this->assertLocked($user->reorder(null, [$b->id, $a->id, $c->id]), 'B');
        $this->assertSame(3, $user->reorder(null, [$c->id, $a->id, $b->id]), 'C moved to the top, past B');
    }

    /** @test */
    public function the_children_of_a_locked_folder_cannot_be_rearranged(): void
    {
        [, $acme, $logos] = $this->acme($this->service(true));
        $second = $this->service(true)->create(['name' => 'Icons', 'parent_id' => $acme->id]);

        $this->assertLocked($this->service(false)->reorder($acme->id, [$second->id, $logos->id]));
    }

    /** @test */
    public function pinning_a_locked_folder_needs_the_lock_ability(): void
    {
        [, $acme] = $this->acme($this->service(true));

        $this->assertLocked($this->service(false)->mark($acme->id, FolderLocks::PINNED, true));
        $this->assertInstanceOf(Folder::class, $this->service(true)->mark($acme->id, FolderLocks::PINNED, true));
    }

    /** @test */
    public function the_tree_carries_each_folder_s_own_marks(): void
    {
        [$clients, $acme, $logos] = $this->acme($this->service(true));
        $this->service(true)->mark($clients->id, FolderLocks::PINNED, true);

        $tree = $this->service(false)->tree();
        $clientsNode = $tree[0];
        $acmeNode = $clientsNode['children'][0];

        $this->assertTrue($clientsNode['pinned']);
        $this->assertFalse($clientsNode['locked']);
        $this->assertTrue($acmeNode['locked']);
        $this->assertFalse($acmeNode['children'][0]['locked'], 'its own mark');
        $this->assertSame($acme->id, $acmeNode['children'][0]['locked_by'], 'and whose lock covers it');
        $this->assertNull($clientsNode['locked_by']);

        $this->service(true)->mark($acme->id, FolderLocks::LOCKED, false);
        $this->assertInstanceOf(Folder::class, $this->service(false)->update($logos->id, ['name' => 'Marks']));
    }

    /**
     * Out of the box only administrators may lock (answer 5).
     *
     * @test
     */
    public function only_administrators_hold_lock_by_default(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->assertTrue(Capabilities::can('rename'));
        $this->assertFalse(Capabilities::can('lock'));

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertTrue(Capabilities::can('lock'));
    }

    /**
     * Review L1: PATCH sort_order moved a locked folder within its level,
     * which reorder and pin both refuse.
     *
     * @test
     */
    public function a_new_sort_order_is_a_move_a_lock_refuses(): void
    {
        [, $acme] = $this->acme($this->service(true));
        $user = $this->service(false);

        $this->assertLocked($user->update($acme->id, ['sort_order' => -5]));

        // The same order, a colour: not a move.
        $this->assertInstanceOf(Folder::class, $user->update($acme->id, ['sort_order' => $acme->sortOrder, 'color' => 'moss']));
        $this->assertInstanceOf(Folder::class, $this->service(true)->update($acme->id, ['sort_order' => -5]));
    }

    /**
     * Review L2: the exemption is asked about the locked folder's own type.
     *
     * @test
     */
    public function the_exemption_is_asked_for_the_locked_folders_type(): void
    {
        $asked = [];
        $service = new FolderService(
            new FolderRepository(),
            new AttachmentFolderRepository(),
            new FolderSorts(),
            new FolderLocks(static function (string $type) use (&$asked): bool {
                $asked[] = $type;

                return 'post' === $type;
            })
        );

        $news = $this->service(true)->create(['name' => 'News', 'object_type' => 'post']);
        $this->service(true)->mark($news->id, FolderLocks::LOCKED, true);
        // Both locks before the service reads them: locks are read once per
        // request.
        [, $acme] = $this->acme($this->service(true));

        // May lock Posts folders, and nothing else: their own lock does not stop them.
        $this->assertInstanceOf(Folder::class, $service->update($news->id, ['name' => 'Newsroom']));
        $this->assertSame(['post'], array_values(array_unique($asked)));

        // A media lock still does.
        $this->assertLocked($service->update($acme->id, ['name' => 'Acme Ltd']));
    }
}
