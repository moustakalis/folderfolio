<?php

declare(strict_types=1);

namespace FolderFolio\Domain;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Support\FileSizes;
use FolderFolio\Support\PostTypes;

/**
 * A smart folder's rules: what they may say, and the SQL they become — tier 3
 * item 13.
 *
 * Nick's 13a (board KZsHhrffzKQYqUjTvdFszK): saved rules a person makes, every
 * rule must match. Media's rules first (13c): type, date uploaded, uploaded
 * by, size, filed or not, name. The vocabulary is per object type from the
 * start — `FIELDS` is keyed by type — so posts can take status, author, date
 * and category later without a second engine.
 *
 * **Everything is SQL, added to whatever query is already running.** Not
 * WP_Query arguments: a rule written as `post_mime_type` would overwrite the
 * type the person picked in the library's own filter, where a `WHERE` clause
 * narrows it — a smart folder of images, filtered to "This month", is this
 * month's images. The rules are asked of `posts_clauses` by
 * `Admin\MediaLibraryFilter`, the same hook and the same bail as a folder:
 * no smart folder asked for, no clause touched.
 *
 * A rule is `{field, op, value}`. `sanitize()` keeps only what this type
 * understands and says nothing about the rest; `where()` never sees anything
 * `sanitize()` did not produce.
 *
 * @phpstan-type Rule array{field: string, op: string, value: int|string}
 */
final class SmartRules
{
    /** Ten rules is more than any view needs; a hundred would be a query nobody meant. */
    public const MAX_RULES = 10;

    /**
     * What each object type's rules may say: field => the operators it takes.
     */
    public const FIELDS = [
        PostTypes::MEDIA => [
            'type' => ['is', 'is_not'],
            'date' => ['last', 'after', 'before'],
            'author' => ['is'],
            'size' => ['gt', 'lt'],
            'filed' => ['none', 'any', 'in'],
            'name' => ['contains'],
        ],
    ];

    /** The file families a type rule names, as MIME prefixes. */
    public const FILE_TYPES = [
        'image' => ['image/'],
        'video' => ['video/'],
        'audio' => ['audio/'],
        'document' => ['application/', 'text/'],
    ];

    /**
     * Rules for this type, cleaned: unknown fields and operators dropped,
     * values coerced, at most MAX_RULES. An empty result means nothing usable
     * was sent.
     *
     * @param mixed $raw
     * @return list<Rule>
     */
    public static function sanitize($raw, string $objectType): array
    {
        $fields = self::FIELDS[$objectType] ?? null;

        if ($fields === null || !is_array($raw)) {
            return [];
        }

        $clean = [];

        foreach ($raw as $rule) {
            if (!is_array($rule) || count($clean) >= self::MAX_RULES) {
                continue;
            }

            $field = is_string($rule['field'] ?? null) ? $rule['field'] : '';
            $op = is_string($rule['op'] ?? null) ? $rule['op'] : '';

            if (!isset($fields[$field]) || !in_array($op, $fields[$field], true)) {
                continue;
            }

            $value = self::value($field, $op, $rule['value'] ?? null);

            if ($value === null) {
                continue;
            }

            $clean[] = ['field' => $field, 'op' => $op, 'value' => $value];
        }

        return $clean;
    }

    /**
     * @param mixed $value
     */
    private static function value(string $field, string $op, $value): int|string|null
    {
        switch ($field) {
            case 'type':
                return is_string($value) && isset(self::FILE_TYPES[$value]) ? $value : null;

            case 'date':
                if ($op === 'last') {
                    $days = is_numeric($value) ? (int) $value : 0;

                    return $days >= 1 && $days <= 36500 ? $days : null;
                }

                return is_string($value) && self::isDate($value) ? $value : null;

            case 'author':
                if ($value === 'me') {
                    return 'me';
                }

                $id = is_numeric($value) ? (int) $value : 0;

                return $id > 0 ? $id : null;

            case 'size':
                $bytes = is_numeric($value) ? (int) $value : -1;

                return $bytes >= 0 ? $bytes : null;

            case 'filed':
                if ($op !== 'in') {
                    return '';
                }

                $id = is_numeric($value) ? (int) $value : 0;

                return $id > 0 ? $id : null;

            case 'name':
                if (!is_string($value)) {
                    return null;
                }

                $text = trim(sanitize_text_field($value));

                return $text === '' ? null : mb_substr($text, 0, 100);
        }

        return null;
    }

