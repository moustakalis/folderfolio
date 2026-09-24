<?php

declare(strict_types=1);

namespace FolderFolio\Cli;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Modules\Import\Catalog;
use FolderFolio\Modules\Import\JsonSource;
use FolderFolio\Modules\Import\Plan;
use FolderFolio\Modules\Import\Planner;
use FolderFolio\Modules\Import\Run;
use FolderFolio\Modules\Import\Runner;
use FolderFolio\Modules\Import\RunStore;
use FolderFolio\Modules\Import\Source;
use WP_CLI;
use WP_CLI\Utils;
use WP_Error;

/**
 * Import folders from another plugin, or from a FolderFolio export file.
 *
 * The wizard's engine, from a terminal — the same Planner, the same Runner,
 * the same run record — so an import started here shows in the wizard and
 * can be undone from either. Added, never moved: nothing is removed from a
 * folder you made, and the other plugin's data is only read.
 *
 * Writing needs a user who may manage options, as the wizard does, because
 * filing checks each file's permissions against a person: run with
 * `--user=<login>`.
 *
 * ## EXAMPLES
 *
 *     wp folderfolio import list
 *     wp folderfolio import preview filebird
 *     wp folderfolio import run filebird --user=admin
 *     wp folderfolio import run ./folderfolio-export.json --user=admin --yes
 *     wp folderfolio import undo --user=admin
 */
final class ImportCommand
{
    public function __construct(
        private readonly Planner $planner = new Planner(),
        private readonly Runner $runner = new Runner(),
        private readonly RunStore $runs = new RunStore()
    ) {
    }

