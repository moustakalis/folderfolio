<?php

declare(strict_types=1);

namespace FolderFolio\Database;

use Throwable;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Atomicity for the write paths that take more than one statement.
 *
 * ## Why this exists
 *
 * Several domain operations are several statements. Before this, a failure
 * part-way through left the halves disagreeing, and two of those states could
 * not be recovered from:
 *
 * - `delete($id, CASCADE)` removed every assignment in the subtree and then
 *   the folders. A failure between them left the folders standing with their
 *   membership gone — nothing records what had been in them.
 * - `move()` rewrites every descendant's `path` and `depth`, then sets the
 *   folder's own `parent_id`. A failure between them left the two disagreeing,
 *   and every subtree read is `WHERE path LIKE '<path>%'`, so the tree
 *   misroutes from then on. `Doctor::pathDrift()` exists to find that state.
 * - `assignAttachments(MODE_MOVE)` deletes an attachment's existing rows and
 *   then writes the new one. A failure between them left the file in no folder
 *   at all. That is the drag gesture.
 *
 * `AttachmentFolderRepository::deleteOrphans()` already describes the wreckage
 * in its docblock — "a row whose folder is gone is left by a delete that failed
 * part-way". This is the thing that stops producing it.
 *
 * ## Why a callback rather than begin/commit
 *
 * `$wpdb` has no transaction API, so this is raw SQL either way. A callback
 * makes the commit condition a single rule enforced in one place — **a
 * `WP_Error` return rolls back** — rather than a discipline every caller has to
 * remember at every early return. This codebase signals failure with `WP_Error`
 * everywhere and throws almost nowhere, so a try/finally built around
 * exceptions would have committed every real failure it saw.
 *
 * ## Nesting
 *
 * MySQL transactions do not nest: a second `START TRANSACTION` commits the
 * first. `delete(REPARENT)` calls `move()`, and `moveAttachments()` calls
 * `assignAttachments()`, so nesting is not hypothetical. The outermost call
 * opens a real transaction and every inner one takes a `SAVEPOINT`, which is
 * what MySQL offers for exactly this.
 *
 * ## Hooks fire after the commit, never inside it
 *
 * A listener on `folderfolio_folder_deleted` runs arbitrary third-party code.
 * Inside an open transaction that code would see rows no other connection can,
 * and — worse — anything it does that commits (a `COMMIT`, or DDL, which
 * commits implicitly in MySQL) would end our transaction early and defeat the
 * rollback. So actions are queued with `after()` and fired once the outermost
 * commit has succeeded, and discarded entirely when it does not.
 *
 * ## The one case where this silently does nothing
 *
 * A MyISAM table accepts `START TRANSACTION` and `ROLLBACK` and ignores both.
 * `Schema` therefore pins `ENGINE=InnoDB`, and `Doctor` reports any table that
 * is not — because a host with InnoDB disabled does not fail that CREATE, it
 * quietly substitutes the default engine.
 */
final class Transaction
{
    /**
     * Nesting depth of our own run() calls. 0 means none is open.
     */
    private static int $depth = 0;

    /**
     * Whether something other than us already has a transaction open on this
     * connection. See assumeOpen().
     */
    private static bool $foreignOpen = false;

    /**
     * Callbacks queued by after(), fired once the outermost commit succeeds.
     *
     * @var list<callable(): void>
     */
    private static array $afterCommit = [];

