<?php

namespace FolderFolio\Tests\Integration\Domain;

use FolderFolio\Admin\MediaLibraryFilter;
use FolderFolio\Database\Schema;
use FolderFolio\Domain\AttachmentFolderRepository;
use FolderFolio\Domain\Folder;
use FolderFolio\Domain\FolderBulk;
use FolderFolio\Domain\FolderService;
use FolderFolio\Plugin;
use FolderFolio\Support\Capabilities;
use FolderFolio\Support\PostTypes;
use FolderFolio\Support\Settings;
use WP_Query;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Folders for posts, pages and custom post types — tier 3 item 12.
 *
 * One tree per object type (Nick's 12b): nothing crosses between them, each
 * counts only what its list screen shows, and each is reached through its own
 * type's capability (12e).
 */
class PostFoldersTest extends WP_UnitTestCase
{
    private FolderService $service;

    public function setUp(): void
    {
        parent::setUp();
        (new Schema())->migrate();
        update_option('folderfolio_db_version', Plugin::DB_VERSION);
        delete_option(Settings::OPTION);
        $this->service = new FolderService();

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_attachment_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folders");

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function folder(string $name, string $type = 'page', ?int $parent = null): Folder
    {
        $folder = $this->service->create(['name' => $name, 'object_type' => $type, 'parent_id' => $parent]);
        $this->assertInstanceOf(Folder::class, $folder);

        return $folder;
    }

    /** @test */
    public function each_type_has_its_own_tree(): void
    {
        $this->folder('Brand', 'attachment');
        $this->folder('Landing', 'page');
        $this->folder('News', 'post');

        $this->assertSame(['Brand'], array_column($this->service->tree(), 'name'));
        $this->assertSame(['Landing'], array_column($this->service->tree('page'), 'name'));
        $this->assertSame(['News'], array_column($this->service->tree('post'), 'name'));

        // The same name in two trees is two folders, not a clash.
        $this->folder('Brand', 'page');
        $this->assertCount(2, $this->service->tree('page'));
    }

    /** @test */
    public function a_folder_never_sits_in_another_types_tree(): void
    {
        $media = $this->folder('Brand', 'attachment');
        $page = $this->folder('Landing', 'page');

        $inside = $this->service->create(['name' => 'Stray', 'object_type' => 'page', 'parent_id' => $media->id]);
        $this->assertWPError($inside);
        $this->assertSame('folderfolio_wrong_type', $inside->get_error_code());

        $moved = $this->service->move($page->id, $media->id);
        $this->assertWPError($moved);
        $this->assertSame('folderfolio_wrong_type', $moved->get_error_code());

        $planned = (new FolderBulk())->plan("A\nB", $media->id, 'page');
        $this->assertWPError($planned);
        $this->assertSame('folderfolio_wrong_type', $planned->get_error_code());

        $pasted = $this->service->duplicate($page->id, $media->id);
        $this->assertWPError($pasted);

        // The negative control: inside its own tree, all of it works.
        $this->assertInstanceOf(Folder::class, $this->service->move($this->folder('Child', 'page')->id, $page->id));
        $this->assertIsArray((new FolderBulk())->plan("A\nB", $page->id, 'page'));
    }

    /** @test */
    public function only_the_folders_own_kind_is_filed_in_it(): void
    {
        $pages = $this->folder('Landing', 'page');
        $media = $this->folder('Brand', 'attachment');

        $page = self::factory()->post->create(['post_type' => 'page']);
        $post = self::factory()->post->create(['post_type' => 'post']);
        $file = self::factory()->attachment->create();

        foreach ([[$pages, $post], [$pages, $file], [$media, $page]] as [$folder, $item]) {
            $refused = $this->service->assignAttachments($folder->id, [$item]);
            $this->assertWPError($refused);
            $this->assertSame('folderfolio_invalid_attachment', $refused->get_error_code());
        }

        $this->assertSame(1, $this->service->assignAttachments($pages->id, [$page]));
        $this->assertSame(1, $this->service->assignAttachments($media->id, [$file]));

        // Many-to-many, as for media (12f).
        $second = $this->folder('Campaigns', 'page');
        $this->assertSame(1, $this->service->assignAttachments($second->id, [$page]));
        $this->assertSame([$page], $this->service->attachmentIds($pages->id));
        $this->assertSame([$page], $this->service->attachmentIds($second->id));

        // A delete may hand its items only to a folder of the same kind.
        $handed = $this->service->delete($pages->id, FolderService::CHILDREN_REPARENT, $media->id);
        $this->assertWPError($handed);
        $this->assertSame('folderfolio_invalid_reassignment', $handed->get_error_code());
    }

    /** @test */
    public function counts_follow_what_the_list_screen_shows(): void
    {
        $folder = $this->folder('Landing', 'page');

        $live = self::factory()->post->create(['post_type' => 'page']);
        $draft = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'draft']);
        $gone = self::factory()->post->create(['post_type' => 'page']);
        $loose = self::factory()->post->create(['post_type' => 'page']);

        $this->service->assignAttachments($folder->id, [$live, $draft, $gone]);
        wp_trash_post($gone);

        $node = $this->service->tree('page', 'direct')[0];
        $this->assertSame(2, $node['count'], 'a published page and a draft; not the one in the trash');
        $this->assertSame(2, $this->service->countAttachments($folder->id));

        $library = (new AttachmentFolderRepository())->libraryCounts('page');
        $this->assertSame(['all' => 3, 'unassigned' => 1], $library, 'live, draft and loose; loose alone is in no folder');
        $this->assertContains($loose, get_posts(['post_type' => 'page', 'fields' => 'ids', 'post_status' => 'any']));

        // A page in the trash comes back into its folder with it.
        wp_untrash_post($gone);
        $this->assertSame(3, $this->service->tree('page', 'direct')[0]['count']);
    }

    /** @test */
    public function a_deleted_post_leaves_every_folder(): void
    {
        $folder = $this->folder('News', 'post');
        $post = self::factory()->post->create();
        $this->service->assignAttachments($folder->id, [$post]);

        wp_delete_post($post, true);

        $this->assertSame([], $this->service->attachmentIds($folder->id));
    }

    /** @test */
    public function the_folder_filter_narrows_a_post_list(): void
    {
        $folder = $this->folder('News', 'post');
        [$in, $out] = self::factory()->post->create_many(2);
        $this->service->assignAttachments($folder->id, [$in]);

        $query = new WP_Query([
            'post_type' => 'post',
            'fields' => 'ids',
            MediaLibraryFilter::QUERY_VAR => $folder->id,
        ]);
        $this->assertSame([$in], array_map('intval', $query->posts));

        $query = new WP_Query(['post_type' => 'post', 'fields' => 'ids', MediaLibraryFilter::QUERY_VAR => 0]);
        $this->assertSame([$out], array_map('intval', $query->posts), 'Unassigned is the posts in no folder');
    }

    /** @test */
    public function each_type_is_reached_through_its_own_capability(): void
    {
        // An Author edits posts but not pages, and uploads files.
        wp_set_current_user(self::factory()->user->create(['role' => 'author']));

        $this->assertTrue(Capabilities::canUseFolders());
        $this->assertTrue(Capabilities::canUseFolders('post'));
        $this->assertFalse(Capabilities::canUseFolders('page'));
        $this->assertTrue(Capabilities::can('create', 'post'), 'the same matrix row as media');
        $this->assertFalse(Capabilities::can('create', 'page'));
        $this->assertFalse(Capabilities::can('download', 'post'), 'a folder of posts has no files to zip');

        // A type nobody ticked, or nobody registered, has no folders at all.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        register_post_type('ff_product', ['show_ui' => true, 'label' => 'Products']);
        $this->assertFalse(Capabilities::canUseFolders('ff_product'));
        $this->assertFalse(Capabilities::canUseFolders('nonsense'));

        update_option(Settings::OPTION, ['post_types' => ['post', 'page', 'ff_product']] + (array) get_option(Settings::OPTION, []));
        $this->assertTrue(Capabilities::canUseFolders('ff_product'));
        $this->assertSame(['attachment', 'post', 'page', 'ff_product'], PostTypes::enabled());
        $this->assertArrayHasKey('ff_product', PostTypes::offered());
        $this->assertArrayNotHasKey('wp_block', PostTypes::offered());

        // Switched off: media only, and media can never be switched off.
        update_option(Settings::OPTION, ['post_types' => []] + (array) get_option(Settings::OPTION, []));
        $this->assertSame(['attachment'], PostTypes::enabled());
        $this->assertFalse(Capabilities::canUseFolders('post'));

        unregister_post_type('ff_product');
    }

    /** @test */
    public function the_rest_routes_answer_for_the_folders_own_type(): void
    {
        $page = $this->folder('Landing', 'page');
        $this->folder('Brand', 'attachment');

        $get = new WP_REST_Request('GET', '/folderfolio/v1/folders');
        $get->set_param('object_type', 'page');
        $this->assertSame(['Landing'], array_column(rest_do_request($get)->get_data()['data'], 'name'));

        // A folder made at the top of the Pages tree, and one under a page folder
        // whatever the request claims.
        $create = new WP_REST_Request('POST', '/folderfolio/v1/folders');
        $create->set_body_params(['name' => 'Legal', 'object_type' => 'page']);
        $made = rest_do_request($create);
        $this->assertSame(201, $made->get_status());
        $this->assertSame('page', $made->get_data()['data']['folder']['object_type']);
        $this->assertSame(['Landing', 'Legal'], array_column($made->get_data()['data']['tree'], 'name'));

        $under = new WP_REST_Request('POST', '/folderfolio/v1/folders');
        $under->set_body_params(['name' => 'Terms', 'parent_id' => $page->id]);
        $this->assertSame('page', rest_do_request($under)->get_data()['data']['folder']['object_type']);

        // Every write returns the tree the folder is in — a delete included,
        // which can no longer ask the folder.
        $rename = new WP_REST_Request('PATCH', '/folderfolio/v1/folders/' . $page->id);
        $rename->set_body_params(['name' => 'Landings']);
        $this->assertContains('Landings', array_column(rest_do_request($rename)->get_data()['data']['tree'], 'name'));

        $delete = new WP_REST_Request('DELETE', '/folderfolio/v1/folders/' . $page->id);
        $delete->set_param('children', 'cascade');
        $this->assertSame(['Legal'], array_column(rest_do_request($delete)->get_data()['data']['tree'], 'name'));

        $counts = new WP_REST_Request('GET', '/folderfolio/v1/counts');
        $counts->set_param('object_type', 'page');
        $this->assertArrayHasKey('unassigned', rest_do_request($counts)->get_data()['data']['library']);

        // An Author reaches the Posts tree and not the Pages one — by the type
        // asked for, and by the folder's own type when a route names one.
        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
        $this->assertSame(403, rest_do_request($get)->get_status());
        $legal = $this->service->tree('page')[0]['id'];
        $rename = new WP_REST_Request('PATCH', '/folderfolio/v1/folders/' . $legal);
        $rename->set_body_params(['name' => 'Mine now', 'object_type' => 'post']);
        $this->assertSame(403, rest_do_request($rename)->get_status());

        $posts = new WP_REST_Request('GET', '/folderfolio/v1/folders');
        $posts->set_param('object_type', 'post');
        $this->assertSame(200, rest_do_request($posts)->get_status());

        // A folder of pages has no ZIP.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertNotSame(200, rest_do_request(new WP_REST_Request('GET', "/folderfolio/v1/folders/{$legal}/zip"))->get_status());
    }

    /** @test */
    public function the_facade_names_a_tree_and_a_parent_decides_it(): void
    {
        \FolderFolio::setService($this->service);

        $legal = \FolderFolio::createFolder('Legal', null, 'page');
        $this->assertInstanceOf(Folder::class, $legal);
        $this->assertSame('page', $legal->objectType);

        // Under a parent, the parent's tree whatever is asked.
        $terms = \FolderFolio::createFolder('Terms', $legal->id, 'attachment');
        $this->assertSame('page', $terms->objectType);

        $news = \FolderFolio::getOrCreateByPath('Newsroom/2026', 'post');
        $this->assertSame('post', $news->objectType);
        $this->assertSame($news->id, \FolderFolio::findFolderByPath('Newsroom/2026', 'post')?->id);
        $this->assertNull(\FolderFolio::findFolderByPath('Newsroom/2026'), 'not in the media tree');

        $this->assertSame(['Legal'], array_column(\FolderFolio::getTree(null, 'page'), 'name'));
        $this->assertSame(['Terms'], array_column(\FolderFolio::getTree($legal->id)[0]['children'] ?? [], 'name'));
        $this->assertSame([], \FolderFolio::getTree(), 'media has none of them');

        \FolderFolio::setService(null);
    }
}
