<?php

declare(strict_types=1);

/**
 * Bootstrap for the unit suite.
 *
 * Deliberately does not load WordPress. What is under test here is the logic
 * most likely to be wrong and least dependent on a running site: path
 * arithmetic, the count roll-up, the colour tokens, and the sanitising of
 * values that arrive over HTTP. Testing those without a WordPress bootstrap
 * keeps the feedback loop in milliseconds and makes the dependency rule
 * enforceable: if a file under test starts needing WordPress, this suite stops
 * compiling, which is the signal.
 *
 * RailPreferences is here because its sanitising half is pure and static. It
 * does have two methods that read and write user meta — those are not called
 * from this suite, and loading the file does not need WordPress. If that ever
 * changes the require below will fail, which is the rule working.
 *
 * Integration tests that do need WordPress live in tests/Integration and run
 * from phpunit.xml.dist against the WordPress test library.
 */

define('ABSPATH', __DIR__ . '/');

$root = dirname(__DIR__);

require_once $root . '/includes/Domain/FolderPath.php';
require_once $root . '/includes/Domain/FolderTree.php';
require_once $root . '/includes/Admin/RailPreferences.php';
