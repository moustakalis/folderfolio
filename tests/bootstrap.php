<?php
/**
 * PHPUnit bootstrap for FolderFolio tests.
 */

// Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// WordPress test suite
if (!defined('WP_TESTS_DIR')) {
    $wp_tests_dir = getenv('WP_TESTS_DIR');

    if (!$wp_tests_dir) {
        $wp_tests_dir = '/tmp/wordpress-tests-lib';
    }
}

if (!file_exists($wp_tests_dir . '/includes/functions.php')) {
    echo "WordPress test suite not found. Run bin/install-wp-tests.sh first.\n";
    exit(1);
}

require_once $wp_tests_dir . '/includes/functions.php';

// Load FolderFolio plugin
function _load_folderfolio_plugin() {
    require_once dirname(__DIR__) . '/../folderfolio.php';
}
tests_add_filter('muplugins_loaded', '_load_folderfolio_plugin');

// Bootstrap WordPress
require_once $wp_tests_dir . '/includes/bootstrap.php';
