<?php

declare(strict_types=1);

namespace FolderFolio\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Each file's size, where SQL can read it — for a smart folder's size rule
 * (tier 3 item 13).
 *
 * WordPress keeps a file's size inside `_wp_attachment_metadata`, a
 * serialized array: readable in PHP, not comparable in a `WHERE`. So the size
 * is kept once more as a number of its own, `_folderfolio_filesize`:
 *
 * - **written** whenever core writes the metadata — an upload, a regenerate,
 *   an edit — from the metadata's own `filesize` (WordPress 6.0+) or the file
 *   on disk;
 * - **filled in** for files that predate the plugin by `backfill()`, the
 *   first time a size rule is asked, in batches inside a time budget, and not
 *   asked again for an hour once a pass finds nothing missing.
 *
 * Deleted on uninstall. Nothing else reads it.
 */
final class FileSizes
{
    public const META = '_folderfolio_filesize';

    /** When a backfill last found nothing missing — a Unix time. */
    private const CHECKED = 'folderfolio_filesizes_checked';

    private const BATCH = 200;

    public function register(): void
    {
        add_filter('wp_update_attachment_metadata', [$this, 'onMetadata'], 10, 2);
    }

    /**
     * @param mixed $metadata
     * @param int   $attachmentId
     * @return mixed
     */
    public function onMetadata($metadata, $attachmentId)
    {
        self::store((int) $attachmentId, is_array($metadata) ? $metadata : []);

        return $metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function store(int $attachmentId, array $metadata): void
    {
        $bytes = isset($metadata['filesize']) && is_numeric($metadata['filesize']) ? (int) $metadata['filesize'] : null;

        if ($bytes === null) {
            $file = get_attached_file($attachmentId);
            $bytes = is_string($file) && is_readable($file) ? (int) filesize($file) : 0;
        }

        update_post_meta($attachmentId, self::META, $bytes);
    }

    /**
     * Give every file a size, within a time budget. True when none is left.
     */
    public static function backfill(float $budget = 2.0): bool
    {
        global $wpdb;

        $checked = (int) get_option(self::CHECKED, 0);

        if ($checked > time() - HOUR_IN_SECONDS) {
            return true;
        }

        $started = microtime(true);

        do {
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} AS p
                     LEFT JOIN {$wpdb->postmeta} AS m ON m.post_id = p.ID AND m.meta_key = %s
                     WHERE p.post_type = 'attachment' AND m.meta_id IS NULL
                     LIMIT %d",
                    self::META,
                    self::BATCH
                )
            );

            if ($ids === []) {
                update_option(self::CHECKED, time(), false);

                return true;
            }

            foreach ($ids as $id) {
                $metadata = wp_get_attachment_metadata((int) $id);
                self::store((int) $id, is_array($metadata) ? $metadata : []);
            }
        } while (microtime(true) - $started < $budget);

        return false;
    }

    /** For uninstall. */
    public static function forget(): void
    {
        delete_post_meta_by_key(self::META);
        delete_option(self::CHECKED);
    }
}
