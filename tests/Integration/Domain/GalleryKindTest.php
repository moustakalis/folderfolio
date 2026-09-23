<?php

namespace FolderFolio\Tests\Integration\Domain;

use FolderFolio\Database\Schema;
use FolderFolio\Domain\AttachmentFolderRepository;
use FolderFolio\Domain\Folder;
use FolderFolio\Domain\FolderExport;
use FolderFolio\Domain\FolderKinds;
use FolderFolio\Domain\FolderLocks;
use FolderFolio\Domain\FolderRepository;
use FolderFolio\Domain\FolderService;
use FolderFolio\Domain\FolderSorts;
use FolderFolio\Support\UploadRouter;
use FolderFolio\Support\UploadTarget;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A Gallery folder kind — tier 3 item 14, board KZsHhrffzKQYqUjTvdFszK (14a):
 * images only, refused wherever files are added and said why; marked; its
 * files opening in the order arranged by hand. No Collection kind.
 */
class GalleryKindTest extends WP_UnitTestCase
{
    private FolderService $service;

    /** @var array<string, int> */
    private array $files = [];

    public function setUp(): void
    {
        parent::setUp();
        (new Schema())->migrate();

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_attachment_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folder_meta");

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->service = new FolderService();

        foreach (['beach' => 'image/jpeg', 'logo' => 'image/png', 'brief' => 'application/pdf', 'clip' => 'video/mp4'] as $key => $mime) {
            $this->files[$key] = self::factory()->attachment->create(['post_mime_type' => $mime, 'post_title' => $key]);
        }
    }

    public function tearDown(): void
    {
        unset($_REQUEST[UploadTarget::PARAM]);
        remove_all_filters('wp_handle_upload_prefilter');
        remove_all_filters('folderfolio_default_folder_for_upload');
        remove_all_actions('add_attachment');

        parent::tearDown();
    }

    private function folder(string $name, ?int $parent = null): Folder
    {
        $folder = $this->service->create(['name' => $name, 'parent_id' => $parent]);
        $this->assertInstanceOf(Folder::class, $folder);

        return $folder;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function node(int $id): ?array
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

        return $walk($this->service->tree());
    }

    /** @test */
    public function a_gallery_is_marked_and_opens_in_the_order_arranged_by_hand(): void
    {
        $hero = $this->folder('Hero shots');
        $named = $this->folder('Named');
        $this->assertTrue((new FolderSorts())->set($named->id, 'files', 'name-asc'));

        $this->assertSame('folder', $this->node($hero->id)['kind'], 'every node says its kind');

        $this->assertInstanceOf(Folder::class, $this->service->setKind($hero->id, FolderKinds::GALLERY));
        $this->assertInstanceOf(Folder::class, $this->service->setKind($named->id, FolderKinds::GALLERY));

        $this->assertSame('gallery', $this->node($hero->id)['kind']);
        $this->assertSame('custom', $this->node($hero->id)['sort_files']);
        $this->assertSame('name-asc', $this->node($named->id)['sort_files'], 'an order already chosen is kept');

        $this->assertInstanceOf(Folder::class, $this->service->setKind($hero->id, FolderKinds::FOLDER));
        $this->assertSame('folder', $this->node($hero->id)['kind']);
        $this->assertSame('custom', $this->node($hero->id)['sort_files'], 'going back leaves the order alone');
    }

    /** @test */
    public function a_gallery_takes_images_and_refuses_the_rest_with_a_sentence(): void
    {
        $hero = $this->folder('Hero shots');
        $this->service->setKind($hero->id, FolderKinds::GALLERY);

        $this->assertSame(2, $this->service->assignAttachments($hero->id, [$this->files['beach'], $this->files['logo']]));

        $one = $this->service->assignAttachments($hero->id, [$this->files['brief']]);
        $this->assertWPError($one);
        $this->assertSame('folderfolio_gallery_images_only', $one->get_error_code());
        $this->assertStringContainsString('“Hero shots” is a gallery', $one->get_error_message());
        $this->assertStringContainsString('That file is not an image', $one->get_error_message());

        // All or nothing, as every batch is: the image in it is not filed either.
        $mixed = $this->service->assignAttachments($hero->id, [$this->files['clip'], $this->files['brief'], $this->files['beach']], FolderService::MODE_MOVE);
        $this->assertWPError($mixed);
        $this->assertStringContainsString('2 of these 3 files are not images', $mixed->get_error_message());

        // A move into it is an add into it.
        $plain = $this->folder('Plain');
        $this->service->assignAttachments($plain->id, [$this->files['brief']]);
        $this->assertWPError($this->service->moveAttachments($plain->id, $hero->id, [$this->files['brief']]));
        $this->assertSame([$plain->id], array_map(static fn (Folder $f): int => $f->id, $this->service->foldersOf($this->files['brief'])));

        // A plain folder still takes anything.
        $this->assertSame(1, $this->service->assignAttachments($plain->id, [$this->files['clip']]));
    }

    /** @test */
    public function a_folder_holding_other_files_is_not_made_a_gallery(): void
    {
        $mixed = $this->folder('Campaign');
        $this->service->assignAttachments($mixed->id, [$this->files['beach'], $this->files['brief'], $this->files['clip']]);

        $refused = $this->service->setKind($mixed->id, FolderKinds::GALLERY);
        $this->assertWPError($refused);
        $this->assertSame('folderfolio_gallery_has_files', $refused->get_error_code());
        $this->assertStringContainsString('holds 2 files that are not images', $refused->get_error_message());
        $this->assertSame('folder', $this->node($mixed->id)['kind']);
        $this->assertNull($this->node($mixed->id)['sort_files'], 'a refusal writes nothing');

        // Its subfolders are its subfolders' business: only its own files count.
        $child = $this->folder('Shots', $mixed->id);
        $this->service->assignAttachments($child->id, [$this->files['logo']]);
        $this->assertInstanceOf(Folder::class, $this->service->setKind($child->id, FolderKinds::GALLERY));
    }

