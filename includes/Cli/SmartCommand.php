<?php

declare(strict_types=1);

namespace FolderFolio\Cli;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Support\PostTypes;
use WP_CLI;
use WP_CLI\Utils;

/**
 * Smart folders — a name and rules that pick files, never a place files are
 * filed.
 *
 * Rules are given as JSON, the shape the REST route takes and `smart list
 * --format=json` prints, so a set copied from one site is pasted into the
 * next unchanged. Every rule must match. A rule is {"field", "op", "value"}:
 * type is/is_not image|video|audio|document; date last (days), after or
 * before (Y-m-d); author is a user id or "me"; size gt/lt bytes; filed none,
 * any, or in a folder id; name contains text.
 */
final class SmartCommand
{
    use CommandHelpers;

    /**
     * List smart folders, with what each matches now.
     *
     * The count is for --user: a rule of author is me is theirs, and without
     * a user it matches nothing.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. json includes each folder's rules as the REST API takes them.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     *   - ids
     * ---
     *
     * ## EXAMPLES
     *
     *     wp folderfolio smart list
     *     wp folderfolio smart list --format=json > smart.json
     *
     * @subcommand list
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function list_(array $args, array $assoc): void
    {
        $smart = \FolderFolio::getSmartFolders(PostTypes::MEDIA);
        $format = $assoc['format'] ?? 'table';

        if ($smart === []) {
            WP_CLI::log('No smart folders yet.');

            return;
        }

        if ($format === 'ids') {
            WP_CLI::line(implode(' ', array_column($smart, 'id')));

            return;
        }

        if ($format === 'json' || $format === 'yaml') {
            WP_CLI::print_value($smart, ['format' => $format]);

            return;
        }

        $rows = array_map(function (array $item): array {
            $count = \FolderFolio::countSmartFolderItems((int) $item['id']);

            return [
                'id' => $item['id'],
                'name' => $item['name'],
                'rules' => $this->rulesText($item['rules']),
                'count' => is_int($count) ? $count : 0,
            ];
        }, $smart);

        Utils\format_items($format, $rows, ['id', 'name', 'rules', 'count']);
    }

    /**
     * Make a smart folder.
     *
     * ## OPTIONS
     *
     * <name>
     * : Its name.
     *
     * --rules=<json>
     * : A JSON list of rules, every one of which must match.
     *
     * [--porcelain]
     * : Print only the new smart folder's id.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio smart create 'Recent images' --rules='[{"field":"type","op":"is","value":"image"},{"field":"date","op":"last","value":30}]'
     *     wp folderfolio smart create 'Unfiled PDFs' --rules='[{"field":"type","op":"is","value":"document"},{"field":"filed","op":"none","value":""}]'
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function create(array $args, array $assoc): void
    {
        $smart = \FolderFolio::createSmartFolder(
            (string) ($args[0] ?? ''),
            $this->rules((string) ($assoc['rules'] ?? ''))
        );

        $this->bailOnError($smart);

        if (isset($assoc['porcelain'])) {
            WP_CLI::line((string) $smart['id']);

            return;
        }

        WP_CLI::success(sprintf('Smart folder "%s" is id %d: %s.', $smart['name'], $smart['id'], $this->rulesText($smart['rules'])));
    }

    /**
     * Rename a smart folder, or give it new rules.
     *
     * ## OPTIONS
     *
     * <id>
     * : The smart folder.
     *
     * [--name=<name>]
     * : Its new name.
     *
     * [--rules=<json>]
     * : Its new rules, all of them — they replace the old ones.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio smart update 3 --name='Last month'
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function update(array $args, array $assoc): void
    {
        if (!isset($assoc['name']) && !isset($assoc['rules'])) {
            WP_CLI::error('Say what changes — --name, --rules or both.');
        }

        $smart = \FolderFolio::updateSmartFolder(
            (int) ($args[0] ?? 0),
            isset($assoc['name']) ? (string) $assoc['name'] : null,
            isset($assoc['rules']) ? $this->rules((string) $assoc['rules']) : null
        );

        $this->bailOnError($smart);

        WP_CLI::success(sprintf('Smart folder %d is "%s": %s.', $smart['id'], $smart['name'], $this->rulesText($smart['rules'])));
    }

    /**
     * Delete a smart folder. No file is touched.
     *
     * ## OPTIONS
     *
     * <id>
     * : The smart folder.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio smart delete 3 --yes
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function delete(array $args, array $assoc): void
    {
        $id = (int) ($args[0] ?? 0);
        $smart = $this->find($id);

        WP_CLI::confirm(sprintf('Delete the smart folder "%s"? No file is touched.', $smart['name']), $assoc);

        $this->bailOnError(\FolderFolio::deleteSmartFolder($id));

        WP_CLI::success(sprintf('Deleted the smart folder "%s".', $smart['name']));
    }

    /**
     * The files a smart folder matches now, newest first.
     *
     * For --user, as the library shows them to that person.
     *
     * ## OPTIONS
     *
     * <id>
     * : The smart folder.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: ids
     * options:
     *   - ids
     *   - count
     *   - table
     *   - csv
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp folderfolio smart files 3 --user=admin
     *     wp folderfolio assign $(wp folderfolio smart files 3 --user=admin | tr ' ' ',') --folder=7 --user=admin
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function files(array $args, array $assoc): void
    {
        $this->find((int) ($args[0] ?? 0));
        $ids = \FolderFolio::getSmartFolderItemIds((int) $args[0]);

        $this->bailOnError($ids);

        $format = $assoc['format'] ?? 'ids';

        if ($format === 'ids') {
            WP_CLI::line(implode(' ', $ids));

            return;
        }

        if ($format === 'count') {
            WP_CLI::line((string) count($ids));

            return;
        }

        Utils\format_items($format, array_map(static fn (int $id): array => [
            'id' => $id,
            'title' => get_the_title($id),
            'mime' => (string) get_post_mime_type($id),
            'date' => (string) get_post_field('post_date', $id),
        ], $ids), ['id', 'title', 'mime', 'date']);
    }

    /**
     * @return array<string, mixed>
     */
    private function find(int $id): array
    {
        foreach (\FolderFolio::getSmartFolders(PostTypes::MEDIA) as $smart) {
            if ((int) $smart['id'] === $id) {
                return $smart;
            }
        }

        WP_CLI::error(sprintf('No smart folder with id %d.', $id));
    }

    /**
     * @return list<array{field: string, op: string, value: mixed}>
     */
    private function rules(string $json): array
    {
        $rules = json_decode($json, true);

        if (!is_array($rules) || array_values($rules) !== $rules) {
            WP_CLI::error('--rules is a JSON list, e.g. [{"field":"type","op":"is","value":"image"}].');
        }

        /** @var list<array{field: string, op: string, value: mixed}> $rules */
        return $rules;
    }

    /**
     * "type is image; date last 30" — one line for a table cell.
     *
     * @param mixed $rules
     */
    private function rulesText($rules): string
    {
        $parts = [];

        foreach (is_array($rules) ? $rules : [] as $rule) {
            if (is_array($rule)) {
                $value = $rule['value'] ?? '';
                $parts[] = trim(sprintf('%s %s %s', $rule['field'] ?? '', $rule['op'] ?? '', is_scalar($value) ? (string) $value : (string) wp_json_encode($value)));
            }
        }

        return implode('; ', $parts);
    }
}
