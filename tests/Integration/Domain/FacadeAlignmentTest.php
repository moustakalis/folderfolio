<?php

namespace FolderFolio\Tests\Integration\Domain;

use FolderFolio\Database\Schema;
use FolderFolio\Domain\Folder;
use FolderFolio\Domain\SmartFolders;
use FolderFolio\Support\Settings;
use WP_Error;
use WP_UnitTestCase;
use ZipArchive;

/**
 * What the rail can do, the facade can do — 24 Sep, alignment audit item B —
 * with the hooks the audit found missing, and the settings a fleet needs
 * (item C). WP-CLI calls these same methods; the commands were run end to
 * end in the rig (record `…-24g-the-api-caught-up`).
 */
class FacadeAlignmentTest extends WP_UnitTestCase
{
    /** @var array<string, list<array<int, mixed>>> */
    private array $heard = [];

    /** @var list<string> */
    private array $made = [];

    private const HOOKS = [
        'folderfolio_folder_color_changed' => 2,
        'folderfolio_folder_sort_changed' => 3,
        'folderfolio_files_ordered' => 2,
        'folderfolio_smart_folder_saved' => 2,
        'folderfolio_smart_folder_deleted' => 2,
        'folderfolio_import_finished' => 1,
        'folderfolio_import_undone' => 1,
    ];

    public function setUp(): void
    {
        parent::setUp();
        (new Schema())->migrate();

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_attachment_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folder_meta");
        delete_option(SmartFolders::OPTION);
        delete_option(Settings::OPTION);

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        foreach (self::HOOKS as $hook => $count) {
            add_action($hook, function (...$args) use ($hook): void {
                $this->heard[$hook][] = $args;
            }, 10, $count);
        }
    }

