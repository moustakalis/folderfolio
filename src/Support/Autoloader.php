<?php

declare(strict_types=1);

namespace FolderFolio\Support;

/**
 * Minimal PSR-4 autoloader for FolderFolio\ namespace.
 *
 * Maps FolderFolio\* classes to src/*.php files.
 */
final class Autoloader
{
    /**
     * @var string
     */
    private static string $prefix = 'FolderFolio\\';

    /**
     * @var string
     */
    private static string $baseDir;

    /**
     * Register the autoloader.
     *
     * @param string $pluginDir Absolute path to the plugin directory.
     * @return void
     */
    public static function register(string $pluginDir): void
    {
        self::$baseDir = trailingslashit($pluginDir) . 'src/';

        spl_autoload_register([self::class, 'autoload']);
    }

    /**
     * Autoload a class.
     *
     * @param string $class Fully qualified class name.
     * @return void
     */
    private static function autoload(string $class): void
    {
        if (strpos($class, self::$prefix) !== 0) {
            return;
        }

        $relativeClass = substr($class, strlen(self::$prefix));
        $file = self::$baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (is_readable($file)) {
            require_once $file;
        }
    }
}
