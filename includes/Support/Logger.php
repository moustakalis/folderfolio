<?php

declare(strict_types=1);

namespace FolderFolio\Support;

/**
 * Simple logger utility.
 */
final class Logger
{
    /**
     * Log a message to error log.
     *
     * @param string $message
     * @return void
     */
    public static function log(string $message): void
    {
        error_log('[FolderFolio] ' . $message);
    }
}
