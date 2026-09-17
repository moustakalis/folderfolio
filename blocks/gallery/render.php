<?php

declare(strict_types=1);

/**
 * The gallery, on the front end.
 *
 * Server-rendered, and that is a security decision before it is a performance
 * one: a client-rendered block would need a publicly readable REST endpoint,
 * which is a new unauthenticated query surface on every site that installs
 * this plugin. Rendered here, the front end makes no API call at all, works
 * under full-page caching, and ships no JavaScript.
 *
 * The markup is deliberately plain. This is the one surface a visitor sees, so
 * it carries no class of ours that sets visual style, no plugin credit and no
 * colour of ours: a list, a figure, and an `<img>` core generated with its own
 * srcset and loading attributes. The theme's typography and the theme's link
 * colour, because it is the theme's page.
 *
 * @var array<string, mixed> $attributes
 * @var string               $content
 * @var WP_Block             $block
 */

use FolderFolio\Blocks\GalleryQuery;

if (!defined('ABSPATH')) {
    exit;
}

$folderfolio_attachments = (new GalleryQuery())->attachments($attributes);

if ([] === $folderfolio_attachments) {
    // Nothing at all, rather than an empty box or an explanation. A visitor
    // reading the page is not the person who can fix an empty folder, and a
    // gallery that has not been configured yet should not leave a hole in a
    // published post.
    //
    // `return`, not `echo`: core requires this file inside an output buffer
    // and keeps what was printed. A returned value goes nowhere — which is
    // why the markup below is echoed rather than returned.
    return;
}

$folderfolio_layout = 'masonry' === ($attributes['layout'] ?? 'grid') ? 'masonry' : 'grid';
$folderfolio_columns = max(1, min(8, (int) ($attributes['columns'] ?? 3)));
$folderfolio_gap = max(0, min(96, (int) ($attributes['gap'] ?? 16)));
$folderfolio_link = is_string($attributes['linkTo'] ?? null) ? $attributes['linkTo'] : 'none';

$folderfolio_wrapper = get_block_wrapper_attributes([
    'class' => 'folderfolio-gallery--' . $folderfolio_layout,
    // Two custom properties rather than two rules: the stylesheet is static
    // and cacheable, and a theme that wants different columns can override the
    // properties without fighting a specificity war with inline CSS.
    'style' => sprintf(
        '--ff-gallery-columns:%d;--ff-gallery-gap:%dpx;',
        $folderfolio_columns,
        $folderfolio_gap
    ),
]);

$folderfolio_out = '<ul ' . $folderfolio_wrapper . '>';

foreach ($folderfolio_attachments as $folderfolio_attachment) {
    $folderfolio_image = wp_get_attachment_image(
        $folderfolio_attachment->ID,
        'large',
        false,
        [
            // Core decides `loading` and `decoding` for itself, and gets the
            // first-image-above-the-fold case right; this only adds what core
            // has no way to know.
            'class' => 'wp-block-folderfolio-gallery__image',
        ]
    );

    if ('' === $folderfolio_image) {
        // A non-image attachment — a PDF in a folder of photographs. Skipped
        // rather than rendered as a broken tile.
        continue;
    }

    $folderfolio_href = '';

    if ('media' === $folderfolio_link) {
        $folderfolio_href = (string) wp_get_attachment_url($folderfolio_attachment->ID);
    } elseif ('attachment' === $folderfolio_link) {
        $folderfolio_href = (string) get_attachment_link($folderfolio_attachment->ID);
    }

    $folderfolio_caption = wp_get_attachment_caption($folderfolio_attachment->ID);

    $folderfolio_out .= '<li class="wp-block-folderfolio-gallery__item"><figure>';
    $folderfolio_out .= '' !== $folderfolio_href
        ? '<a href="' . esc_url($folderfolio_href) . '">' . $folderfolio_image . '</a>'
        : $folderfolio_image;

    if (is_string($folderfolio_caption) && '' !== $folderfolio_caption) {
        $folderfolio_out .= '<figcaption>' . wp_kses_post($folderfolio_caption) . '</figcaption>';
    }

    $folderfolio_out .= '</figure></li>';
}

$folderfolio_out .= '</ul>';

// Escaped as it was built: `get_block_wrapper_attributes()` escapes its own
// output, `wp_get_attachment_image()` is core's markup, the caption goes
// through `wp_kses_post()` and the href through `esc_url()`. Escaping the
// assembled string again would strip the `srcset` core just wrote.
//
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo $folderfolio_out;
