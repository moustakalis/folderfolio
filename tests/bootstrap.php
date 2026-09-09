<?php
/**
 * PHPUnit bootstrap for FolderFolio.
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
    $_tests_dir = sys_get_temp_dir() . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    echo "Could not find {$_tests_dir}/includes/functions.php. Have you run bin/install-wp-tests.sh?\n";
    exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
    'muplugins_loaded',
    static function (): void {
        require dirname( __DIR__ ) . '/folderfolio.php';
    }
);

require $_tests_dir . '/includes/bootstrap.php';
