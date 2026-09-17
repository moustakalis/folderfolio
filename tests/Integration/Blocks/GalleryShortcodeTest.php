<?php

namespace FolderFolio\Tests\Integration\Blocks;

use FolderFolio\Blocks\GalleryShortcode;
use FolderFolio\Database\Schema;
use WP_UnitTestCase;

/**
 * The shortcode: a path in, the block's own markup out.
 *
 * The assertion that matters is the last one. A shortcode is typed by hand, so
 * it will be typed wrong, and a typo must not write anything — `findByPath()`,
 * never `getOrCreateByPath()`.
 */
class GalleryShortcodeTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        (new Schema())->migrate();

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_attachment_folders");
        $wpdb->query("DELETE FROM {$wpdb->prefix}folderfolio_folders");

        // Filing media needs a user who may file media; see GalleryQueryTest.
        wp_set_current_user($this->factory->user->create(['role' => 'administrator']));
    }

    /**
     * An attachment that behaves like one — with a file, so that
     * `wp_get_attachment_image()` has something to render. Without the meta
     * the renderer skips it as a non-image, which is right in production and
     * baffling in a test.
     */
    private function image(): int
    {
        $id = $this->factory->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_parent' => $this->factory->post->create(['post_status' => 'publish']),
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

    /**
     * @test
     */
    public function it_resolves_a_nested_path(): void
    {
        $brand = \FolderFolio::createFolder('Brand');
        $logos = \FolderFolio::createFolder('Logos', $brand->id);
        \FolderFolio::assign([$this->image()], $logos->id);

        $html = (new GalleryShortcode())->render([
            'folder' => 'Brand/Logos',
            'columns' => '4',
        ]);

        $this->assertStringContainsString('wp-block-folderfolio-gallery', $html);
        $this->assertStringContainsString('--ff-gallery-columns:4', $html);
        $this->assertSame(1, substr_count($html, '<li'));
    }

    /**
     * @test
     */
    public function an_unknown_path_renders_nothing_and_creates_nothing(): void
    {
        $before = count(\FolderFolio::getTree());

        $html = (new GalleryShortcode())->render(['folder' => 'Brnad/Logos']);

        $this->assertSame('', $html);
        $this->assertCount(
            $before,
            \FolderFolio::getTree(),
            'A typo in a shortcode must never create a folder.'
        );
    }

    /**
     * @test
     */
    public function masonry_and_subfolders_come_through(): void
    {
        $top = \FolderFolio::createFolder('Top');
        $under = \FolderFolio::createFolder('Under', $top->id);
        \FolderFolio::assign([$this->image()], $under->id);

        $html = (new GalleryShortcode())->render([
            'folder' => 'Top',
            'subfolders' => 'yes',
            'layout' => 'masonry',
        ]);

        $this->assertStringContainsString('folderfolio-gallery--masonry', $html);
        $this->assertSame(1, substr_count($html, '<li'));
    }
}
