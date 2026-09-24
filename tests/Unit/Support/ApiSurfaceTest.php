<?php

namespace FolderFolio\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * docs/api/README.md names every facade method, every hook the plugin fires
 * and every WP-CLI command — read from the source, so a method, hook or
 * command added next month fails here until the page says it exists.
 *
 * The 24 Sep alignment audit found five hooks nobody had written down, CLI
 * commands missing from the page, and fourteen routes the page never listed
 * (record `…-24f-api-cli-alignment`). The routes are held the same way in
 * `Integration\Rest\RouteDocsTest`, which needs WordPress to list them.
 */
class ApiSurfaceTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function docs(): string
    {
        return (string) file_get_contents(self::root() . '/docs/api/README.md');
    }

    public function test_every_facade_method_has_a_heading(): void
    {
        preg_match_all('/public static function (\w+)\(/', (string) file_get_contents(self::root() . '/includes/api.php'), $m);

        $methods = array_diff($m[1], ['setService']);
        $this->assertGreaterThan(30, count($methods), 'the pattern still finds the facade');

        $missing = array_values(array_filter(
            $methods,
            static fn (string $method): bool => !str_contains(self::docs(), "#### `{$method}(")
        ));

        $this->assertSame([], $missing, 'on the facade, not on the page');
    }

    public function test_every_hook_fired_is_on_the_page(): void
    {
        $fired = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/includes'));

        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                preg_match_all("/do_action\\(\\s*'(folderfolio_[a-z_]+)'/", (string) file_get_contents($file->getPathname()), $m);
                $fired = array_merge($fired, $m[1]);
            }
        }

        $fired = array_values(array_unique($fired));
        $this->assertGreaterThan(15, count($fired));

        $missing = array_values(array_filter(
            $fired,
            static fn (string $hook): bool => !str_contains(self::docs(), "'{$hook}'")
        ));

        $this->assertSame([], $missing, 'fired by the plugin, not on the page');
    }

    public function test_every_cli_command_is_on_the_page(): void
    {
        $plugin = (string) file_get_contents(self::root() . '/includes/Plugin.php');
        preg_match_all("/add_command\\('([^']+)', (\\w+)::class\\)/", $plugin, $registered, PREG_SET_ORDER);

        $this->assertGreaterThanOrEqual(5, count($registered));

        $cli = substr(self::docs(), (int) strpos(self::docs(), '## WP-CLI'));
        $missing = [];
        $commands = 0;

        foreach ($registered as [, $prefix, $class]) {
            $source = (string) file_get_contents(self::root() . "/includes/Cli/{$class}.php");

            // Each public method with the docblock above it.
            preg_match_all('#/\*\*((?:(?!\*/).)*)\*/\s*public function (\w+)\(#s', $source, $methods, PREG_SET_ORDER);

            foreach ($methods as [, $doc, $method]) {
                if (str_starts_with($method, '__')) {
                    continue;
                }

                $name = preg_match('/@subcommand\s+(\S+)/', $doc, $sub) ? $sub[1] : $method;
                $command = "wp {$prefix} {$name}";
                ++$commands;

                if (!preg_match('/^' . preg_quote($command, '/') . '( |$)/m', $cli)) {
                    $missing[] = $command;
                }
            }
        }

        $this->assertGreaterThan(30, $commands, 'the pattern still finds the commands');
        $this->assertSame([], $missing, 'registered, not on the page');
    }
}
