<?php

declare(strict_types=1);

namespace FolderFolio\Blocks;

use FolderFolio\Domain\FolderService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `[folderfolio_gallery folder="Brand/Logos" columns="4"]`
 *
 * For classic themes, page builders, and anywhere else the block editor is not
 * — which on a lot of real sites is most of the site.
 *
 * **It takes a path, not an id.** A person writing a shortcode by hand knows
 * their folder as "Brand/Logos"; nobody knows it as 47. The path is resolved
 * read-only: `findByPath()` rather than `getOrCreateByPath()`, because a typo
 * in a shortcode must not create a folder — that is how a media library ends
 * up with a folder called "Brnad".
 *
 * Everything else is the block. The attributes are mapped and handed to
 * `render_block()`, so the shortcode and the block are the same renderer with
 * the same query behind them, down to the stylesheet that gets enqueued. Two
 * implementations of "a folder as a gallery" would be two things to keep in
 * step, and the one nobody uses is the one that rots.
 */
final class GalleryShortcode
{
    public const TAG = 'folderfolio_gallery';

    private FolderService $folders;

    public function __construct(?FolderService $folders = null)
    {
        $this->folders = $folders ?? new FolderService();
    }

    public function register(): void
    {
        add_shortcode(self::TAG, [$this, 'render']);
    }

    /**
     * @param array<string, string>|string $atts
     */
    public function render($atts): string
    {
        $atts = shortcode_atts(
            [
                'folder' => '',
                'id' => '',
                'subfolders' => 'no',
                'layout' => 'grid',
                'columns' => '3',
                'gap' => '16',
                'orderby' => 'date',
                'order' => 'desc',
                'limit' => '0',
                'link' => 'none',
                'lightbox' => 'no',
            ],
            is_array($atts) ? $atts : [],
            self::TAG
        );

        $folderId = $this->resolve((string) $atts['folder'], (string) $atts['id']);

        if (null === $folderId) {
            // Silence on the page, not a message. A visitor cannot act on
            // "folder not found", and a shortcode that shouts at them about a
            // typo in the post is worse than one that renders nothing.
            return '';
        }

        return (string) render_block([
            'blockName' => Gallery::NAME,
            'attrs' => [
                'folderIds' => [$folderId],
                'includeDescendants' => $this->boolean((string) $atts['subfolders']),
                'layout' => 'masonry' === $atts['layout'] ? 'masonry' : 'grid',
                'columns' => (int) $atts['columns'],
                'gap' => (int) $atts['gap'],
                'orderBy' => in_array($atts['orderby'], GalleryQuery::ORDER_BY, true)
                    ? (string) $atts['orderby']
                    : 'date',
                'order' => 'asc' === $atts['order'] ? 'asc' : 'desc',
                'limit' => (int) $atts['limit'],
                'linkTo' => in_array($atts['link'], ['none', 'media', 'attachment'], true)
                    ? (string) $atts['link']
                    : 'none',
                'lightbox' => $this->boolean((string) $atts['lightbox']),
            ],
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ]);
    }

    /**
     * The folder this shortcode means, or null.
     *
     * `id` wins when both are given, because it is unambiguous. Neither
     * creates anything.
     */
    private function resolve(string $path, string $id): ?int
    {
        if ('' !== trim($id) && (int) $id > 0) {
            return null === $this->folders->get((int) $id) ? null : (int) $id;
        }

        if ('' === trim($path)) {
            return null;
        }

        $folder = $this->folders->findByPath($path);

        return null === $folder ? null : $folder->id;
    }

    /**
     * `yes`, `true`, `1`, `on` — whichever one the person reached for.
     */
    private function boolean(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['yes', 'true', '1', 'on'], true);
    }
}