    /**
     * List the plugins FolderFolio can import from, and which hold data.
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
     * ---
     *
     * ## EXAMPLES
     *
     *     wp folderfolio import list
     *
     * @subcommand list
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function list_(array $args, array $assoc): void
    {
        $rows = array_map(
            static fn (array $source): array => [
                'key' => $source['key'],
                'plugin' => $source['label'],
                'data' => $source['has_data'] ? 'yes' : 'no',
                'active' => $source['plugin_active'] ? 'yes' : 'no',
                'folders' => $source['folders'],
                'assignments' => $source['assignments'],
            ],
            Catalog::detect()
        );

        Utils\format_items(
            (string) ($assoc['format'] ?? 'table'),
            $rows,
            ['key', 'plugin', 'data', 'active', 'folders', 'assignments']
        );
    }

    /**
     * Show what an import would do, and write nothing.
     *
     * ## OPTIONS
     *
     * <source>
     * : A key from `wp folderfolio import list`, or the path to an export file.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp folderfolio import preview filebird
     *     wp folderfolio import preview ./folderfolio-export.json --format=json
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function preview(array $args, array $assoc): void
    {
        $source = $this->source((string) ($args[0] ?? ''));
        $plan = $this->planner->plan($source);

        if (($assoc['format'] ?? 'table') === 'json') {
            $out = $plan->toArray();

            if ($source instanceof JsonSource) {
                $out['file'] = $source->facts();
            }

            WP_CLI::line((string) wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->printPlan($source, $plan);
    }

    /**
     * Import, from start to finish.
     *
     * Shows the plan, asks, and runs it in batches with a progress bar. Stop it
     * with Ctrl-C; `wp folderfolio import resume` carries on from where it
     * stopped.
     *
     * ## OPTIONS
     *
     * <source>
     * : A key from `wp folderfolio import list`, or the path to an export file.
     *
     * [--yes]
     * : Import without asking.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio import run filebird --user=admin
     *     wp folderfolio import run ./folderfolio-export.json --user=admin --yes
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function run(array $args, array $assoc): void
    {
        $this->requireUser();

        $source = $this->source((string) ($args[0] ?? ''));
        $plan = $this->planner->plan($source);

        $this->printPlan($source, $plan);

        if ($plan->isEmpty()) {
            WP_CLI::success('Nothing needed importing — everything was already here.');

            return;
        }

        WP_CLI::confirm('Import?', $assoc);

        $started = $this->runner->start($source);

        if ($started instanceof WP_Error) {
            WP_CLI::error($started->get_error_message() . ' See `wp folderfolio import status`.');
        }

        $this->carry($started);
    }

    /**
     * Carry an import that stopped part-way on to its end.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio import resume --user=admin
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function resume(array $args, array $assoc): void
    {
        $this->requireUser();

        $run = $this->runs->current();

        if (null === $run || $run->isFinished()) {
            WP_CLI::error('There is no import to continue.');
        }

        $this->carry($run);
    }

    /**
     * Say where the last import is.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp folderfolio import status
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $run = $this->runs->current();

        if (null === $run) {
            WP_CLI::line('No import has run on this site.');

            return;
        }

        if (($assoc['format'] ?? 'table') === 'json') {
            WP_CLI::line((string) wp_json_encode($run->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return;
        }

        $rows = [
            ['field' => 'Source', 'value' => $run->sourceLabel . ' (' . $run->sourceKey . ')'],
            ['field' => 'Status', 'value' => $run->status],
            ['field' => 'Folders read', 'value' => sprintf('%d of %d', $run->cursor, $run->total)],
            ['field' => 'Folders created', 'value' => (string) $run->foldersCreated],
            ['field' => 'Merged by name', 'value' => (string) $run->foldersMerged],
            ['field' => 'Files added', 'value' => (string) $run->filesAdded],
            ['field' => 'Files skipped', 'value' => (string) $run->skippedTotal],
            ['field' => 'Started (UTC)', 'value' => $run->startedAt],
            ['field' => 'Finished (UTC)', 'value' => $run->finishedAt],
        ];

        if ('' !== $run->error) {
            $rows[] = ['field' => 'Error', 'value' => $run->error];
        }

        Utils\format_items('table', $rows, ['field', 'value']);
    }

    /**
     * Stop a running import after the folder it is on.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio import stop --user=admin
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function stop(array $args, array $assoc): void
    {
        $this->requireUser();

        $current = $this->runs->current();

        // The runner answers a finished run with the run itself, which the
        // wizard never asks about; here it would read as a stop that happened.
        if (null === $current || $current->isFinished()) {
            WP_CLI::error('There is no import running.');
        }

        $run = $this->runner->stop();

        if ($run instanceof WP_Error) {
            WP_CLI::error($run->get_error_message());
        }

        // The wizard's next poll is what finishes a stopping run; from here
        // there is no next poll, so take the step now.
        $run = $this->runner->step();

        if ($run instanceof WP_Error) {
            WP_CLI::error($run->get_error_message());
        }

        WP_CLI::success(sprintf('Stopped after %d of %d folders. Undo removes what it made.', $run->cursor, $run->total));
    }

    /**
     * Undo the last import.
     *
     * Removes the folders it created and the files it filed. Anything you had
     * made or filed yourself is left alone.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Undo without asking.
     *
     * ## EXAMPLES
     *
     *     wp folderfolio import undo --user=admin
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function undo(array $args, array $assoc): void
    {
        $this->requireUser();

        $run = $this->runs->current();

        if (null === $run || Run::DONE !== $run->status) {
            WP_CLI::error('There is no finished import to undo.');
        }

        WP_CLI::confirm(
            sprintf(
                'Undo the %s import — %d folder(s) and the files it filed?',
                $run->sourceLabel,
                count($run->createdFolderIds)
            ),
            $assoc
        );

        $undone = $this->runner->undo();

        if ($undone instanceof WP_Error) {
            WP_CLI::error($undone->get_error_message());
        }

        WP_CLI::success('Import undone. Anything you had already made was left alone.');
    }

    // ------------------------------------------------------------ helpers

    private function source(string $argument): Source
    {
        if ('' === $argument) {
            WP_CLI::error('Name a source: a key from `wp folderfolio import list`, or the path to an export file.');
        }

        $source = Catalog::fromArgument($argument);

        if ($source instanceof WP_Error) {
            $hint = 'folderfolio_import_unknown_source' === $source->get_error_code()
                ? ' Keys: ' . implode(', ', array_column(Catalog::detect(), 'key')) . '.'
                : '';

            WP_CLI::error($source->get_error_message() . $hint);
        }

        /** @var Source $source */
        return $source;
    }

    /**
     * The wizard's permission, and the person filing is checked against.
     */
    private function requireUser(): void
    {
        if (!current_user_can('manage_options')) {
            WP_CLI::error(
                'Run this as an administrator — add --user=<login>. Filing checks each file against the person doing it, as the wizard does.'
            );
        }
    }

    private function printPlan(Source $source, Plan $plan): void
    {
        $counts = $plan->toArray()['counts'];
        $samples = $plan->toArray()['samples'];

        $rows = [];

        foreach ([
            'create' => 'Folders to create',
            'merge' => 'Merged by name into yours',
            'reconcile' => 'Already imported before',
            'duplicate' => 'Duplicate names collapsed',
            'files' => 'Files to add',
            'already' => 'Files already filed there',
            'skipped' => 'Files that no longer exist',
            'unreachable' => 'Folders that cannot be placed',
        ] as $key => $label) {
            $example = $samples[$key] ?? [];

            $rows[] = [
                'what' => $label,
                'count' => (int) $counts[$key],
                'for example' => implode(', ', array_slice($example, 0, 4)),
            ];
        }

        WP_CLI::line(sprintf('%s — nothing has been written yet.', $source->label()));
        Utils\format_items('table', $rows, ['what', 'count', 'for example']);

        if ($source instanceof JsonSource) {
            $facts = $source->facts();

            if (!($facts['same_site'] ?? true) && ($facts['assignments_in_file'] ?? 0) > 0) {
                WP_CLI::line(sprintf(
                    'Only the folders are imported: its %d file assignment(s) name files on another site by number.',
                    (int) $facts['assignments_in_file']
                ));
            }
        }
    }

    private function carry(Run $run): void
    {
        $bar = Utils\make_progress_bar(sprintf('Importing from %s', $run->sourceLabel), max(1, $run->total));
        $at = $run->cursor;
        $bar->tick($at);

        $result = $this->runner->toEnd(static function (Run $now) use ($bar, &$at): void {
            $bar->tick(max(0, $now->cursor - $at));
            $at = $now->cursor;
        });

        if ($result instanceof WP_Error) {
            $bar->finish();
            WP_CLI::error($result->get_error_message());
        }

        $bar->tick(max(0, $result->cursor - $at));
        $bar->finish();

        $summary = sprintf(
            '%d folder(s) created, %d merged by name, %d file(s) added, %d skipped.',
            $result->foldersCreated,
            $result->foldersMerged,
            $result->filesAdded,
            $result->skippedTotal
        );

        if ('' !== $result->error) {
            WP_CLI::warning($result->error);
            WP_CLI::error('The import stopped. ' . $summary . ' `wp folderfolio import undo` removes what it made.');
        }

        WP_CLI::success('Imported. ' . $summary . ' Nothing left a folder you had made.');

        $source = Catalog::find($result->sourceKey);

        if (null !== $source && $source->isPluginActive()) {
            WP_CLI::line(sprintf(
                '%s is still switched on. Nothing in FolderFolio needs it running, so you can deactivate it whenever you like.',
                $result->sourceLabel
            ));
        }
    }
}
