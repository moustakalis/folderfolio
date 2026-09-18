<?php

declare(strict_types=1);

namespace FolderFolio\Tests;

use FolderFolio\Database\Transaction;
use PHPUnit\Runner\AfterTestHook;
use PHPUnit\Runner\BeforeTestHook;

/**
 * Tell Database\Transaction that the test harness already has a transaction
 * open on this connection.
 *
 * `WP_UnitTestCase` wraps every test in a transaction and rolls it back in
 * tearDown — that rollback is the whole of its isolation between tests. Our
 * write paths open transactions of their own, and MySQL does not nest them: a
 * `START TRANSACTION` inside the harness's would commit it, the rollback would
 * then have nothing to undo, and every subsequent test would run against
 * whatever its predecessors left behind. That failure does not look like a
 * transaction bug when it surfaces; it looks like an unrelated test failing for
 * no reason, three files away.
 *
 * Registered as a PHPUnit extension rather than a base class every test has to
 * remember to extend: there are eight integration test classes today and the
 * ninth would be written by someone who had never heard of this.
 */
final class TransactionHarness implements BeforeTestHook, AfterTestHook
{
    public function executeBeforeTest(string $test): void
    {
        Transaction::assumeOpen();
    }

    public function executeAfterTest(string $test, float $time): void
    {
        Transaction::reset();
    }
}
