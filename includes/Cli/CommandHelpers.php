<?php

declare(strict_types=1);

namespace FolderFolio\Cli;

if (!defined('ABSPATH')) {
    exit;
}

use WP_CLI;
use WP_Error;

/**
 * What every FolderFolio command says the same way.
 */
trait CommandHelpers
{
    /**
     * Bail out of the command when the facade returned a WP_Error.
     *
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

    /**
     * Stop unless `--user` named somebody.
     *
     * A CLI run has nobody unless it is told, and a command that checks each
     * file against the person doing it would refuse every file with a reason
     * that does not say why (found building `import`, 24 Sep).
     *
     * @param string $doing What needs a person, as "Say who is …" finishes it.
     */
    private function needUser(string $doing, string $checked = 'Each file is checked against that person, as in the library.'): void
    {
        if (0 === get_current_user_id()) {
            WP_CLI::error(sprintf('Say who is %s — add --user=<login>. %s', $doing, $checked));
        }
    }

    /**
     * Comma-separated ids, as every command here takes them.
     *
     * @return list<int>
     */
    private function ids(string $list): array
    {
        return array_values(array_filter(array_map('intval', explode(',', $list))));
    }

    /**
     * `none` (or empty) as null; anything else as itself.
     */
    private function orNone(string $value): ?string
    {
        $value = trim($value);

        return '' === $value || 'none' === strtolower($value) ? null : $value;
    }
}
