<?php

declare(strict_types=1);

namespace FolderFolio\Modules\Import;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which of the ids a source names are still attachments.
 *
 * Every source's pair table names files that were deleted years ago — none of
 * them has a foreign key, and none of them cleans up on `delete_attachment`.
 * Screen 07 calls these out by id rather than burying them in a count, because
 * "attachments 118 and 204 no longer exist" is something a person can act on.
 *
 * Shared by the planner and the runner deliberately. They have to agree about
 * what is importable, and two implementations of "is this still a file" is two
 * chances for the preview to promise something the run does not do.
 */
final class Media
{
    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public static function existing(array $ids): array
    {
        global $wpdb;

        if ([] === $ids) {
            return [];
        }

        $found = [];

        // Chunked: a library with 50,000 filed attachments would otherwise
        // build one IN() list past max_allowed_packet.
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));

            /** @var list<string> $rows */
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- which of these ids still exist, asked once per 500 while importing; must be live.
            $rows = $wpdb->get_col(
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the sniff cannot count the spread ids that fill the %d list.
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only: one %d per id.
                    "SELECT ID FROM %i WHERE post_type = 'attachment' AND ID IN ({$placeholders})",
                    $wpdb->posts,
                    ...$chunk
                )
            ) ?: [];

            foreach ($rows as $row) {
                $found[] = (int) $row;
            }
        }

        return $found;
    }
}
