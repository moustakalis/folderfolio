<?php

declare(strict_types=1);

/**
 * Bootstrap for the unit suite.
 *
 * Deliberately does not load WordPress. The classes under test here —
 * FolderPath and FolderTree — are the parts of the domain layer that hold the
 * logic most likely to be wrong (path arithmetic, count roll-up) and have no
 * WordPress dependency at all. Testing them without a WordPress bootstrap
 * keeps the feedback loop in milliseconds and makes the dependency rule
 * enforceable: if a file under test starts needing WordPress, this suite stops
 * compiling, which is the signal.
 *
 * Integration tests that do need WordPress live in tests/Integration and run
 * from phpunit.xml.dist against the WordPress test library.
 */

define('ABSPATH', __DIR__ . '/');

$root = dirname(__DIR__);

require_once $root . '/includes/Domain/FolderPath.php';
require_once $root . '/includes/Domain/FolderTree.php';