    /** @test */
    public function only_media_folders_and_only_past_a_lock_for_those_who_may_lock(): void
    {
        $pages = $this->service->create(['name' => 'Landing', 'object_type' => 'page']);
        $this->assertInstanceOf(Folder::class, $pages);
        $this->assertSame('folderfolio_kind_media_only', $this->service->setKind($pages->id, FolderKinds::GALLERY)->get_error_code());
        $this->assertSame('folderfolio_kind_unknown', $this->service->setKind($pages->id, 'collection')->get_error_code());

        $vault = $this->folder('Vault');
        $this->service->mark($vault->id, FolderLocks::LOCKED, true);

        $user = new FolderService(
            new FolderRepository(),
            new AttachmentFolderRepository(),
            new FolderSorts(),
            new FolderLocks(static fn (): bool => false)
        );
        $this->assertSame('folderfolio_locked', $user->setKind($vault->id, FolderKinds::GALLERY)->get_error_code());

        $locker = new FolderService(
            new FolderRepository(),
            new AttachmentFolderRepository(),
            new FolderSorts(),
            new FolderLocks(static fn (): bool => true)
        );
        $this->assertInstanceOf(Folder::class, $locker->setKind($vault->id, FolderKinds::GALLERY));
    }

    /** @test */
    public function a_copy_of_a_gallery_is_a_gallery_and_the_export_says_so(): void
    {
        $brand = $this->folder('Brand');
        $hero = $this->folder('Hero shots', $brand->id);
        $this->service->setKind($hero->id, FolderKinds::GALLERY);
        $this->service->assignAttachments($hero->id, [$this->files['beach']]);

        $copy = $this->service->duplicate($brand->id, null, true);
        $this->assertInstanceOf(Folder::class, $copy);
        $copiedHero = $this->service->children($copy->id)[0];

        $this->assertSame('gallery', $this->node($copiedHero->id)['kind']);
        $this->assertSame('folder', $this->node($copy->id)['kind']);
        $this->assertSame([$this->files['beach']], $this->service->attachmentIds($copiedHero->id));

        $kinds = array_column((new FolderExport())->document()['folders'], 'kind', 'name');
        $this->assertSame('gallery', $kinds['Hero shots']);
        $this->assertSame('folder', $kinds['Brand']);
    }

    /** @test */
    public function an_upload_into_a_gallery_is_refused_before_it_is_stored_unless_it_is_an_image(): void
    {
        $hero = $this->folder('Hero shots');
        $plain = $this->folder('Plain');
        $this->service->setKind($hero->id, FolderKinds::GALLERY);
        (new UploadTarget())->register();

        $dir = get_temp_dir();
        $pdf = $dir . 'folderfolio-brief.pdf';
        file_put_contents($pdf, "%PDF-1.4\n%âãÏÓ\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $png = $dir . 'folderfolio-dot.png';
        file_put_contents($png, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        $upload = static fn (string $path, string $name): array => apply_filters(
            'wp_handle_upload_prefilter',
            ['name' => $name, 'tmp_name' => $path, 'type' => 'application/octet-stream', 'error' => 0, 'size' => filesize($path)]
        );

        $_REQUEST[UploadTarget::PARAM] = (string) $hero->id;
        $refused = $upload($pdf, 'brief.pdf');
        $this->assertIsString($refused['error']);
        $this->assertStringContainsString('“Hero shots” is a gallery', $refused['error']);
        $this->assertSame(0, $upload($png, 'dot.png')['error'], 'an image goes through');

        $_REQUEST[UploadTarget::PARAM] = (string) $plain->id;
        $this->assertSame(0, $upload($pdf, 'brief.pdf')['error'], 'a plain folder takes anything');

        unset($_REQUEST[UploadTarget::PARAM]);
        $this->assertSame(0, $upload($pdf, 'brief.pdf')['error'], 'no folder named, nothing asked');

        // And behind it, the filing: if something else stored the file
        // anyway, UploadRouter's assign is refused and files nothing.
        (new UploadRouter())->register();
        $_REQUEST[UploadTarget::PARAM] = (string) $hero->id;
        $late = self::factory()->attachment->create(['post_mime_type' => 'application/pdf']);
        $this->assertSame([], $this->service->foldersOf($late));

        unlink($pdf);
        unlink($png);
    }

    /** @test */
    public function the_route_is_organise(): void
    {
        $hero = $this->folder('Hero shots');

        $request = new WP_REST_Request('POST', "/folderfolio/v1/folders/{$hero->id}/kind");
        $request->set_body_params(['kind' => 'gallery']);

        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
        $this->assertSame(403, rest_do_request($request)->get_status());

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status());
        $this->assertTrue((new FolderKinds())->isGallery($hero->id));

        $bad = new WP_REST_Request('POST', "/folderfolio/v1/folders/{$hero->id}/kind");
        $bad->set_body_params(['kind' => 'collection']);
        $this->assertSame(400, rest_do_request($bad)->get_status());
    }
}
