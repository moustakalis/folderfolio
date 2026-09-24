<?php

declare(strict_types=1);

namespace FolderFolio\Cli;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Database\Doctor;
use FolderFolio\Database\Schema;
use WP_CLI;
use WP_CLI\Utils;
use WP_Error;

/**
 * FolderFolio maintenance and media filing.
 */
final class RootCommand
{
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
        $ids = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) ($args[0] ?? ''))
        )));

        if ($ids === []) {
            WP_CLI::error('Give at least one attachment id.');
        }

        // Filing checks each file against the person doing it; a CLI run has
        // nobody unless --user says who (found building `import`, 24 Sep —
        // the refusal said only "not allowed to organise").
        if (0 === get_current_user_id()) {
            WP_CLI::error('Say who is filing — add --user=<login>. Each file is checked against that person, as in the library.');
        }

        $result = \FolderFolio::assign(
            $ids,
            (int) ($assoc['folder'] ?? 0),
            (string) ($assoc['mode'] ?? 'add')
        );

        if ($result instanceof WP_Error) {
            WP_CLI::error(sprintf('%s (%s)', $result->get_error_message(), $result->get_error_code()));
        }

        WP_CLI::success(sprintf('Filed %d attachment(s).', $result));
    }

    /**
     * Recompute every folder's path and depth from parent_id.
     *
     * parent_id is the source of truth, so this can always rebuild the derived
     * columns. Safe to run at any time; it writes only what has drifted.
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
        $written = (new Schema())->backfillPaths(true);

        WP_CLI::success(sprintf('Rebuilt %d folder path(s).', $written));
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
