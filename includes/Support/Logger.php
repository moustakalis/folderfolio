<?php

declare(strict_types=1);

namespace FolderFolio\Support;

/**
 * Small structured logger for development diagnostics.
 */
final class Logger
{
    /**
     * Log an informational message.
     *
     * @param array<string, mixed> $context Additional structured context.
     */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /**
     * Log an error message.
     *
     * @param array<string, mixed> $context Additional structured context.
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /**
     * Write one formatted log line.
     *
     * @param 'INFO'|'ERROR' $level Log severity.
     * @param array<string, mixed> $context Additional structured context.
     */
    private function write(string $level, string $message, array $context): void
    {
        $suffix = '';

        if ($context !== []) {
            try {
                $suffix = ' ' . json_encode(
                        $context,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                    );
            } catch (\JsonException) {
                $suffix = ' {"context":"Unable to encode log context"}';
            }
        }

        error_log(sprintf('[FolderFolio %s] %s%s', $level, $message, $suffix));
    }
}
