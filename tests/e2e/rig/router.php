<?php
/**
 * Rig only — copied in by tests/e2e/rig/setup.sh, never shipped.
 */
$root = __DIR__;
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = $root . $path;
if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    if (str_ends_with($file, '.php')) {
        chdir(dirname($file));
        $_SERVER['SCRIPT_NAME'] = $path;
        $_SERVER['SCRIPT_FILENAME'] = $file;
        $_SERVER['PHP_SELF'] = $path;
        require $file;
        return;
    }
    return false;
}
if (is_dir($file) && file_exists(rtrim($file, '/') . '/index.php')) {
    $index = rtrim($file, '/') . '/index.php';
    $_SERVER['SCRIPT_NAME'] = rtrim($path, '/') . '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $index;
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
    chdir(dirname($index));
    require $index;
    return;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
chdir($root);
require $root . '/index.php';