    public function tearDown(): void
    {
        foreach ($this->made as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        delete_option(SmartFolders::OPTION);
        delete_option(Settings::OPTION);

        parent::tearDown();
    }

    private function folder(string $name, ?int $parent = null): Folder
    {
        $folder = \FolderFolio::createFolder($name, $parent);
        $this->assertInstanceOf(Folder::class, $folder);

        return $folder;
    }

    /**
     * @return array<string, mixed>
     */
    private function node(int $id): array
    {
        $walk = static function (array $nodes) use (&$walk, $id): ?array {
            foreach ($nodes as $node) {
                if ((int) $node['id'] === $id) {
                    return $node;
                }

                $found = $walk($node['children'] ?? []);

                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        };

        return $walk(\FolderFolio::getTree()) ?? [];
    }

    private function image(string $title, string $date): int
    {
        return self::factory()->attachment->create([
            'post_mime_type' => 'image/jpeg',
            'post_title' => $title,
            'post_date' => $date,
        ]);
    }

    /** @test */
    public function the_facade_organises_as_the_rail_does(): void
    {
        $brand = $this->folder('Brand');
        $logos = $this->folder('Logos', $brand->id);
        $press = $this->folder('Press');

        // Colour — announced once, with what it was.
        $this->assertSame('plum', \FolderFolio::setFolderColor($brand->id, 'plum')->color);
        $this->assertSame('plum', \FolderFolio::setFolderColor($brand->id, 'plum')->color);
        $this->assertNull(\FolderFolio::setFolderColor($brand->id, null)->color);
        $this->assertSame([[$brand->id, null], [$brand->id, 'plum']], array_map(
            static fn (array $a): array => [$a[0]->id, $a[1]],
            $this->heard['folderfolio_folder_color_changed']
        ));

        // Sort — set, cleared, refused.
        \FolderFolio::setFolderSort($brand->id, 'files', 'newest');
        $this->assertSame('newest', $this->node($brand->id)['sort_files']);
        \FolderFolio::setFolderSort($brand->id, 'files', null);
        $this->assertNull($this->node($brand->id)['sort_files']);
        $this->assertSame('folderfolio_sort_order', \FolderFolio::setFolderSort($brand->id, 'files', 'sideways')->get_error_code());
        $this->assertSame('folderfolio_folder_not_found', \FolderFolio::setFolderSort(999999, 'files', 'newest')->get_error_code());
        $this->assertSame([['files', 'newest'], ['files', null]], array_map(
            static fn (array $a): array => [$a[1], $a[2]],
            $this->heard['folderfolio_folder_sort_changed']
        ));

        // Lock, pin, kind.
        \FolderFolio::lockFolder($logos->id);
        \FolderFolio::pinFolder($press->id);
        $this->assertTrue((bool) $this->node($logos->id)['locked']);
        $this->assertTrue((bool) $this->node($press->id)['pinned']);
        \FolderFolio::lockFolder($logos->id, false);
        $this->assertFalse((bool) $this->node($logos->id)['locked']);
        \FolderFolio::setFolderKind($press->id, 'gallery');
        $this->assertSame('gallery', $this->node($press->id)['kind']);
        $this->assertSame('folderfolio_kind_unknown', \FolderFolio::setFolderKind($press->id, 'album')->get_error_code());

        // Duplicate beside the original, then arrange the top level by hand.
        $copy = \FolderFolio::duplicateFolder($brand->id, $brand->parentId);
        $this->assertInstanceOf(Folder::class, $copy);
        $this->assertNull($copy->parentId);
        $this->assertCount(1, \FolderFolio::getChildren($copy->id), 'Logos came too');

        $this->assertSame(3, \FolderFolio::reorderFolders(null, [$press->id, $copy->id, $brand->id]));
        \FolderFolio::setFolderSort($press->id, 'folders', null);
        $top = array_map(static fn (array $n): int => (int) $n['id'], \FolderFolio::getTree());
        $this->assertSame([$press->id, $copy->id, $brand->id], $top, 'Custom order is the order given (Press is pinned too)');
        $this->assertSame('folderfolio_reorder_stale', \FolderFolio::reorderFolders(null, [$press->id, $brand->id])->get_error_code());
    }

    /** @test */
    public function files_are_placed_and_the_order_is_announced(): void
    {
        $folder = $this->folder('Hero');
        $old = $this->image('old', '2026-01-01 10:00:00');
        $new = $this->image('new', '2026-03-01 10:00:00');
        \FolderFolio::assign([$old, $new], $folder->id);

        $this->assertSame(2, \FolderFolio::orderFiles($folder->id, [$new], 'start'));
        $this->assertSame('custom', $this->node($folder->id)['sort_files']);
        $this->assertSame([[[$new, $old], $folder->id]], $this->heard['folderfolio_files_ordered']);

        $this->assertSame('folderfolio_order_not_here', \FolderFolio::orderFiles($folder->id, [123456], 'end')->get_error_code());
    }

    /** @test */
    public function smart_folders_are_made_counted_and_heard(): void
    {
        $this->image('beach', '2026-01-01 10:00:00');
        $this->image('dune', '2026-02-01 10:00:00');
        self::factory()->attachment->create(['post_mime_type' => 'application/pdf', 'post_title' => 'brief']);

        $smart = \FolderFolio::createSmartFolder('Images', [['field' => 'type', 'op' => 'is', 'value' => 'image']]);
        $this->assertIsArray($smart);
        $this->assertSame(2, \FolderFolio::countSmartFolderItems($smart['id']));

        $ids = \FolderFolio::getSmartFolderItemIds($smart['id']);
        $this->assertCount(2, $ids);
        $this->assertSame('dune', get_the_title($ids[0]), 'newest first');

        $renamed = \FolderFolio::updateSmartFolder($smart['id'], 'Pictures');
        $this->assertSame('Pictures', $renamed['name']);
        $this->assertCount(1, $renamed['rules'], 'null kept the rules');
        $this->assertCount(1, \FolderFolio::getSmartFolders());

        $this->assertSame('folderfolio_smart_no_rules', \FolderFolio::createSmartFolder('Nothing', [])->get_error_code());
        $this->assertTrue(\FolderFolio::deleteSmartFolder($smart['id']));
        $this->assertInstanceOf(WP_Error::class, \FolderFolio::getSmartFolderItemIds($smart['id']));

        $this->assertSame([true, false], array_column($this->heard['folderfolio_smart_folder_saved'], 1));
        $this->assertSame($smart['id'], $this->heard['folderfolio_smart_folder_deleted'][0][0]);
        $this->assertSame('Pictures', $this->heard['folderfolio_smart_folder_deleted'][0][1]['name']);
    }

    /** @test */
    public function upkeep_exports_zips_and_repairs(): void
    {
        $folder = $this->folder('Kit');
        $dir = wp_get_upload_dir()['basedir'] . '/ff-facade';
        wp_mkdir_p($dir);
        file_put_contents($dir . '/a.jpg', 'jpeg bytes');
        $this->made[] = $dir . '/a.jpg';
        $file = self::factory()->attachment->create_object(['file' => $dir . '/a.jpg', 'post_mime_type' => 'image/jpeg', 'post_title' => 'a']);
        update_post_meta($file, '_wp_attached_file', 'ff-facade/a.jpg');
        \FolderFolio::assign([$file], $folder->id);

        $export = \FolderFolio::exportFolders(true);
        $this->assertCount(1, $export['folders']);
        $this->assertNotEmpty($export['assignments']);

        $out = get_temp_dir() . 'ff-facade-' . wp_generate_password(6, false);
        wp_mkdir_p($out);
        $written = \FolderFolio::zipFolder($folder->id, $out);
        $this->assertIsArray($written);
        $this->made[] = $written['path'];
        $this->assertSame($out . '/Kit.zip', $written['path']);
        $this->assertSame(1, $written['files']);
        $this->assertSame($written['length'], filesize($written['path']), 'the manifest\'s length is the file\'s');

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($written['path']));
        $this->assertSame('jpeg bytes', $zip->getFromName('Kit/a.jpg'));
        $zip->close();

        $this->assertSame('folderfolio_zip_exists', \FolderFolio::zipFolder($folder->id, $written['path'])->get_error_code());
        $this->assertSame('folderfolio_folder_not_found', \FolderFolio::zipFolder(999999, $out)->get_error_code());

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'folderfolio_attachment_folders', ['attachment_id' => 987654, 'folder_id' => $folder->id]);
        $this->assertSame(1, \FolderFolio::removeOrphans());
        $this->assertSame(0, \FolderFolio::removeOrphans());
        $this->assertSame(0, \FolderFolio::rebuildPaths(), 'nothing had drifted');
        $wpdb->update($wpdb->prefix . 'folderfolio_folders', ['path' => '/7/' . $folder->id . '/', 'depth' => 1], ['id' => $folder->id]);
        $this->assertSame(1, \FolderFolio::rebuildPaths(), 'the one that had');
        $this->assertSame('/' . $folder->id . '/', \FolderFolio::getFolder($folder->id)->path);
    }

