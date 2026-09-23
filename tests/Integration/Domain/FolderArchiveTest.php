<?php

namespace FolderFolio\Tests\Integration\Domain;

use FolderFolio\Admin\FolderDownload;
use FolderFolio\Database\Schema;
use FolderFolio\Domain\FolderArchive;
use FolderFolio\Domain\FolderService;
use FolderFolio\Support\Capabilities;
use FolderFolio\Support\Settings;
use WP_REST_Request;
use WP_UnitTestCase;
use ZipArchive;

/**
 * Download a folder as a ZIP — tier 2 item 11, against a real WordPress.
 *
 * Nick's answers (board PpiAmXsixk3sG9yygJQnw5): the folder and its
 * subfolders as directories, a file filed twice in both places, the original
 * upload, and what cannot go in named in not-included.txt — every one read
 * back out of the archive the download would send.
 */
class FolderArchiveTest extends WP_UnitTestCase
{
    private FolderService $service;

    private string $dir;

    /** @var list<string> */
    private array $made = [];

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
        $this->dir = wp_get_upload_dir()['basedir'] . '/ff-zip';
        wp_mkdir_p($this->dir);
    }

    public function tearDown(): void
    {
        foreach ($this->made as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    /** An attachment with a real file under uploads/ff-zip. */
    private function file(string $name, string $bytes, int $parentPost = 0): int
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $bytes);
        $this->made[] = $path;

        $id = self::factory()->attachment->create_object([
            'file' => $path,
            'post_mime_type' => 'image/jpeg',
            'post_title' => $name,
            'post_parent' => $parentPost,
        ]);
        update_post_meta($id, '_wp_attached_file', 'ff-zip/' . $name);

        return $id;
    }

    private function folder(string $name, ?int $parent = null): int
    {
        return $this->service->create(['name' => $name, 'parent_id' => $parent])->id;
    }

    /**
     * The archive the download would send, opened.
     *
     * @param array<string, mixed> $manifest
     */
    private function zip(array $manifest): ZipArchive
    {
        $bytes = '';
        (new FolderArchive())->write($manifest, static function (string $chunk) use (&$bytes): void {
            $bytes .= $chunk;
        });

        $this->assertSame($manifest['length'], strlen($bytes), 'The Content-Length promised is the length sent.');

        $path = $this->dir . '/out-' . wp_generate_password(6, false) . '.zip';
        file_put_contents($path, $bytes);
        $this->made[] = $path;

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CHECKCONS));

        return $zip;
    }

    /** @return list<string> */
    private function names(ZipArchive $zip): array
    {
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        sort($names);

        return $names;
    }

    public function test_the_folder_and_its_subfolders_arrive_as_directories(): void
    {
        $brand = $this->folder('Brand');
        $logos = $this->folder('Logos', $brand);
        $this->folder('Empty', $brand);

        $guide = $this->file('guide.pdf', 'GUIDE');
        $hero = $this->file('hero.jpg', 'HERO');
        $this->service->assignAttachments($brand, [$guide]);
        // Filed twice: in the archive twice, once where each filing is.
        $this->service->assignAttachments($brand, [$hero]);
        $this->service->assignAttachments($logos, [$hero]);

        $manifest = (new FolderArchive())->manifest($brand);
        $zip = $this->zip($manifest);

        $this->assertSame(
            ['Brand/', 'Brand/Empty/', 'Brand/Logos/', 'Brand/Logos/hero.jpg', 'Brand/guide.pdf', 'Brand/hero.jpg'],
            $this->names($zip)
        );
        $this->assertSame('HERO', $zip->getFromName('Brand/Logos/hero.jpg'));
        $this->assertSame('GUIDE', $zip->getFromName('Brand/guide.pdf'));
        $this->assertSame(3, $manifest['files']);
        $this->assertSame('Brand.zip', $manifest['filename']);
        $zip->close();
    }

    public function test_what_cannot_go_in_is_named_and_what_may_not_be_read_is_only_counted(): void
    {
        $brand = $this->folder('Brand');

        $kept = $this->file('kept.jpg', 'KEPT');
        $gone = $this->file('gone.jpg', 'GONE');
        unlink($this->dir . '/gone.jpg');

        // A stored path that climbs out of uploads to a file that exists —
        // WordPress's own index.php. The archive holds media only.
        $outside = $this->file('outside.jpg', 'X');
        update_post_meta($outside, '_wp_attached_file', 'ff-zip/../../../index.php');
        $this->assertFileExists(get_attached_file($outside));

        // Attached to someone else's private post: not the Author's to read.
        $private = self::factory()->post->create(['post_status' => 'private', 'post_author' => get_current_user_id()]);
        $hidden = $this->file('secret-plan.jpg', 'SECRET', $private);

        $this->service->assignAttachments($brand, [$kept, $gone, $outside, $hidden]);

        wp_set_current_user(self::factory()->user->create(['role' => 'author']));

        $manifest = (new FolderArchive())->manifest($brand);
        $zip = $this->zip($manifest);
        $note = (string) $zip->getFromName('Brand/not-included.txt');

        $this->assertSame(['Brand/', 'Brand/kept.jpg', 'Brand/not-included.txt'], $this->names($zip));
        $this->assertStringContainsString('Brand/gone.jpg — the file is missing from the server', $note);
        $this->assertStringContainsString('Brand/index.php — the file is stored outside the uploads folder', $note);
        $this->assertStringContainsString('1 file you do not have permission to view.', $note);
        $this->assertStringNotContainsString('secret', $note, 'A file this person may not read is not named.');
        $this->assertSame(1, $manifest['files']);
        $this->assertSame(3, $manifest['left_out']);
        $zip->close();
    }

    public function test_the_original_upload_goes_in_rather_than_the_scaled_copy(): void
    {
        $brand = $this->folder('Brand');
        $photo = $this->file('photo-scaled.jpg', 'SCALED');
        file_put_contents($this->dir . '/photo.jpg', 'ORIGINAL');
        $this->made[] = $this->dir . '/photo.jpg';
        wp_update_attachment_metadata($photo, ['file' => 'ff-zip/photo-scaled.jpg', 'original_image' => 'photo.jpg']);
        $this->service->assignAttachments($brand, [$photo]);

        $zip = $this->zip((new FolderArchive())->manifest($brand));

        $this->assertSame('ORIGINAL', $zip->getFromName('Brand/photo.jpg'));
        $this->assertFalse($zip->statName('Brand/photo-scaled.jpg'));
        $zip->close();
    }

    public function test_a_checksum_is_kept_and_a_resume_is_that_range_of_the_whole(): void
    {
        $brand = $this->folder('Brand');
        $a = $this->file('a.jpg', random_bytes(5000));
        $this->service->assignAttachments($brand, [$a, $this->file('b.jpg', random_bytes(3000))]);

        $archive = new FolderArchive();
        $first = $archive->manifest($brand);
        $this->assertNull($first['entries'][1]['crc'] ?? null, 'Nothing is known the first time.');

        $whole = '';
        $archive->write($first, static function (string $chunk) use (&$whole): void {
            $whole .= $chunk;
        });

        // The CRC read while writing is kept, keyed to the file's size and time.
        $again = $archive->manifest($brand);
        $this->assertIsInt($again['entries'][1]['crc'] ?? null);
        $this->assertSame($first['etag'], $again['etag'], 'The same folder and files are the same archive.');

        foreach ([7, 2600, strlen($whole) - 30] as $from) {
            $part = '';
            $archive->write($again, static function (string $chunk) use (&$part): void {
                $part .= $chunk;
            }, $from);
            $this->assertSame(substr($whole, $from), $part, "Resuming at byte {$from}.");
        }
    }

    public function test_the_summary_route_says_the_size_and_asks_above_the_threshold(): void
    {
        $brand = $this->folder('Brand');
        $this->service->assignAttachments($brand, [$this->file('a.jpg', str_repeat('x', 2048))]);

        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
        $this->assertTrue(Capabilities::can('download'), 'An Author downloads by default.');

        $get = static fn (): \WP_REST_Response => rest_do_request(new WP_REST_Request('GET', "/folderfolio/v1/folders/{$brand}/zip"));

        $data = $get()->get_data()['data'];
        $this->assertSame(1, $data['files']);
        $this->assertSame(2048, $data['bytes']);
        $this->assertFalse($data['confirm']);
        $this->assertNull($data['refused']);
        $this->assertStringContainsString('action=' . FolderDownload::ACTION, (string) $data['url']);

        add_filter('folderfolio_zip_confirm_bytes', static fn (): int => 1024);
        $this->assertTrue($get()->get_data()['data']['confirm']);

        add_filter('folderfolio_zip_max_bytes', static fn (): int => 1024);
        $refused = $get()->get_data()['data'];
        $this->assertIsString($refused['refused']);
        $this->assertNull($refused['url']);

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertSame(403, $get()->get_status());
    }

    public function test_a_matrix_saved_before_download_gives_it_by_default(): void
    {
        // Saved from the settings screen before the column existed: no
        // `abilities` key, and no Download in any row.
        update_option(Settings::OPTION, ['roles' => [
            'administrator' => ['create', 'rename', 'delete', 'assign', 'lock'],
            'editor' => ['create', 'rename', 'delete', 'assign'],
            'author' => ['create', 'assign'],
        ]]);

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->assertTrue(Capabilities::can('download'));

        // Saved with the column showing and Download unticked: it stays off.
        Settings::save(['roles' => ['editor' => ['create' => '1']]]);
        $this->assertFalse(Capabilities::can('download'));

        delete_option(Settings::OPTION);
    }
}
