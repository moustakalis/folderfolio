<?php

declare(strict_types=1);

namespace FolderFolio\Domain;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Error;
use wpdb;

/**
 * A folder's kind — tier 3 item 14, board KZsHhrffzKQYqUjTvdFszK (14a).
 *
 * Two kinds: a plain folder, which holds anything, and a **gallery**, which
 * holds images only. One row in `folderfolio_folder_meta` for a gallery and
 * none for a folder, like a lock or a pin — no migration, and every folder
 * that exists today is already a plain folder.
 *
 * No *Collection* kind (Real Media Library's third): a folder that holds only
 * galleries is a folder. What a gallery adds is a refusal — non-images are
 * turned away wherever files are added, with a sentence — a mark in the rail,
 * a place first in the gallery block's picker, and its files opening in the
 * order somebody arranged by hand.
 *
 * "An image" is a `post_mime_type` under `image/` — the same test the smart
 * folders' *Type is an image* rule uses (SmartRules::FILE_TYPES), so a file
 * the library calls an image is one a gallery takes.
 */
final class FolderKinds
{
    public const KEY = 'kind';

    public const FOLDER = 'folder';

    public const GALLERY = 'gallery';

    public const KINDS = [self::FOLDER, self::GALLERY];

    private wpdb $wpdb;

    /** @var array<int, true>|null */
    private ?array $galleries = null;

    public function __construct()
    {
        global $wpdb;

        $this->wpdb = $wpdb;
    }

    private function table(): string
    {
        return $this->wpdb->prefix . 'folderfolio_folder_meta';
    }

    /**
     * Every gallery, as id → true.
     *
     * @return array<int, true>
     */
    public function galleries(): array
    {
        if ($this->galleries !== null) {
            return $this->galleries;
        }

        $rows = $this->wpdb->get_col(
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT folder_id FROM {$this->table()} WHERE meta_key = %s AND meta_value = %s",
                self::KEY,
                self::GALLERY
            )
        ) ?: [];

        $out = [];

        foreach ($rows as $id) {
            $out[(int) $id] = true;
        }

        return $this->galleries = $out;
    }

    public function isGallery(int $folderId): bool
    {
        return isset($this->galleries()[$folderId]);
    }

    public function kindOf(int $folderId): string
    {
        return $this->isGallery($folderId) ? self::GALLERY : self::FOLDER;
    }

    /**
     * Write a folder's kind. Idempotent; a plain folder has no row.
     */
    public function set(int $folderId, string $kind): bool|WP_Error
    {
        $written = $kind === self::GALLERY
            ? $this->wpdb->query(
                $this->wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    "REPLACE INTO {$this->table()} (folder_id, meta_key, meta_value) VALUES (%d, %s, %s)",
                    $folderId,
                    self::KEY,
                    self::GALLERY
                )
            )
            : $this->wpdb->query(
                $this->wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    "DELETE FROM {$this->table()} WHERE folder_id = %d AND meta_key = %s",
                    $folderId,
                    self::KEY
                )
            );

        $this->galleries = null;

        if ($written === false) {
            return new WP_Error(
                'folderfolio_mark_failed',
                __('That could not be saved.', 'folderfolio'),
                ['status' => 500]
            );
        }

        wp_cache_set_last_changed('folderfolio');

        return true;
    }

    /**
     * The ids among these that are not images, in the order given.
     *
     * Primes the post cache first, for the reason `guardAttachments()` does:
     * a bulk add of a thousand files is one query, not a thousand.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public static function notImages(array $ids): array
    {
        foreach (array_chunk($ids, 1000) as $chunk) {
            _prime_post_caches($chunk, false, false);
        }

        $out = [];

        foreach ($ids as $id) {
            if (!self::isImageMime((string) get_post_mime_type($id))) {
                $out[] = $id;
            }
        }

        return $out;
    }

    public static function isImageMime(string $mime): bool
    {
        return str_starts_with(strtolower($mime), 'image/');
    }

    /**
     * Files offered to a gallery that are not images.
     *
     * @param int $refused How many of the files offered are not images.
     */
    public static function refusal(string $name, int $refused, int $offered): WP_Error
    {
        $message = $offered === 1
            ? sprintf(
                /* translators: %s: the gallery's name. */
                __('“%s” is a gallery, and a gallery holds images only. That file is not an image.', 'folderfolio'),
                $name
            )
            : sprintf(
                /* translators: 1: the gallery's name, 2: how many files are not images, 3: how many files were offered. */
                _n(
                    '“%1$s” is a gallery, and a gallery holds images only. %2$d of these %3$d files is not an image.',
                    '“%1$s” is a gallery, and a gallery holds images only. %2$d of these %3$d files are not images.',
                    $refused,
                    'folderfolio'
                ),
                $name,
                $refused,
                $offered
            );

        return new WP_Error('folderfolio_gallery_images_only', $message, ['status' => 400]);
    }
}
