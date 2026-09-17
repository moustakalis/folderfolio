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

    public function current(): ?Run
    {
        $stored = get_option(self::OPTION, null);

        if (!is_array($stored)) {
            return null;
        }

        /** @var array<string, mixed> $stored */
        return Run::fromArray($stored);
    }

    public function save(Run $run): void
    {
        update_option(self::OPTION, $run->store(), false);
    }

    public function clear(): void
    {
        delete_option(self::OPTION);
    }
}
