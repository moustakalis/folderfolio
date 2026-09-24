<?php

declare(strict_types=1);

namespace FolderFolio\Cli;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Domain\Folder;
use FolderFolio\Domain\FolderService;
use FolderFolio\Domain\FolderTree;
use WP_CLI;
use WP_CLI\Utils;
use WP_Error;

/**
 * Manage media folders.
 *
 * Thin by design: every command here delegates to the public `FolderFolio`
 * facade. If a command ever needs to reach past it, that is a gap in the
 * facade rather than a reason to reach into the domain layer — the CLI is the
 * facade's first real consumer, and so its first real test.
 *
 * No other plugin in this category ships WP-CLI commands at all, which makes
 * this the difference between a plugin and something you can run across a
 * fleet of sites non-interactively.
 */
final class FolderCommand
{
    private const FIELDS = ['id', 'name', 'parent_id', 'depth', 'count', 'total_count'];

    public function __construct(
        private readonly FolderService $folders = new FolderService()
    ) {
    }

    /**
     * List folders.
     *
     * ## OPTIONS
     *
     * [--tree]
     * : Render the hierarchy with indentation instead of a flat table.
     *
     * [--parent=<id>]
     * : Only folders beneath this one.
     *
     * [--fields=<fields>]
     * : Comma-separated columns. Defaults to id,name,parent_id,depth,count,total_count.
     *
     * [--format=<format>]
     * : Output format.
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
     *     wp folderfolio folder list --tree
     *     wp folderfolio folder list --parent=7 --format=ids
     *
     * @subcommand list
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function list_(array $args, array $assoc): void
    {
        $parent = isset($assoc['parent']) ? (int) $assoc['parent'] : null;

        $tree = $parent === null
            ? $this->folders->tree()
            : $this->folders->subtree($parent);

        if ($tree === [] && $parent !== null) {
            WP_CLI::error(sprintf('No folder with id %d.', $parent));
        }

        $rows = FolderTree::flatten($tree);

        if ($rows === []) {
            WP_CLI::log('No folders yet.');

            return;
        }

        $format = $assoc['format'] ?? 'table';

        if ($format === 'ids') {
            WP_CLI::log(implode(' ', array_column($rows, 'id')));

            return;
        }

        if (isset($assoc['tree']) && $format === 'table') {
            foreach ($rows as $row) {
                WP_CLI::log(sprintf(
                    '%s%s  (#%d, %d)',
                    str_repeat('  ', (int) $row['depth']),
                    (string) $row['name'],
                    (int) $row['id'],
                    (int) ($row['total_count'] ?? 0)
                ));
            }

            return;
        }

        $fields = isset($assoc['fields'])
            ? array_map('trim', explode(',', $assoc['fields']))
            : self::FIELDS;

        Utils\format_items($format, $rows, $fields);
    }

    /**
     * Create a folder, and any missing ancestor of it.
     *
     * Takes a path of names, so provisioning a structure is one line. The
     * operation is idempotent: running it twice creates nothing the second
     * time and still prints the folder.
     *
     * ## OPTIONS
     *
     * <path>
     * : Slash-separated folder names, e.g. "Clients/Acme/2026".
     *
     * [--porcelain]
     * : Print only the folder id, for use in a script.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio folder create 'Clients/Acme/2026'
     *     ID=$(wp folderfolio folder create 'Brand/Logos' --porcelain)
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function create(array $args, array $assoc): void
    {
        $folder = \FolderFolio::getOrCreateByPath((string) ($args[0] ?? ''));

        $this->bailOnError($folder);

        if (isset($assoc['porcelain'])) {
            WP_CLI::line((string) $folder->id);

            return;
        }

        WP_CLI::success(sprintf('Folder "%s" is id %d.', $folder->name, $folder->id));
    }

    /**
     * Move a folder, and its whole subtree with it.
     *
     * ## OPTIONS
     *
     * <id>
     * : The folder to move.
     *
     * --parent=<id>
     * : New parent. Use 0 to move it to the root.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio folder move 12 --parent=3
     *     wp folderfolio folder move 12 --parent=0
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function move(array $args, array $assoc): void
    {
        $parent = (int) ($assoc['parent'] ?? 0);

        $folder = \FolderFolio::moveFolder(
            (int) ($args[0] ?? 0),
            $parent === 0 ? null : $parent
        );

        $this->bailOnError($folder);

        WP_CLI::success(sprintf(
            'Moved "%s" to %s.',
            $folder->name,
            $folder->parentId === null ? 'the root' : '#' . $folder->parentId
        ));
    }

    /**
     * Delete a folder. Media is never deleted, only unfiled.
     *
     * ## OPTIONS
     *
     * <id>
     * : The folder to delete.
     *
     * --children=<strategy>
     * : What happens to its subfolders. Required — this is not a question to
     * answer silently.
     * ---
     * options:
     *   - reparent
     *   - cascade
     * ---
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio folder delete 12 --children=reparent
     *     wp folderfolio folder delete 12 --children=cascade --yes
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function delete(array $args, array $assoc): void
    {
        $id = (int) ($args[0] ?? 0);
        $children = (string) ($assoc['children'] ?? '');

        $folder = $this->folders->get($id);

        if ($folder === null) {
            WP_CLI::error(sprintf('No folder with id %d.', $id));
        }

        $descendants = count($this->folders->subtreeIds($id)) - 1;

        WP_CLI::confirm(
            $children === FolderService::CHILDREN_CASCADE && $descendants > 0
                ? sprintf(
                    'Delete "%s" and %d folder(s) beneath it? Media is kept, only unfiled.',
                    $folder->name,
                    $descendants
                )
                : sprintf('Delete "%s"? Media is kept, only unfiled.', $folder->name),
            $assoc
        );

        $deleted = \FolderFolio::deleteFolder($id, $children);

        $this->bailOnError($deleted);

        WP_CLI::success(sprintf('Deleted %d folder(s).', $deleted));
    }

    /**
     * Bail out of the command when the facade returned a WP_Error.
     *
     * @param Folder|int|bool|WP_Error $result
     * @phpstan-assert !WP_Error $result
     */
    private function bailOnError(mixed $result): void
    {
        if ($result instanceof WP_Error) {
            WP_CLI::error(sprintf(
                '%s (%s)',
                $result->get_error_message(),
                $result->get_error_code()
            ));
        }
    }
}
