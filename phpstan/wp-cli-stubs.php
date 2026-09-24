<?php

/**
 * Minimal WP-CLI stubs, for static analysis only.
 *
 * Never loaded at runtime, and never shipped — bin/build-zip.sh copies an
 * allowlist that does not include phpstan/. `scanFiles` in phpstan.neon points
 * here so the analyser knows what WP_CLI is. Without it, PHPStan reports every
 * call as `class.notFound` and, worse, stops reasoning about includes/Cli/*
 * altogether.
 *
 * ## Why not php-stubs/wp-cli-stubs
 *
 * It caps php-stubs/wordpress-stubs at ^6.0, and this plugin pins ^7.1 for
 * WordPress 7.x. Composer cannot satisfy both, so the choice was an outdated
 * WordPress stub set or a hand-written WP-CLI one. This file covers only the
 * symbols includes/Cli/* actually calls — a surface small enough to keep
 * honest — and it carries one thing the real package does not express:
 * error() and halt() never return, which lets PHPStan narrow types after a
 * guard clause instead of assuming execution continues.
 *
 * If a command starts using a WP-CLI symbol that is not here, PHPStan says so
 * rather than passing over it. That is the point.
 *
 * Bracketed namespaces are required: this file declares symbols in both the
 * global namespace and WP_CLI\Utils, and PHP forbids mixing global code with
 * an unbracketed `namespace` statement.
 *
 * @phpcs:disable
 */

declare(strict_types=1);

namespace {
    class WP_CLI
    {
        /**
         * @param string|callable|object $callable
         * @param array<string, mixed>   $args
         */
        public static function add_command(string $name, $callable, array $args = []): bool
        {
            return true;
        }

        public static function log(string $message): void
        {
        }

        public static function line(string $message = ''): void
        {
        }

        public static function success(string $message): void
        {
        }

        public static function warning(string $message): void
        {
        }

        /**
         * Prints an error and terminates the process.
         *
         * @param string|\WP_Error $message
         * @phpstan-return never
         */
        public static function error($message, bool $exit = true)
        {
            exit(1);
        }

        /**
         * @param array<string, mixed> $assoc_args
         */
        public static function confirm(string $question, array $assoc_args = []): void
        {
        }

        /**
         * Print a value in the format asked for — json, yaml, or var_export.
         *
         * @param mixed                $value
         * @param array<string, mixed> $assoc_args
         */
        public static function print_value($value, array $assoc_args = []): void
        {
        }

        /**
         * @phpstan-return never
         */
        public static function halt(int $code)
        {
            exit($code);
        }
    }
}

namespace WP_CLI\Utils {
    /**
     * @param array<int, array<string, mixed>> $items
     * @param string|array<int, string>        $fields
     */
    function format_items(string $format, array $items, $fields): void
    {
    }

    /**
     * @param array<string, mixed> $assoc_args
     * @param mixed                $default
     * @return mixed
     */
    function get_flag_value(array $assoc_args, string $flag, $default = null)
    {
        return $default;
    }

    /**
     * cli\progress\Bar in a terminal, a no-op object elsewhere — both answer
     * tick() and finish(), which is all `wp folderfolio import` asks of it.
     *
     * @return \WP_CLI\Utils\ProgressBar
     */
    function make_progress_bar(string $message, int $count, int $interval = 100)
    {
        return new ProgressBar();
    }

    /** The two methods of cli\progress\Bar the plugin calls. */
    class ProgressBar
    {
        public function tick(int $increment = 1, ?string $msg = null): void
        {
        }

        public function finish(): void
        {
        }
    }
}