    private static function isDate(string $value): bool
    {
        if (1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Whether any rule needs the file-size index (`Support\FileSizes`).
     *
     * @param list<Rule> $rules
     */
    public static function needsSizes(array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($rule['field'] === 'size') {
                return true;
            }
        }

        return false;
    }

    /**
     * The rules as `AND (…)` fragments against `{$wpdb->posts}`, every value
     * bound. Empty for no rules.
     *
     * "Uploaded by me" is whoever is asking — a shared smart folder shows each
     * person their own uploads. A date is a day, not a second, so the same
     * rule is the same SQL all day and WP_Query's cache keeps working.
     *
     * @param list<Rule> $rules
     */
    public static function where(array $rules): string
    {
        global $wpdb;

        $posts = $wpdb->posts;
        $parts = [];

        foreach ($rules as $rule) {
            $value = $rule['value'];

            switch ($rule['field']) {
                case 'type':
                    $likes = array_map(
                        static fn (string $prefix): string => (string) $wpdb->prepare(
                            '%i.post_mime_type LIKE %s',
                            $posts,
                            $wpdb->esc_like($prefix) . '%'
                        ),
                        self::FILE_TYPES[(string) $value]
                    );
                    $any = '(' . implode(' OR ', $likes) . ')';
                    $parts[] = $rule['op'] === 'is_not' ? "NOT {$any}" : $any;
                    break;

                case 'date':
                    if ($rule['op'] === 'last') {
                        // Local midnight, N days ago: "the last 7 days" is a
                        // week of whole days, and the same SQL until tomorrow.
                        $from = current_datetime()->modify('-' . ((int) $value - 1) . ' days')->format('Y-m-d 00:00:00');
                        $parts[] = (string) $wpdb->prepare('%i.post_date >= %s', $posts, $from);
                    } elseif ($rule['op'] === 'after') {
                        $parts[] = (string) $wpdb->prepare('%i.post_date >= %s', $posts, $value . ' 00:00:00');
                    } else {
                        $parts[] = (string) $wpdb->prepare('%i.post_date < %s', $posts, $value . ' 00:00:00');
                    }
                    break;

                case 'author':
                    $id = $value === 'me' ? get_current_user_id() : (int) $value;
                    $parts[] = $id > 0 ? (string) $wpdb->prepare('%i.post_author = %d', $posts, $id) : '1 = 0';
                    break;

                case 'size':
                    $parts[] = (string) $wpdb->prepare(
                        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the operator is one of two literals, '>' or '<', never the rule's own text.
                        'EXISTS (SELECT 1 FROM %i AS ff_size WHERE ff_size.post_id = %i.ID AND ff_size.meta_key = %s AND CAST(ff_size.meta_value AS UNSIGNED) ' . ($rule['op'] === 'gt' ? '>' : '<') . ' %d)',
                        $wpdb->postmeta,
                        $posts,
                        FileSizes::META,
                        (int) $value
                    );
                    break;

                case 'filed':
                    $parts[] = self::filed($rule['op'], (int) $value);
                    break;

                case 'name':
                    $like = '%' . $wpdb->esc_like((string) $value) . '%';
                    $parts[] = (string) $wpdb->prepare(
                        "(%i.post_title LIKE %s OR EXISTS (SELECT 1 FROM %i AS ff_file WHERE ff_file.post_id = %i.ID AND ff_file.meta_key = '_wp_attached_file' AND ff_file.meta_value LIKE %s))",
                        $posts,
                        $like,
                        $wpdb->postmeta,
                        $posts,
                        $like
                    );
                    break;
            }
        }

        return $parts === [] ? '' : ' AND ' . implode(' AND ', $parts);
    }

    /**
     * In no folder, in any, or in one folder and everything beneath it — a
     * rule about a folder means the folder as the rail shows it, subfolders
     * included, the way its inherited count does.
     */
    private static function filed(string $op, int $folderId): string
    {
        global $wpdb;

        $table = $wpdb->prefix . 'folderfolio_attachment_folders';
        $posts = $wpdb->posts;

        if ($op === 'none') {
            return "NOT EXISTS (SELECT 1 FROM {$table} AS ff_a WHERE ff_a.attachment_id = {$posts}.ID)";
        }

        if ($op === 'any') {
            return "EXISTS (SELECT 1 FROM {$table} AS ff_a WHERE ff_a.attachment_id = {$posts}.ID)";
        }

        $repository = new FolderRepository();
        $folder = $repository->find($folderId);

        if ($folder === null) {
            return '1 = 0';
        }

        $ids = array_map('intval', $repository->subtreeIds((string) $folder['path']));

        if ($ids === []) {
            return '1 = 0';
        }

        return "EXISTS (SELECT 1 FROM {$table} AS ff_a WHERE ff_a.attachment_id = {$posts}.ID AND ff_a.folder_id IN (" . implode(',', $ids) . '))';
    }
}
