<?php

namespace FolderFolio\Tests\Integration\Admin;

use FolderFolio\Admin\FolderViews;
use FolderFolio\Admin\MediaLibraryFilter;
use FolderFolio\Database\Schema;
use WP_UnitTestCase;

/**
 * Review #24 (Nick, 25 Sep): inside a folder, the status line above a post
 * list counts the folder, keeps it in every link, and leads with whose counts
 * they are. Outside a folder it is core's, untouched.
 */
class FolderViewsTest extends WP_UnitTestCase
{
    private int $admin;

    public function set_up(): void
    {
        parent::set_up();

        (new Schema())->migrate();
        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
    }

    public function tear_down(): void
    {
        unset($_GET[MediaLibraryFilter::QUERY_VAR], $_GET['author'], $_REQUEST['post_status'], $_REQUEST['show_sticky']);
        delete_option('sticky_posts');

        parent::tear_down();
    }

    /** Core's line for a site of 12 posts, as the filter receives it. */
    private function core(): array
    {
        return [
            'all' => '<a href="edit.php?post_type=post">All <span class="count">(12)</span></a>',
            'publish' => '<a href="edit.php?post_status=publish&#038;post_type=post">Published <span class="count">(10)</span></a>',
            'draft' => '<a href="edit.php?post_status=draft&#038;post_type=post">Draft <span class="count">(2)</span></a>',
        ];
    }

    /**
     * @return array{folder: int, ids: array<string, int>}
     */
    private function launch(): array
    {
        $folder = \FolderFolio::createFolder('Launch', null, 'post');
        $this->assertNotWPError($folder);

        $ids = [
            'a' => self::factory()->post->create(['post_status' => 'publish']),
            'b' => self::factory()->post->create(['post_status' => 'publish']),
            'c' => self::factory()->post->create(['post_status' => 'draft']),
        ];

        // Nine more outside the folder: the site has 12.
        self::factory()->post->create_many(8, ['post_status' => 'publish']);
        self::factory()->post->create(['post_status' => 'draft']);

        $this->assertSame(3, \FolderFolio::assign(array_values($ids), $folder->id));

        return ['folder' => $folder->id, 'ids' => $ids];
    }

    /** @test */
    public function without_a_folder_the_line_is_cores(): void
    {
        $this->launch();

        $this->assertSame($this->core(), (new FolderViews())->filter($this->core(), 'post'));
    }

    /** @test */
    public function inside_a_folder_the_counts_are_the_folders_and_the_links_keep_it(): void
    {
        $launch = $this->launch();
        $_GET[MediaLibraryFilter::QUERY_VAR] = (string) $launch['folder'];

        $views = (new FolderViews())->filter($this->core(), 'post');

        $this->assertSame(['all', 'publish', 'draft'], array_keys($views));
        $this->assertStringContainsString('All <span class="count">(3)</span>', $views['all']);
        $this->assertStringContainsString('<span class="count">(2)</span>', $views['publish']);
        $this->assertStringContainsString('<span class="count">(1)</span>', $views['draft']);

        foreach ($views as $key => $html) {
            $this->assertMatchesRegularExpression('/href="[^"]*folderfolio_folder=' . $launch['folder'] . '/', $html, "{$key} keeps the folder");
        }

        $this->assertStringContainsString('post_status=draft', $views['draft']);
        $this->assertStringContainsString('class="current"', $views['all'], 'No status asked for: All is the current view.');
    }

    /** @test */
    public function the_line_leads_with_the_folder_as_text_inside_the_all_item(): void
    {
        $launch = $this->launch();
        $_GET[MediaLibraryFilter::QUERY_VAR] = (string) $launch['folder'];

        $all = (new FolderViews())->filter($this->core(), 'post')['all'];

        // Inside the first item, before its link — an item of its own would
        // get a stray " |" after it (trap 128).
        $this->assertMatchesRegularExpression('/^<span class="folderfolio-views-in">.*<\/span> <a /s', $all);
        $this->assertSame('In Launch: All (3)', trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($all))));
        $this->assertStringContainsString('aria-hidden="true"', $all, 'The glyph is decoration; the words carry it.');
    }

    /** @test */
    public function a_status_with_nothing_in_the_folder_drops_out_and_the_current_one_is_marked(): void
    {
        $folder = \FolderFolio::createFolder('Drafts only', null, 'post');
        \FolderFolio::assign([self::factory()->post->create(['post_status' => 'draft'])], $folder->id);
        self::factory()->post->create(['post_status' => 'publish']);

        $_GET[MediaLibraryFilter::QUERY_VAR] = (string) $folder->id;
        $_REQUEST['post_status'] = 'draft';

        $views = (new FolderViews())->filter($this->core(), 'post');

        $this->assertSame(['all', 'draft'], array_keys($views), 'Published has nothing in this folder.');
        $this->assertStringContainsString('class="current"', $views['draft']);
        $this->assertStringNotContainsString('class="current"', $views['all']);
    }

    /** @test */
    public function unassigned_counts_the_posts_filed_nowhere(): void
    {
        $this->launch();
        $_GET[MediaLibraryFilter::QUERY_VAR] = '0';

        $views = (new FolderViews())->filter($this->core(), 'post');

        $this->assertStringContainsString('In Unassigned:', wp_strip_all_tags($views['all']));
        $this->assertStringContainsString('All <span class="count">(9)</span>', $views['all']);
    }

    /** @test */
    public function mine_and_sticky_follow_cores_rules_inside_the_folder(): void
    {
        $launch = $this->launch();
        $author = self::factory()->user->create(['role' => 'editor']);
        $theirs = self::factory()->post->create(['post_status' => 'publish', 'post_author' => $author]);
        \FolderFolio::assign([$theirs], $launch['folder']);
        wp_update_post(['ID' => $launch['ids']['a'], 'post_author' => $this->admin]);
        wp_update_post(['ID' => $launch['ids']['b'], 'post_author' => $this->admin]);
        wp_update_post(['ID' => $launch['ids']['c'], 'post_author' => $this->admin]);
        stick_post($launch['ids']['a']);

        $_GET[MediaLibraryFilter::QUERY_VAR] = (string) $launch['folder'];

        $views = (new FolderViews())->filter($this->core(), 'post');

        $this->assertSame(['all', 'mine', 'publish', 'sticky', 'draft'], array_keys($views));
        $this->assertStringContainsString('(4)', $views['all']);
        $this->assertStringContainsString('(3)', $views['mine']);
        $this->assertStringContainsString('(1)', $views['sticky']);
        $this->assertStringContainsString('all_posts=1', $views['all'], 'As core: All says it is everyone\'s when Mine is offered.');
    }

    /** @test */
    public function someone_who_cannot_read_private_posts_counts_only_their_own(): void
    {
        $launch = $this->launch();
        \FolderFolio::assign([self::factory()->post->create(['post_status' => 'private', 'post_author' => $this->admin])], $launch['folder']);

        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);
        $_GET[MediaLibraryFilter::QUERY_VAR] = (string) $launch['folder'];

        $views = (new FolderViews())->filter($this->core(), 'post');

        $this->assertArrayNotHasKey('private', $views);
        $this->assertStringContainsString('(3)', $views['all']);
    }

    /** @test */
    public function a_folder_of_another_type_leaves_cores_line(): void
    {
        $media = \FolderFolio::createFolder('Photos');
        $_GET[MediaLibraryFilter::QUERY_VAR] = (string) $media->id;

        $this->assertSame($this->core(), (new FolderViews())->filter($this->core(), 'post'));
    }
}
