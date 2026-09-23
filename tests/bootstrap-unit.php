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
 * Settings is here on the same terms, and matters more: it is the function
 * standing between a form post and the option that decides who may delete a
 * folder. get() and save() touch the options table and are not called from
 * this suite; everything the tests exercise is pure.
 *
 * Integration tests that do need WordPress live in tests/Integration and run
 * from phpunit.xml.dist against the WordPress test library.
 */

define('ABSPATH', __DIR__ . '/');

$root = dirname(__DIR__);

require_once $root . '/includes/Domain/FolderPath.php';

// The pure half of pasting a copy — its name, and where it lands. The half
// that writes is FolderService::duplicate(), and it is an integration test.
require_once $root . '/includes/Domain/FolderCopy.php';
require_once $root . '/includes/Domain/FolderTree.php';
require_once $root . '/includes/Admin/RailPreferences.php';
require_once $root . '/includes/Support/Settings.php';

// The ZIP download's two pure halves: the writer, read back by libzip, and
// the names inside the archive.
require_once $root . '/includes/Support/ZipWriter.php';
require_once $root . '/includes/Domain/ArchiveNames.php';

// The import module's walk. Pure, and the place where the shapes nobody can
// arrange on purpose live: a source folder whose parent was deleted, a cycle,
// a branch deeper than the path column holds.
require_once $root . '/includes/Modules/Import/SourceFolder.php';
require_once $root . '/includes/Modules/Import/SourceTree.php';

/*
 * Elsewhere, for its `pick()` alone. Loading the file needs no WordPress —
 * every call to one is inside a method — and `pick()` is the half with a
 * decision in it: which plugin the rail's empty state names, and how many
 * others there are. The half that queries four competitors' tables is proved
 * in a throwaway WordPress instead, which is the only place those tables can
 * be made to exist.
 */
require_once $root . '/includes/Modules/Import/Elsewhere.php';
