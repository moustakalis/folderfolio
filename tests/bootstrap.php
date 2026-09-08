<?php
/**
 * PHPUnit bootstrap for FolderFolio tests.
 */

$_tests_dir = getenv('WP_TESTS_DIR');
if (!$_tests_dir) {
    $_tests_dir = '/tmp/wordpress-tests-lib';
}

if (!file_exists($_tests_dir . '/includes/functions.php')) {
    echo "WordPress test suite not found at {$_tests_dir}\n";
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once $_tests_dir . '/includes/functions.php';

function _folderfolio_load_plugin(): void
{
    require_once dirname(__DIR__) . '/../folderfolio.php';
}
tests_add_filter('muplugins_loaded', '_folderfolio_load_plugin');

require_once $_tests_dir . '/includes/bootstrap.php';
