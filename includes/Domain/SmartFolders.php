<?php

declare(strict_types=1);

namespace FolderFolio\Domain;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Admin\MediaLibraryFilter;
use FolderFolio\Support\FileSizes;
use FolderFolio\Support\PostTypes;
use WP_Error;

/**
 * Smart folders — a name and a set of rules, kept by the site (tier 3 item
 * 13, Nick's 13b: shared, made by roles with Organise, a Smart group above
 * the tree, never a drop target).
 *
 * **Not rows in `folderfolio_folders`.** A smart folder has no parent, no
 * path, no files filed in it, no lock, no colour; putting it in the folder
 * table would teach every one of those code paths to step round it. They are
 * few — a site keeps a handful of views — and read on every library load, so
 * one autoloaded option holds them all, the way the settings do.
 *
 * @phpstan-import-type Rule from SmartRules
 * @phpstan-type Smart array{
 *     id: int,
 *     name: string,
 *     object_type: string,
 *     rules: list<Rule>,
 *     created_by: int,
 *     created_at: string
 * }
 */
final class SmartFolders
{
    public const OPTION = 'folderfolio_smart_folders';

    /** Every smart folder a site can have. The group is a short list, not a second tree. */
    public const MAX = 50;

    /**
     * @return list<Smart>
     */
    public function all(?string $objectType = null): array
    {
        $stored = get_option(self::OPTION, []);
        $items = is_array($stored) && is_array($stored['items'] ?? null) ? $stored['items'] : [];
        $out = [];

        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['id'], $item['name'], $item['object_type'])) {
                continue;
            }

            $type = (string) $item['object_type'];

            if ($objectType !== null && $type !== $objectType) {
                continue;
            }

            $out[] = [
                'id' => (int) $item['id'],
                'name' => (string) $item['name'],
                'object_type' => $type,
                'rules' => SmartRules::sanitize($item['rules'] ?? [], $type),
                'created_by' => (int) ($item['created_by'] ?? 0),
                'created_at' => (string) ($item['created_at'] ?? ''),
            ];
        }

        usort($out, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * @return Smart|null
     */
    public function get(int $id): ?array
    {
        foreach ($this->all() as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param mixed $rules
     * @return Smart|WP_Error
     */
    public function create(string $name, string $objectType, $rules): array|WP_Error
    {
        if (!isset(SmartRules::FIELDS[$objectType])) {
            return new WP_Error(
                'folderfolio_smart_type',
                __('Smart folders are for media for now.', 'folderfolio'),
                ['status' => 400]
            );
        }

        $checked = $this->check($name, $objectType, $rules, null);

        if (is_wp_error($checked)) {
            return $checked;
        }

        $stored = $this->stored();

        if (count($stored['items']) >= self::MAX) {
            return new WP_Error(
                'folderfolio_smart_too_many',
                /* translators: %d: the most smart folders a site can have. */
                sprintf(__('A site can have up to %d smart folders.', 'folderfolio'), self::MAX),
                ['status' => 400]
            );
        }

        $item = [
            'id' => $stored['next'],
            'name' => $checked['name'],
            'object_type' => $objectType,
            'rules' => $checked['rules'],
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
        ];

        $stored['items'][] = $item;
        $stored['next']++;
        $this->write($stored);

        return $item;
    }

    /**
     * @param mixed $rules Null keeps the rules as they are.
     * @return Smart|WP_Error
     */
    public function update(int $id, ?string $name, $rules): array|WP_Error
    {
        $current = $this->get($id);

        if ($current === null) {
            return $this->notFound();
        }

        $checked = $this->check(
            $name ?? $current['name'],
            $current['object_type'],
            $rules ?? $current['rules'],
            $id
        );

        if (is_wp_error($checked)) {
            return $checked;
        }

        $stored = $this->stored();

        foreach ($stored['items'] as $index => $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                $stored['items'][$index]['name'] = $checked['name'];
                $stored['items'][$index]['rules'] = $checked['rules'];
            }
        }

        $this->write($stored);

        return $this->get($id) ?? $current;
    }

    /**
     * @return true|WP_Error
     */
    public function delete(int $id): bool|WP_Error
    {
        $stored = $this->stored();
        $before = count($stored['items']);
        $stored['items'] = array_values(array_filter(
            $stored['items'],
            static fn ($item): bool => !is_array($item) || (int) ($item['id'] ?? 0) !== $id
        ));

        if (count($stored['items']) === $before) {
            return $this->notFound();
        }

        $this->write($stored);

        return true;
    }

    /**
     * How many items the rules match right now, for the person asking.
     *
     * Asked of WP_Query with the smart folder's query var, so the number is
     * the library's own answer to the same question — the same statuses, the
     * same clause — and not a second count that could disagree with it.
     *
     * @param list<Rule> $rules
     */
    public function count(array $rules, string $objectType = PostTypes::MEDIA): int
    {
        if (SmartRules::needsSizes($rules)) {
            FileSizes::backfill();
        }

        $query = new \WP_Query([
            'post_type' => $objectType,
            'post_status' => PostTypes::statuses($objectType),
            'fields' => 'ids',
            'posts_per_page' => 1,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'suppress_filters' => false,
            MediaLibraryFilter::SMART_RULES_VAR => $rules,
        ]);

        return (int) $query->found_posts;
    }

    /**
     * @param mixed $rules
     * @return array{name: string, rules: list<Rule>}|WP_Error
     */
    private function check(string $name, string $objectType, $rules, ?int $id): array|WP_Error
    {
        $name = trim(sanitize_text_field($name));

        if ($name === '') {
            return new WP_Error('folderfolio_name_required', __('Give the smart folder a name.', 'folderfolio'), ['status' => 400]);
        }

        if (mb_strlen($name) > 191) {
            return new WP_Error('folderfolio_name_too_long', __('That name is too long.', 'folderfolio'), ['status' => 400]);
        }

        foreach ($this->all($objectType) as $other) {
            if ($other['id'] !== $id && mb_strtolower($other['name']) === mb_strtolower($name)) {
                return new WP_Error(
                    'folderfolio_duplicate_name',
                    __('A smart folder with that name already exists.', 'folderfolio'),
                    ['status' => 400]
                );
            }
        }

        $clean = SmartRules::sanitize($rules, $objectType);

        if ($clean === []) {
            return new WP_Error(
                'folderfolio_smart_no_rules',
                __('Add at least one rule — a smart folder with none would be everything.', 'folderfolio'),
                ['status' => 400]
            );
        }

        return ['name' => $name, 'rules' => $clean];
    }

    /**
     * @return array{next: int, items: list<array<string, mixed>>}
     */
    private function stored(): array
    {
        $stored = get_option(self::OPTION, []);
        $items = is_array($stored) && is_array($stored['items'] ?? null) ? array_values($stored['items']) : [];
        $next = is_array($stored) ? (int) ($stored['next'] ?? 1) : 1;

        foreach ($items as $item) {
            $next = max($next, (int) ($item['id'] ?? 0) + 1);
        }

        /** @var list<array<string, mixed>> $items */
        return ['next' => max(1, $next), 'items' => $items];
    }

    /**
     * @param array{next: int, items: list<array<string, mixed>>} $stored
     */
    private function write(array $stored): void
    {
        update_option(self::OPTION, $stored, true);

        // A cached folder view keys on this (MediaLibraryFilter::cacheVersion()).
        wp_cache_set_last_changed('folderfolio');
    }

    private function notFound(): WP_Error
    {
        return new WP_Error('folderfolio_smart_not_found', __('Smart folder not found.', 'folderfolio'), ['status' => 404]);
    }
}
