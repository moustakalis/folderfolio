<?php

namespace FolderFolio\Tests\Integration\Admin;

use FolderFolio\Admin\ImportPage;
use FolderFolio\Admin\Rail;
use FolderFolio\Admin\SettingsPage;
use FolderFolio\Database\Schema;
use FolderFolio\Database\StatusReport;
use FolderFolio\Domain\Folder;
use FolderFolio\Domain\FolderService;
use FolderFolio\Support\Settings;
use WP_UnitTestCase;

/**
 * The settings screen says what the product does — 24 Sep, Nick: "Opens in"
 * still promised a × on the breadcrumb a month after it became two labelled
 * buttons, and the Status tab counted a filed page as a file.
 *
 * The sentences that name another screen's controls are held to that
 * screen's own strings, so renaming a button fails here rather than leaving a
 * description of something that is not there.
 */
class SettingsCopyTest extends WP_UnitTestCase
{
    private FolderService $service;

    public function setUp(): void
    {
        parent::setUp();
        (new Schema())->migrate();
        // Posts and Pages on, as a fresh site has them (PostFoldersTest does the same).
        delete_option(Settings::OPTION);

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_attachment_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folder_meta");

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->service = new FolderService();
    }

    public function tearDown(): void
    {
        unset($_GET['tab']);
        parent::tearDown();
    }

    private function page(string $tab): string
    {
        $_GET['tab'] = $tab;
        ob_start();
        (new SettingsPage(new ImportPage()))->renderPage();

        return (string) ob_get_clean();
    }

    /** @test */
    public function opens_in_names_the_crumb_row_s_real_controls(): void
    {
        $html = html_entity_decode($this->page('settings'), ENT_QUOTES | ENT_HTML5);
        $strings = Rail::strings();

        $this->assertStringContainsString(
            'with ' . $strings['clearFilter'] . ' beside it',
            $html,
            'the breadcrumb ends in Clear filter, and the sentence should say so'
        );
        $this->assertStringContainsString($strings['startHere'], $html, 'a person\'s own starting folder beats the site\'s');
        $this->assertStringNotContainsString('with a × beside it', $html);
    }

    /** @test */
    public function the_roles_note_names_each_screen_s_own_permission(): void
    {
        $html = html_entity_decode($this->page('settings'), ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('uploading files for media, editing posts for posts, editing pages for pages', $html);
        $this->assertStringNotContainsString('a tick here and the ability to upload files.', $html);
        $this->assertStringNotContainsString('sub-folders', $html, 'one spelling: subfolders');
    }

    /**
     * @return array<string, string>
     */
    private function status(): array
    {
        return array_column((new StatusReport())->rows(), 'value', 'label');
    }

    /** @test */
    public function a_media_only_site_reads_as_it_always_did(): void
    {
        $brand = $this->service->create(['name' => 'Brand']);
        $this->assertInstanceOf(Folder::class, $brand);
        $this->service->create(['name' => 'Logos', 'parent_id' => $brand->id]);
        $image = self::factory()->attachment->create(['post_mime_type' => 'image/png']);
        $this->service->assignAttachments($brand->id, [$image]);

        $rows = $this->status();

        $this->assertSame('2', $rows['Folders']);
        $this->assertSame('1 file, filed 1 time', $rows['Files in folders']);
        $this->assertArrayNotHasKey('Filed on other screens', $rows);
    }

    /** @test */
    public function a_filed_page_is_not_counted_as_a_file_and_each_tree_is_named(): void
    {
        $brand = $this->service->create(['name' => 'Brand']);
        $image = self::factory()->attachment->create(['post_mime_type' => 'image/png']);
        $this->service->assignAttachments($brand->id, [$image]);

        $landing = $this->service->create(['name' => 'Landing', 'object_type' => 'page']);
        $this->assertInstanceOf(Folder::class, $landing, is_wp_error($landing) ? $landing->get_error_message() : '');
        $year = $this->service->create(['name' => '2024', 'parent_id' => $landing->id, 'object_type' => 'page']);
        $this->assertInstanceOf(Folder::class, $year);
        $pages = self::factory()->post->create_many(2, ['post_type' => 'page']);
        $this->service->assignAttachments($year->id, $pages);

        $rows = $this->status();

        $this->assertSame('3 · Media 1 · Pages 2', $rows['Folders']);
        $this->assertSame('1 file, filed 1 time', $rows['Files in folders'], 'pages are not files');
        $this->assertSame('Pages 2', $rows['Filed on other screens']);
        $this->assertSame('2 levels down: Landing › 2024 (Pages)', $rows['Deepest folder']);
    }
}
