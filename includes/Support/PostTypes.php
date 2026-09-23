<?php

declare(strict_types=1);

namespace FolderFolio\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which kinds of content have folders, and what each one asks — tier 3 item 12.
 *
 * A folder belongs to one object type (`folderfolio_folders.object_type`): the
 * media library's are `attachment`, a post's `post`, a page's `page`. One tree
 * per type (Nick's 12b, board KZsHhrffzKQYqUjTvdFszK) — a post and an image
 * are never filed in the same folder, and the rail on the Posts screen shows
 * the Posts tree.
 *
 * Everything that differs between types is answered here, so the rest of the
 * plugin asks rather than assumes `attachment`:
 *
 * - **enabled** — media always; Posts and Pages by default; anything else
 *   when ticked under *Folders for*. A type that is not registered right now
 *   (its plugin switched off) is not enabled, but its folders are kept.
 * - **the base capability** — `Capabilities` rule 1. `upload_files` for media,
 *   the type's own `edit_posts` for the rest (12e): a person who cannot open
 *   the Products screen is not handed its folder tree by the roles matrix.
 * - **the statuses that count** — what the type's list screen calls *All*.
 */
final class PostTypes
{
    public const MEDIA = 'attachment';

    /**
     * Types that are never offered, whatever they register.
     *
     * The block editor's own records — reusable blocks, templates, navigation,
     * global styles, fonts — have `show_ui` but are not content a person files.
     */
    private const NEVER = [
        'attachment',
        'revision',
        'nav_menu_item',
        'custom_css',
        'customize_changeset',
        'oembed_cache',
        'user_request',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_global_styles',
        'wp_navigation',
        'wp_font_family',
        'wp_font_face',
    ];

    /**
     * Every type with folders on this site right now, media first.
     *
     * @return list<string>
     */
    public static function enabled(): array
    {
        $types = [self::MEDIA];

        foreach (Settings::get()['post_types'] as $type) {
            if (self::offerable($type)) {
                $types[] = $type;
            }
        }

        /**
         * Filters the object types that have folders.
         *
         * `attachment` is put back if a filter removes it: the media library's
         * folders are the product, not an option.
         *
         * @param list<string> $types
         */
        $filtered = apply_filters('folderfolio_object_types', $types);
        $filtered = is_array($filtered) ? array_values(array_filter($filtered, 'is_string')) : $types;

        return array_values(array_unique([self::MEDIA, ...$filtered]));
    }

    public static function isEnabled(string $type): bool
    {
        return in_array($type, self::enabled(), true);
    }

    /**
     * The types *Folders for* lists: registered, with an admin screen, and not
     * one of the editor's own records.
     *
     * @return array<string, string> slug => plural label
     */
    public static function offered(): array
    {
        $offered = [];

        foreach (get_post_types(['show_ui' => true], 'objects') as $slug => $object) {
            if (self::offerable((string) $slug)) {
                $offered[(string) $slug] = (string) $object->labels->name;
            }
        }

        return $offered;
    }

    private static function offerable(string $type): bool
    {
        if (in_array($type, self::NEVER, true) || strlen($type) > 20) {
            return false;
        }

        $object = get_post_type_object($type);

        return $object !== null && (bool) $object->show_ui;
    }

    /**
     * Rule 1's capability for a type: without it, no folders at all.
     *
     * An unknown type answers `do_not_allow` — the capability WordPress itself
     * uses for "nobody" — rather than falling back to anything a person might
     * hold.
     */
    public static function baseCap(string $type): string
    {
        if ($type === self::MEDIA) {
            return 'upload_files';
        }

        $object = get_post_type_object($type);

        if ($object === null) {
            return 'do_not_allow';
        }

        return (string) ($object->cap->edit_posts ?? 'edit_posts');
    }

    /**
     * The statuses a type's list screen counts under *All*.
     *
     * Media is `inherit` and `private`, as the library queries it. Every other
     * type takes what `edit.php` shows under *All*: `show_in_admin_all_list`,
     * which leaves out the trash and auto-drafts — a folder should not count a
     * post nobody can see in it.
     *
     * @return list<string>
     */
    public static function statuses(string $type): array
    {
        if ($type === self::MEDIA) {
            return ['inherit', 'private'];
        }

        /** @var list<string> $statuses */
        $statuses = array_values(get_post_stati(['show_in_admin_all_list' => true]));

        return $statuses === [] ? ['publish', 'draft', 'pending', 'future', 'private'] : $statuses;
    }

    /**
     * The type's plural name, as its own screens say it — "Posts", "Pages".
     */
    public static function label(string $type): string
    {
        $object = get_post_type_object($type);

        return $object === null ? $type : (string) $object->labels->name;
    }

    /**
     * The type a request is about, from a raw parameter: absent is media.
     *
     * @param mixed $value
     */
    public static function fromRequest($value): string
    {
        if (!is_string($value) || $value === '') {
            return self::MEDIA;
        }

        return sanitize_key($value);
    }
}