    /**
     * Run $work inside a transaction.
     *
     * Commits when $work returns anything but a WP_Error. Rolls back on a
     * WP_Error return and on any throwable, and rethrows the latter.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function run(callable $work): mixed
    {
        global $wpdb;

        // Our outermost block — the one whose success means the operation
        // happened, and therefore the one that fires the queued hooks.
        $outermost = self::$depth === 0;

        // Whether that block is also the thing that opens the transaction. It
        // is not when someone else already has one open, in which case even our
        // outermost block has to be a savepoint: a second START TRANSACTION
        // would commit theirs.
        $owns = $outermost && !self::$foreignOpen;

        $savepoint = 'folderfolio_sp_' . self::$depth;
        $queued = count(self::$afterCommit);

        if ($owns) {
            $wpdb->query('START TRANSACTION');
        } else {
            $wpdb->query("SAVEPOINT {$savepoint}");
        }

        self::$depth++;

        try {
            $result = $work();
        } catch (Throwable $error) {
            self::undo($owns, $savepoint, $queued);

            throw $error;
        }

        if ($result instanceof WP_Error) {
            self::undo($owns, $savepoint, $queued);

            return $result;
        }

        self::$depth--;

        if ($owns) {
            $wpdb->query('COMMIT');
        } else {
            // Not required for correctness — an unreleased savepoint is
            // discarded at commit — but it frees the server's record of it,
            // which matters when a batch loops through hundreds of these.
            $wpdb->query("RELEASE SAVEPOINT {$savepoint}");
        }

        if ($outermost) {
            self::fire();
        }

        return $result;
    }

    /**
     * Queue something to run after the outermost transaction commits.
     *
     * Used for `do_action()`. Discarded if the transaction — or the enclosing
     * savepoint — is rolled back, so a hook never announces work that was
     * undone.
     *
     * Outside a transaction it runs immediately, so a caller does not have to
     * know whether it is inside one.
     *
     * @param callable(): void $callback
     */
    public static function after(callable $callback): void
    {
        if (self::$depth === 0) {
            $callback();

            return;
        }

        self::$afterCommit[] = $callback;
    }

    /**
     * Whether a transaction of ours is currently open.
     */
    public static function isOpen(): bool
    {
        return self::$depth > 0;
    }

    /**
     * Tell this class that something else has already opened a transaction on
     * this connection, so the next run() takes a savepoint rather than issuing
     * a `START TRANSACTION` that would commit it.
     *
     * `WP_UnitTestCase` is the reason this exists: it wraps every test in a
     * transaction and rolls it back in tearDown to isolate tests from each
     * other. Without this, the first write path under test would commit the
     * harness's transaction, the rollback would then have nothing to undo, and
     * every later test would run against whatever the earlier ones left —
     * which is a test suite that stops meaning anything, in a way that shows up
     * as an unrelated failure somewhere else.
     *
     * Note what this does *not* change: our outermost block is still our
     * outermost block, so `after()` callbacks still fire when it succeeds. It
     * only changes how that block is opened — a savepoint instead of a
     * transaction. A test that asserts a hook fired therefore behaves the same
     * as production, which is the point of running it.
     *
     * @internal Test harnesses only. Never call this from plugin code.
     */
    public static function assumeOpen(): void
    {
        self::$foreignOpen = true;
        self::$depth = 0;
        self::$afterCommit = [];
    }

    /**
     * Forget any state, without touching the database.
     *
     * @internal Test harnesses only.
     */
    public static function reset(): void
    {
        self::$depth = 0;
        self::$foreignOpen = false;
        self::$afterCommit = [];
    }

    /**
     * Roll back to the start of this block and drop anything it queued.
     */
    private static function undo(bool $outermost, string $savepoint, int $queued): void
    {
        global $wpdb;

        self::$depth--;

        // Only this block's queue entries go; an enclosing block may still
        // commit and is entitled to fire its own.
        self::$afterCommit = array_slice(self::$afterCommit, 0, $queued);

        if ($outermost) {
            $wpdb->query('ROLLBACK');

            return;
        }

        $wpdb->query("ROLLBACK TO SAVEPOINT {$savepoint}");
    }

    /**
     * Fire and clear the queue.
     *
     * Cleared before running, so a callback that itself opens a transaction
     * cannot see — or re-fire — the queue it was called from.
     */
    private static function fire(): void
    {
        $callbacks = self::$afterCommit;
        self::$afterCommit = [];

        foreach ($callbacks as $callback) {
            $callback();
        }
    }
}
