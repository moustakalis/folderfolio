<?php

namespace FolderFolio\Tests\Integration\Blocks;

use FolderFolio\Blocks\GalleryQuery;
use FolderFolio\Database\Schema;
use WP_UnitTestCase;

/**
 * What a gallery can and cannot show.
 *
 * This is the plugin's only unauthenticated surface: everything else is behind
 * `upload_files`, and this runs for whoever is reading the page. So the tests
 * are written from the visitor's side — logged out, no capabilities — and they
 * assert the thing the docs promise in those words: **folders are not access
 * control**. Filing a private site's attachment into a folder must not publish
 * it, and a gallery must not become a way to enumerate media a theme would
 * never show.
 */
class GalleryQueryTest extends WP_UnitTestCase
{
    private int $folder;

    public function setUp(): void
    {
        parent::setUp();

        (new Schema())->migrate();

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_attachment_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folders");

        // The fixture is built as somebody who is allowed to build it. Filing
        // media is `upload_files` plus `edit_post` per attachment, so a run
        // with no user gets a WP_Error from `assign()` and a test that fails
        // for the wrong reason — which is how this was written the first time.
        // Every assertion below then switches to the visitor.
        wp_set_current_user($this->factory->user->create(['role' => 'administrator']));

        $folder = \FolderFolio::createFolder('Gallery');
        $this->assertNotWPError($folder);
        $this->folder = $folder->id;
    }

    /**
     * An attachment that behaves like one.
     *
     * The factory alone makes a row with no `_wp_attached_file`, so
     * `wp_get_attachment_image()` returns an empty string and the renderer —
     * correctly — skips it as a non-image. Half an hour went into that the
     * first time.
     */
    private function attachment(int $parent): int
    {
        $id = $this->factory->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_parent' => $parent,
            'post_mime_type' => 'image/jpeg',
        ]);

        update_post_meta($id, '_wp_attached_file', 'folderfolio-test.jpg');
        wp_update_attachment_metadata($id, [
            'width' => 600,
            'height' => 400,
            'file' => 'folderfolio-test.jpg',
            'sizes' => [],
        ]);

        return $id;
    }

    /** An attachment on a published post: the ordinary case. */
    private function publicImage(): int
    {
        return $this->attachment($this->factory->post->create(['post_status' => 'publish']));
    }

    /**
     * @test
     */
    public function a_gallery_shows_the_folder_it_was_given(): void
    {
        $image = $this->publicImage();
        \FolderFolio::assign([$image], $this->folder);

        wp_set_current_user(0);

        $found = (new GalleryQuery())->attachments(['folderIds' => [$this->folder]]);

        $this->assertSame([$image], array_map(static fn ($post): int => $post->ID, $found));
    }

    /**
     * @test
     */
    public function a_gallery_does_not_publish_an_attachment_on_a_private_post(): void
    {
        $hidden = $this->attachment($this->factory->post->create(['post_status' => 'private']));
        $visible = $this->publicImage();

        \FolderFolio::assign([$hidden, $visible], $this->folder);

        // The visitor. No user, no capabilities, which is who this code path
        // actually runs for.
        wp_set_current_user(0);

        $found = array_map(
            static fn ($post): int => $post->ID,
            (new GalleryQuery())->attachments(['folderIds' => [$this->folder]])
        );

        $this->assertContains($visible, $found);
        $this->assertNotContains(
            $hidden,
            $found,
            'Filing an attachment into a folder does not make it public.'
        );
    }

    /**
     * @test
     */
    public function an_administrator_sees_the_same_gallery_a_visitor_does(): void
    {
        $hidden = $this->attachment($this->factory->post->create(['post_status' => 'private']));

        \FolderFolio::assign([$hidden], $this->folder);

        wp_set_current_user($this->factory->user->create(['role' => 'administrator']));

        // Deliberate: a gallery is page content, and page content cannot mean
        // one thing for the author and another for everybody else. An editor
        // who could see an extra image here would publish a page that does not
        // contain it, which is the worst version of this bug — invisible to
        // the person who could fix it.
        $found = array_map(
            static fn ($post): int => $post->ID,
            (new GalleryQuery())->attachments(['folderIds' => [$this->folder]])
        );

        $this->assertNotContains($hidden, $found);
    }

    /**
     * @test
     */
    public function nothing_comes_back_for_a_folder_that_does_not_exist(): void
    {
        $this->assertSame([], (new GalleryQuery())->attachments(['folderIds' => [999999]]));
        $this->assertSame([], (new GalleryQuery())->attachments(['folderIds' => []]));
        $this->assertSame([], (new GalleryQuery())->attachments([]));
    }

    /**
     * @test
     */
    public function subfolders_are_included_only_when_asked(): void
    {
        $child = \FolderFolio::createFolder('Below', $this->folder);
        $this->assertNotWPError($child);

        $top = $this->publicImage();
        $below = $this->publicImage();

        \FolderFolio::assign([$top], $this->folder);
        \FolderFolio::assign([$below], $child->id);

        $ids = static fn (array $posts): array => array_map(
            static fn ($post): int => $post->ID,
            $posts
        );

        $query = new GalleryQuery();

        $this->assertSame(
            [$top],
            $ids($query->attachments(['folderIds' => [$this->folder]]))
        );

        $with = $ids($query->attachments([
            'folderIds' => [$this->folder],
            'includeDescendants' => true,
        ]));

        sort($with);
        $expected = [$top, $below];
        sort($expected);

        $this->assertSame($expected, $with);
    }

    /**
     * @test
     */
    public function the_limit_is_capped_however_large_it_is_asked_to_be(): void
    {
        $images = [];

        for ($i = 0; $i < 5; $i++) {
            $images[] = $this->publicImage();
        }

        \FolderFolio::assign($images, $this->folder);

        $found = (new GalleryQuery())->attachments([
            'folderIds' => [$this->folder],
            'limit' => 3,
        ]);

        $this->assertCount(3, $found);

        // A page is not an export. GalleryQuery::MAX is the ceiling whatever
        // the block asks for, and `limit: 0` means "the folder", not
        // "everything ever uploaded".
        $this->assertLessThanOrEqual(
            GalleryQuery::MAX,
            count((new GalleryQuery())->attachments([
                'folderIds' => [$this->folder],
                'limit' => 100000,
            ]))
        );
    }
}
