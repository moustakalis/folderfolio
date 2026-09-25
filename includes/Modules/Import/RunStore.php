<?php

declare(strict_types=1);

namespace FolderFolio\Modules\Import;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The one import run the site is holding.
 *
 * One, not many. An import is something a site does once, occasionally twice;
 * a history of them would be a table, a screen to read it and an uninstall
 * step, in exchange for a list nobody opens. What the wizard needs is the run
 * in progress — or, once it has finished, the run whose report is on screen
 * and whose Undo button still means something.
 *
 * Not autoloaded. It is read on the import screen and by the import routes,
 * and on no other request; autoloading it would put a few kilobytes of folder
 * ids into every page load of the site for the sake of a screen visited twice.
 */
final class RunStore
{
    public const OPTION = 'folderfolio_import_run';

    /**
     * A request to stop, kept apart from the run record — review M5.
     *
     * Stop used to write `stopping` into the run record, and the batch in
     * flight — which had read the record before Stop was pressed — saved it
     * back as `running` a moment later, so the import carried on. The batch
     * never writes this option; it only reads it.
     */
    public const STOP = 'folderfolio_import_stop';

    /**
     * Read live: another request (a second tab, the CLI, the batch in flight)
     * may have written it since this one started, and a non-autoloaded
     * option is otherwise cached for the rest of the request.
     */
    public function current(): ?Run
    {
        wp_cache_delete(self::OPTION, 'options');
        $stored = get_option(self::OPTION, null);

        if (!is_array($stored)) {
            return null;
        }

        /** @var array<string, mixed> $stored */
        $run = Run::fromArray($stored);

        // Say "stopping" as soon as it has been asked for, whatever the record
        // says — the record is the batch's to write.
        if (null !== $run && !$run->isFinished() && $this->stopRequested($run->id)) {
            $run->status = Run::STOPPING;
        }

        return $run;
    }

    public function requestStop(string $runId): void
    {
        update_option(self::STOP, $runId, false);
    }

    public function stopRequested(string $runId): bool
    {
        wp_cache_delete(self::STOP, 'options');

        return get_option(self::STOP, '') === $runId;
    }

    public function clearStop(): void
    {
        delete_option(self::STOP);
    }

    public function save(Run $run): void
    {
        update_option(self::OPTION, $run->store(), false);
    }

    public function clear(): void
    {
        delete_option(self::OPTION);
        delete_option(self::STOP);
    }
}
