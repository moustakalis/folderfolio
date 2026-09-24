<?php

declare(strict_types=1);

namespace FolderFolio\Cli;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Database\Doctor;
use WP_CLI;
use WP_CLI\Utils;

/**
 * FolderFolio maintenance and media filing.
 */
final class RootCommand
{
    use CommandHelpers;

    /**
     * File media into a folder.
     *
     * ## OPTIONS
     *
     * <ids>
     * : Comma-separated attachment ids.
     *
     * --folder=<id>
     * : Destination folder.
     *
     * [--mode=<mode>]
     * : Whether to add or move. add files it here and leaves every other
     * folder alone; move files it here and takes it out of every other.
     * ---
     * default: add
     * options:
     *   - add
     *   - move
     * ---
     *
     * ## EXAMPLES
     *
     *     wp folderfolio assign 12,13,14 --folder=7
     *     wp folderfolio assign 12 --folder=7 --mode=move
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function assign(array $args, array $assoc): void
    {
        $ids = $this->ids((string) ($args[0] ?? ''));

        if ($ids === []) {
            WP_CLI::error('Give at least one attachment id.');
        }

        $this->needUser('filing');

        $result = \FolderFolio::assign(
            $ids,
            (int) ($assoc['folder'] ?? 0),
            (string) ($assoc['mode'] ?? 'add')
        );

        $this->bailOnError($result);

        WP_CLI::success(sprintf('Filed %d attachment(s).', $result));
    }

    /**
     * Take media out of a folder, or out of every folder.
     *
     * The files are not deleted — only unfiled.
     *
     * ## OPTIONS
     *
     * <ids>
     * : Comma-separated attachment ids.
     *
     * [--folder=<id>]
     * : The folder to take them out of. Leave it out to take them out of every folder.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio unassign 12,13 --folder=7 --user=admin
     *     wp folderfolio unassign 12 --user=admin
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function unassign(array $args, array $assoc): void
    {
        $ids = $this->ids((string) ($args[0] ?? ''));

        if ($ids === []) {
            WP_CLI::error('Give at least one attachment id.');
        }

        $this->needUser('unfiling');

        $folder = isset($assoc['folder']) ? (int) $assoc['folder'] : null;

        if ($folder !== null && \FolderFolio::getFolder($folder) === null) {
            WP_CLI::error(sprintf('No folder with id %d.', $folder));
        }

        $result = \FolderFolio::unassign($ids, $folder);

        $this->bailOnError($result);

        WP_CLI::success($folder === null
            ? sprintf('Took %d attachment(s) out of every folder.', $result)
            : sprintf('Took %d attachment(s) out of folder %d.', $result, $folder));
    }

    /**
     * Write every media folder to a FolderFolio export file.
     *
     * The file Settings → Import → Export saves. Another site reads it with
     * `wp folderfolio import run <file>`.
     *
     * ## OPTIONS
     *
     * [--assignments]
     * : Include which files are in which folder. Only useful on a copy of this site, where the file ids are the same.
     *
     * [--file=<path>]
     * : Where to write it. Without it the document is printed, for a pipe.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio export --file=folders.json
     *     wp folderfolio export --assignments > folders.json
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function export(array $args, array $assoc): void
    {
        $document = \FolderFolio::exportFolders((bool) Utils\get_flag_value($assoc, 'assignments', false));
        $json = (string) wp_json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!isset($assoc['file'])) {
            WP_CLI::line($json);

            return;
        }

        $file = (string) $assoc['file'];

        if (file_exists($file)) {
            WP_CLI::error(sprintf('%s is already there. Choose another name, or move that file first.', $file));
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if (false === file_put_contents($file, $json . "\n")) {
            WP_CLI::error(sprintf('%s could not be written.', $file));
        }

        WP_CLI::success(sprintf(
            'Wrote %d folder(s)%s to %s.',
            count((array) ($document['folders'] ?? [])),
            isset($document['assignments']) && (array) $document['assignments'] !== []
                ? sprintf(' and %d filing(s)', count((array) $document['assignments']))
                : '',
            $file
        ));
    }

    /**
     * Forget files that were deleted, and folders that are gone.
     *
     * Settings → Status → Remove orphaned entries. Deleting a file through
     * WordPress already does this; a file removed another way — straight
     * from the database, a sync tool — leaves its entry behind.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio remove-orphans
     *
     * @subcommand remove-orphans
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function remove_orphans(array $args, array $assoc): void
    {
        $removed = \FolderFolio::removeOrphans();

        WP_CLI::success($removed === 1 ? 'Removed 1 orphaned entry.' : sprintf('Removed %d orphaned entries.', $removed));
    }

    /**
     * Recompute every folder's path and depth from parent_id.
     *
     * parent_id is the source of truth, so this can always rebuild the derived
     * columns. Safe to run at any time; it counts only what had drifted.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio rebuild-paths
     *
     * @subcommand rebuild-paths
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function rebuild_paths(array $args, array $assoc): void
    {
        $written = \FolderFolio::rebuildPaths();

        WP_CLI::success($written === 0
            ? 'Every folder’s path already agreed with its parent.'
            : sprintf('Repaired %d folder path(s) — they agree with their parents again.', $written));
    }

    /**
     * Check folder data for problems.
     *
     * Read-only — it reports, it does not repair. Exits non-zero when it finds
     * anything of severity `error`, so it can gate a deploy.
     *
     * ## OPTIONS
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
     * ---
     *
     * ## EXAMPLES
     *
     *     wp folderfolio doctor
     *     wp folderfolio doctor --format=json
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function doctor(array $args, array $assoc): void
    {
        $findings = (new Doctor())->check();

        if ($findings === []) {
            WP_CLI::success('No problems found.');

            return;
        }

        $rows = array_map(
            static fn (array $f): array => [
                'severity' => $f['severity'],
                'code' => $f['code'],
                'count' => $f['count'],
                'ids' => implode(',', $f['ids']),
                'message' => $f['message'],
            ],
            $findings
        );

        Utils\format_items(
            (string) ($assoc['format'] ?? 'table'),
            $rows,
            ['severity', 'code', 'count', 'ids', 'message']
        );

        $errors = array_filter($findings, static fn (array $f): bool => $f['severity'] === 'error');

        if ($errors !== []) {
            WP_CLI::error(sprintf(
                '%d problem(s) need attention. Try `wp folderfolio rebuild-paths` first.',
                count($errors)
            ));
        }

        WP_CLI::warning('Findings above are advisory.');
    }
}
