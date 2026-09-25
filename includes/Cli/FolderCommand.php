<?php

declare(strict_types=1);

namespace FolderFolio\Cli;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Domain\Folder;
use FolderFolio\Domain\FolderService;
use FolderFolio\Domain\FolderTree;
use FolderFolio\Support\PostTypes;
use WP_CLI;
use WP_CLI\Utils;

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
    use CommandHelpers;

    private const FIELDS = ['id', 'name', 'parent_id', 'depth', 'count', 'total_count'];

    /** What `folder get` shows, in this order. */
    private const DETAIL = [
        'id', 'name', 'path', 'parent_id', 'object_type', 'count', 'total_count',
        'color', 'kind', 'locked', 'pinned', 'sort_folders', 'sort_files',
    ];

    /**
     * List folders.
     *
     * ## OPTIONS
     *
     * [--tree]
     * : Render the hierarchy with indentation instead of a flat table.
     *
     * [--parent=<id>]
     * : Only folders beneath this one, in its own tree.
     *
     * [--object-type=<type>]
     * : Which tree: attachment (media, the default) or a post type with folders, such as page.
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
     *     wp folderfolio folder list --object-type=page --tree
     *
     * @subcommand list
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function list_(array $args, array $assoc): void
    {
        $parent = isset($assoc['parent']) ? (int) $assoc['parent'] : null;
        $type = $this->objectType($assoc);

        $tree = \FolderFolio::getTree($parent, $type);

        if ($tree === [] && $parent !== null) {
            WP_CLI::error(sprintf('No folder with id %d.', $parent));
        }

        $rows = FolderTree::flatten($tree);

        if ($rows === []) {
            WP_CLI::log($type === 'attachment' ? 'No folders yet.' : sprintf('No %s folders yet.', $type));

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
     * [--object-type=<type>]
     * : Which tree: attachment (media, the default) or a post type with folders, such as page.
     *
     * [--porcelain]
     * : Print only the folder id, for use in a script.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio folder create 'Clients/Acme/2026'
     *     ID=$(wp folderfolio folder create 'Brand/Logos' --porcelain)
     *     wp folderfolio folder create 'Legal/Policies' --object-type=page
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function create(array $args, array $assoc): void
    {
        $folder = \FolderFolio::getOrCreateByPath((string) ($args[0] ?? ''), $this->objectType($assoc));

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

        $folder = $this->folder($id);
        $descendants = count(\FolderFolio::getDescendantIds($id)) - 1;

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
     * Show one folder: where it is, what it holds, and how it is set.
     *
     * ## OPTIONS
     *
     * <id>
     * : The folder.
     *
     * [--field=<field>]
     * : Print one value only, e.g. total_count or kind.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp folderfolio folder get 12
     *     wp folderfolio folder get 12 --field=total_count
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function get(array $args, array $assoc): void
    {
        $folder = $this->folder((int) ($args[0] ?? 0));
        $node = $this->node($folder);

        $names = array_map(static fn (Folder $f): string => $f->name, \FolderFolio::getAncestors($folder->id));
        $names[] = $folder->name;

        $row = [
            'id' => $folder->id,
            'name' => $folder->name,
            'path' => implode('/', $names),
            'parent_id' => $folder->parentId ?? '',
            'object_type' => $folder->objectType,
            'count' => (int) ($node['count'] ?? 0),
            'total_count' => (int) ($node['total_count'] ?? 0),
            'color' => $folder->color ?? '',
            'kind' => (string) ($node['kind'] ?? 'folder'),
            'locked' => !empty($node['locked']) ? 'yes' : 'no',
            'pinned' => !empty($node['pinned']) ? 'yes' : 'no',
            'sort_folders' => (string) ($node['sort_folders'] ?? ''),
            'sort_files' => (string) ($node['sort_files'] ?? ''),
        ];

        if (isset($assoc['field'])) {
            if (!array_key_exists($assoc['field'], $row)) {
                WP_CLI::error(sprintf('No field called %s. The fields are %s.', $assoc['field'], implode(', ', self::DETAIL)));
            }

            WP_CLI::line((string) $row[$assoc['field']]);

            return;
        }

        $format = $assoc['format'] ?? 'table';

        if ($format === 'table') {
            Utils\format_items('table', array_map(
                static fn (string $key): array => ['field' => $key, 'value' => $row[$key]],
                self::DETAIL
            ), ['field', 'value']);

            return;
        }

        WP_CLI::print_value($row, ['format' => $format]);
    }

    /**
     * Rename a folder.
     *
     * ## OPTIONS
     *
     * <id>
     * : The folder.
     *
     * <name>
     * : Its new name. A slash is part of the name, not a path.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio folder rename 12 'Spring 2026'
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function rename(array $args, array $assoc): void
    {
        $id = (int) ($args[0] ?? 0);
        $before = $this->folder($id)->name;
        $folder = \FolderFolio::renameFolder($id, (string) ($args[1] ?? ''));

        $this->bailOnError($folder);

        WP_CLI::success(sprintf('Renamed "%s" to "%s".', $before, $folder->name));
    }

    /**
     * Copy a folder and every folder beneath it.
     *
     * The copy goes beside the original unless --parent says where. With
     * --with-files the copies hold the same files — filed, not copied on
     * disk — and each file is checked against --user.
     *
     * ## OPTIONS
     *
     * <id>
     * : The folder to copy.
     *
     * [--parent=<id>]
     * : Where the copy goes. Use 0 for the top level.
     *
     * [--with-files]
     * : File the same files in the copies. Needs --user.
     *
     * [--porcelain]
     * : Print only the copy's id.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio folder duplicate 12
     *     wp folderfolio folder duplicate 12 --parent=0 --with-files --user=admin
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function duplicate(array $args, array $assoc): void
    {
        $source = $this->folder((int) ($args[0] ?? 0));
        $withFiles = (bool) Utils\get_flag_value($assoc, 'with-files', false);

        if ($withFiles) {
            $this->needUser('filing the copies');
        }

        $parent = isset($assoc['parent']) ? (int) $assoc['parent'] : $source->parentId;
        $copy = \FolderFolio::duplicateFolder($source->id, $parent === 0 ? null : $parent, $withFiles);

        $this->bailOnError($copy);

        if (isset($assoc['porcelain'])) {
            WP_CLI::line((string) $copy->id);

            return;
        }

        WP_CLI::success(sprintf('Copied "%s" to "%s", id %d.', $source->name, $copy->name, $copy->id));
    }

    /**
     * Arrange one level of folders by hand.
     *
     * Give every folder in the level, in order. A folder from another level
     * is moved into this one. The level shows this order when it is sorted by
     * Custom order.
     *
     * ## OPTIONS
     *
     * <ids>
     * : Comma-separated folder ids, first to last.
     *
     * [--parent=<id>]
     * : The level: the folder they sit in. Leave it out, or use 0, for the top level.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio folder reorder 14,12,13 --parent=7
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function reorder(array $args, array $assoc): void
    {
        $parent = (int) ($assoc['parent'] ?? 0);
        $arranged = \FolderFolio::reorderFolders($parent === 0 ? null : $parent, $this->ids((string) ($args[0] ?? '')));

        $this->bailOnError($arranged);

        WP_CLI::success(sprintf('Arranged %d folder(s).', $arranged));
    }

    /**
     * Set a folder's colour, or clear it.
     *
     * ## OPTIONS
     *
     * <id>
     * : The folder.
     *
     * <color>
     * : A swatch — slate, red, clay, ochre, moss, teal, steel, indigo, plum, ink — or a hex, which takes the nearest swatch. none clears it.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio folder color 12 plum
     *     wp folderfolio folder color 12 none
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function color(array $args, array $assoc): void
    {
        $this->folder((int) ($args[0] ?? 0));
        $folder = \FolderFolio::setFolderColor((int) $args[0], $this->orNone((string) ($args[1] ?? '')));

        $this->bailOnError($folder);

        WP_CLI::success($folder->color === null
            ? sprintf('"%s" has no colour.', $folder->name)
            : sprintf('"%s" is %s.', $folder->name, $folder->color));
    }

    /**
     * Set the order a folder shows its subfolders or its files in.
     *
     * ## OPTIONS
     *
     * <id>
     * : The folder.
     *
     * <scope>
     * : What is ordered.
     * ---
     * options:
     *   - folders
     *   - files
     * ---
     *
     * <order>
     * : name-asc, name-desc, newest, oldest or custom. none follows each person's own sort.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio folder sort 12 files newest
     *     wp folderfolio folder sort 12 folders none
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function sort(array $args, array $assoc): void
    {
        $order = $this->orNone((string) ($args[2] ?? ''));
        $folder = \FolderFolio::setFolderSort((int) ($args[0] ?? 0), (string) ($args[1] ?? ''), $order);

        $this->bailOnError($folder);

        WP_CLI::success($order === null
            ? sprintf('"%s" shows its %s in each person’s own sort.', $folder->name, $args[1])
            : sprintf('"%s" shows its %s %s.', $folder->name, $args[1], $order));
    }

    /**
     * Put files in a folder's own order.
     *
     * The folder's files are then sorted by Custom order — what a gallery of
     * the folder shows, and the library when Sort inside says Custom.
     *
     * ## OPTIONS
     *
     * <id>
     * : The folder.
     *
     * <file-ids>
     * : Comma-separated ids of files already in the folder. They keep their order among themselves.
     *
     * [--place=<place>]
     * : Where they go.
     * ---
     * default: start
     * options:
     *   - start
     *   - end
     *   - before
     *   - after
     * ---
     *
     * [--anchor=<file-id>]
     * : The file they go before or after.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio folder order-files 12 88,91 --user=admin
     *     wp folderfolio folder order-files 12 88 --place=after --anchor=95 --user=admin
     *
     * @subcommand order-files
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function order_files(array $args, array $assoc): void
    {
        $this->needUser('arranging the files');

        $placed = \FolderFolio::orderFiles(
            (int) ($args[0] ?? 0),
            $this->ids((string) ($args[1] ?? '')),
            (string) ($assoc['place'] ?? 'start'),
            isset($assoc['anchor']) ? (int) $assoc['anchor'] : null
        );

        $this->bailOnError($placed);

        WP_CLI::success(sprintf('The folder’s %d file(s) are in their new order.', $placed));
    }

    /**
     * Lock a folder, and everything beneath it.
     *
     * A locked folder cannot be renamed, moved, reordered, deleted or have
     * folders made inside it by anyone without the lock ability; files can
     * still be filed into it. A command run is not stopped by a lock.
     *
     * ## OPTIONS
     *
     * <id>
     * : The folder.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio folder lock 12
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function lock(array $args, array $assoc): void
    {
        $this->mark($args, 'lock');
    }

    /**
     * Unlock a folder.
     *
     * ## OPTIONS
     *
     * <id>
     * : The folder.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function unlock(array $args, array $assoc): void
    {
        $this->mark($args, 'unlock');
    }

    /**
     * Pin a folder to the top of its level.
     *
     * ## OPTIONS
     *
     * <id>
     * : The folder.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function pin(array $args, array $assoc): void
    {
        $this->mark($args, 'pin');
    }

    /**
     * Unpin a folder.
     *
     * ## OPTIONS
     *
     * <id>
     * : The folder.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function unpin(array $args, array $assoc): void
    {
        $this->mark($args, 'unpin');
    }

    /**
     * Make a media folder a gallery, or a plain folder again.
     *
     * A gallery holds images only, and a new one shows its files in Custom
     * order. Refused while the folder holds anything else.
     *
     * ## OPTIONS
     *
     * <id>
     * : The folder.
     *
     * <kind>
     * : What it is.
     * ---
     * options:
     *   - gallery
     *   - folder
     * ---
     *
     * ## EXAMPLES
     *
     *     wp folderfolio folder kind 12 gallery
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function kind(array $args, array $assoc): void
    {
        $kind = (string) ($args[1] ?? '');
        $folder = \FolderFolio::setFolderKind((int) ($args[0] ?? 0), $kind);

        $this->bailOnError($folder);

        WP_CLI::success(sprintf($kind === 'gallery' ? '"%s" is a gallery.' : '"%s" is a folder.', $folder->name));
    }

    /**
     * Write a media folder and everything beneath it to a ZIP file.
     *
     * The same archive as Download from the folder's menu, without the
     * site's download size limit. Files --user may not read are left out and
     * counted in not-included.txt. An existing file is never replaced.
     *
     * ## OPTIONS
     *
     * <id>
     * : The folder.
     *
     * [--output=<path>]
     * : A file, or a directory to write the folder's own file name into. Defaults to the current directory.
     *
     * [--porcelain]
     * : Print only the path written.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio folder zip 12 --user=admin
     *     wp folderfolio folder zip 12 --output=/backups/brand.zip --user=admin
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function zip(array $args, array $assoc): void
    {
        $this->needUser('downloading', 'Only the files that person may read go in, as in the library.');

        $written = \FolderFolio::zipFolder((int) ($args[0] ?? 0), (string) ($assoc['output'] ?? getcwd()));

        $this->bailOnError($written);

        if (isset($assoc['porcelain'])) {
            WP_CLI::line($written['path']);

            return;
        }

        WP_CLI::success(sprintf(
            'Wrote %s — %d file(s), %s.',
            $written['path'],
            $written['files'],
            size_format($written['length'], 1) ?: '0 B'
        ));

        if ($written['left_out'] > 0) {
            WP_CLI::warning(sprintf('%d file(s) left out — not-included.txt in the ZIP says which and why.', $written['left_out']));
        }
    }

    /**
     * @param list<string> $args
     */
    private function mark(array $args, string $verb): void
    {
        $id = (int) ($args[0] ?? 0);
        $this->folder($id);

        $folder = match ($verb) {
            'lock' => \FolderFolio::lockFolder($id, true),
            'unlock' => \FolderFolio::lockFolder($id, false),
            'pin' => \FolderFolio::pinFolder($id, true),
            default => \FolderFolio::pinFolder($id, false),
        };

        $this->bailOnError($folder);

        $done = ['lock' => 'locked', 'unlock' => 'unlocked', 'pin' => 'pinned', 'unpin' => 'unpinned'][$verb];

        WP_CLI::success(sprintf('"%s" is %s.', $folder->name, $done));
    }

    /**
     * The folder, or the command stops with the id it was given.
     */
    private function folder(int $id): Folder
    {
        $folder = \FolderFolio::getFolder($id);

        if ($folder === null) {
            WP_CLI::error(sprintf('No folder with id %d.', $id));
        }

        return $folder;
    }

    /**
     * The folder's node in its tree — counts, sorts and marks.
     *
     * @return array<string, mixed>
     */
    private function node(Folder $folder): array
    {
        foreach (FolderTree::flatten(\FolderFolio::getTree($folder->id)) as $row) {
            if ((int) $row['id'] === $folder->id) {
                return $row;
            }
        }

        return [];
    }

    /**
     * --object-type, checked against the types with folders.
     *
     * @param array<string, string> $assoc
     */
    private function objectType(array $assoc): string
    {
        $type = (string) ($assoc['object-type'] ?? PostTypes::MEDIA);

        if (!in_array($type, PostTypes::enabled(), true)) {
            WP_CLI::error(sprintf(
                'No folders for %s on this site. The types with folders are %s — others are switched on under Settings → Folders for.',
                $type,
                implode(', ', PostTypes::enabled())
            ));
        }

        return $type;
    }
}
