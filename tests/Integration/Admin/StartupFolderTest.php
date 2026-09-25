<?php

namespace FolderFolio\Tests\Integration\Admin;

use FolderFolio\Admin\MediaLibraryFilter;
use FolderFolio\Admin\RailPreferences;
use FolderFolio\Admin\StartupFolder;
use FolderFolio\Database\Schema;
use FolderFolio\Support\Settings;
use RuntimeException;
use WP_UnitTestCase;

/**
 * The startup folder's redirect, on the server — tier 1's standing debt.
 *
 * The e2e suite drives it through a browser; this holds each of the
 * conditions on its own, so a reversion names the rule it broke. The
 * redirect is observed on `wp_redirect` and stopped there with an exception,
 * because the real one ends in `exit`.
 */
class StartupFolderTest extends WP_UnitTestCase
{
    private int $admin;

    /** @var array<string, mixed> */
    private array $server;

    /** @var array<string, mixed> */
    private array $get;

    public function setUp(): void
    {
        parent::setUp();

        (new Schema())->migrate();
        delete_option(Settings::OPTION);

        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);

        $this->server = $_SERVER;
        $this->get = $_GET;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/wp-admin/upload.php?mode=grid';
        $_GET = ['mode' => 'grid'];

        add_filter('wp_redirect', [$this, 'stop'], 1, 2);
    }

    public function tearDown(): void
    {
        remove_filter('wp_redirect', [$this, 'stop'], 1);
        $_SERVER = $this->server;
        $_GET = $this->get;

        parent::tearDown();
    }

    public function stop(string $location, int $status): never
    {
        throw new RuntimeException($status . ' ' . $location);
    }

    /**
     * Where the screen would send this request, or null to stay.
     */
    private function destination(): ?string
    {
        try {
            (new StartupFolder())->maybeRedirect();
        } catch (RuntimeException $redirect) {
            return $redirect->getMessage();
        }

        return null;
    }

    private function siteStarts(?int $folderId): void
    {
        Settings::save(['startup_folder' => $folderId] + Settings::get());
    }

    public function test_no_startup_folder_leaves_the_library_alone(): void
    {
        self::assertNull($this->destination());
    }

    public function test_a_bare_arrival_goes_to_the_sites_folder_keeping_the_rest_of_the_url(): void
    {
        $folder = \FolderFolio::createFolder('Launch');
        $this->siteStarts($folder->id);

        $to = (string) $this->destination();

        self::assertStringStartsWith('302 ', $to, 'a 301 would be cached past the setting');
        parse_str((string) parse_url(substr($to, 4), PHP_URL_QUERY), $query);
        self::assertSame((string) $folder->id, $query[MediaLibraryFilter::QUERY_VAR] ?? null);
        self::assertSame('1', $query[StartupFolder::QUERY_VAR] ?? null, 'the marker the sentence reads');
        self::assertSame('grid', $query['mode'] ?? null, 'the mode survives');
    }

    // The key present — even empty, which is how "all media" is spelled —
    // means the person chose; the redirect never overrides a choice.
    public function test_a_request_that_names_a_folder_or_all_media_is_not_redirected(): void
    {
        $folder = \FolderFolio::createFolder('Launch');
        $this->siteStarts($folder->id);

        $_GET[MediaLibraryFilter::QUERY_VAR] = '';
        self::assertNull($this->destination(), 'all media, chosen');

        $_GET[MediaLibraryFilter::QUERY_VAR] = '0';
        self::assertNull($this->destination(), 'Unassigned, chosen');
    }

    public function test_the_persons_own_folder_beats_the_sites(): void
    {
        $site = \FolderFolio::createFolder('Site');
        $mine = \FolderFolio::createFolder('Mine');
        $this->siteStarts($site->id);
        RailPreferences::save($this->admin, ['startup' => $mine->id] + RailPreferences::forUser($this->admin));

        self::assertStringContainsString(
            MediaLibraryFilter::QUERY_VAR . '=' . $mine->id,
            (string) $this->destination()
        );
    }

    public function test_unassigned_is_a_destination_not_off(): void
    {
        $this->siteStarts(0);

        self::assertStringContainsString(MediaLibraryFilter::QUERY_VAR . '=0', (string) $this->destination());
    }

    public function test_a_deleted_folder_sends_nobody_anywhere(): void
    {
        $folder = \FolderFolio::createFolder('Gone');
        $this->siteStarts($folder->id);
        \FolderFolio::deleteFolder($folder->id);

        self::assertNull($this->destination());
    }

    public function test_a_post_ajax_or_rest_request_is_never_redirected(): void
    {
        $folder = \FolderFolio::createFolder('Launch');
        $this->siteStarts($folder->id);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        self::assertNull($this->destination(), 'an upload or a bulk action would lose its body');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        add_filter('wp_doing_ajax', '__return_true');
        self::assertNull($this->destination(), 'ajax');
        remove_filter('wp_doing_ajax', '__return_true');

        self::assertNotNull($this->destination(), 'the same request as a plain GET does redirect');
    }

    public function test_someone_who_cannot_use_folders_is_not_sent_into_one(): void
    {
        $folder = \FolderFolio::createFolder('Launch');
        $this->siteStarts($folder->id);

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        self::assertNull($this->destination());
    }

    public function test_it_is_hooked_on_the_media_library_screen_only(): void
    {
        $startup = new StartupFolder();
        $startup->register();

        self::assertSame(10, has_action('load-upload.php', [$startup, 'maybeRedirect']));
        self::assertFalse(has_action('load-edit.php', [$startup, 'maybeRedirect']), 'not the post lists');

        remove_action('load-upload.php', [$startup, 'maybeRedirect']);
    }
}
