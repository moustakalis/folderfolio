<?php

declare(strict_types=1);

namespace FolderFolio\Support;

/**
 * Basic PSR-3-style logger for debugging.
 */
class Logger
{
    public function info(string $message, array $context = []): void
    {
        error_log('[FolderFolio INFO] ' . $message . ' ' . json_encode($context));
    }

    public function error(string $message, array $context = []): void
    {
        error_log('[FolderFolio ERROR] ' . $message . ' ' . json_encode($context));
    }
}