    /** @test */
    public function settings_change_one_key_and_refuse_what_the_form_could_not_send(): void
    {
        $gallery = $this->folder('Home');

        $saved = \FolderFolio::updateSettings(['undo_window' => 12, 'roles' => ['author' => ['create', 'assign']]]);
        $this->assertIsArray($saved);
        $this->assertSame(12, \FolderFolio::getSettings()['undo_window']);
        $this->assertSame(['create', 'assign'], \FolderFolio::getSettings()['roles']['author']);
        $this->assertSame(Settings::defaultRoles()['editor'], \FolderFolio::getSettings()['roles']['editor'], 'the other roles kept');

        \FolderFolio::updateSettings(['default_sort' => 'custom']);
        $this->assertSame(12, \FolderFolio::getSettings()['undo_window'], 'a later change keeps an earlier one');

        $refusals = [
            ['undo_window' => 90],
            ['count_mode' => 'deep'],
            ['default_sort' => 'size'],
            ['startup_folder' => 999999],
            ['post_types' => ['not_a_type']],
            ['roles' => ['wizard' => ['create']]],
            ['roles' => ['author' => ['fly']]],
        ];

        foreach ($refusals as $change) {
            $result = \FolderFolio::updateSettings($change);
            $this->assertInstanceOf(WP_Error::class, $result, (string) wp_json_encode($change));
            $this->assertSame('folderfolio_setting_invalid', $result->get_error_code());
        }

        $this->assertSame('folderfolio_setting_unknown', \FolderFolio::updateSettings(['colour' => 'red'])->get_error_code());
        $this->assertSame(12, \FolderFolio::getSettings()['undo_window'], 'a refusal writes nothing');

        $this->assertIsArray(\FolderFolio::updateSettings(['startup_folder' => 0]));
        $this->assertSame(0, \FolderFolio::getSettings()['startup_folder'], 'Unassigned');
        \FolderFolio::updateSettings(['startup_folder' => $gallery->id, 'post_types' => []]);
        $this->assertSame($gallery->id, \FolderFolio::getSettings()['startup_folder']);
        $this->assertSame([], \FolderFolio::getSettings()['post_types'], 'media only');
    }

    /** @test */
    public function an_import_is_heard_finishing_once_and_undone(): void
    {
        $rows = [];

        for ($i = 0; $i < 3; ++$i) {
            $rows[] = ['id' => 100 + $i, 'parent_id' => null, 'name' => "Imported {$i}", 'sort_order' => $i];
        }

        $path = get_temp_dir() . 'ff-facade-' . wp_generate_password(6, false) . '.json';
        file_put_contents($path, (string) wp_json_encode([
            'folderfolio' => \FolderFolio\Domain\FolderExport::FORMAT,
            'site' => home_url(),
            'folders' => $rows,
            'assignments' => [],
        ]));
        $this->made[] = $path;

        (new \FolderFolio\Modules\Import\RunStore())->clear();
        $source = \FolderFolio\Modules\Import\Catalog::fromArgument($path);
        $this->assertNotInstanceOf(WP_Error::class, $source);

        $runner = new \FolderFolio\Modules\Import\Runner();
        $runner->start($source);
        $runner->toEnd();
        $runner->step();

        $this->assertCount(1, $this->heard['folderfolio_import_finished'] ?? [], 'once, however often it is asked after');
        $this->assertSame('done', $this->heard['folderfolio_import_finished'][0][0]['status']);
        $this->assertSame(3, $this->heard['folderfolio_import_finished'][0][0]['folders_created']);

        $runner->undo();
        $this->assertSame('undone', $this->heard['folderfolio_import_undone'][0][0]['status']);
        (new \FolderFolio\Modules\Import\RunStore())->clear();
    }
}
