<?php

declare(strict_types=1);

namespace FolderFolio\Domain;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Database\Transaction;
use WP_Error;

/**
 * Many folders from a list — tier 1 item 4.
 *
 * One name per line, and a slash nests: `Brand/Logos` makes both. That is the
 * whole syntax, deliberately — an indentation dialect on top of it would be a
 * second way to say the same thing, and the first time somebody pastes a list
 * that mixes them the plugin would have to guess.
 *
 * ## The preview is the point
 *
 * `FolderService::getOrCreateByPath()` already creates a path. What is new
 * here is that **nothing is written until the person has seen what would be**,
 * which is the promise the import wizard makes on the same screen and the one
 * every importer this plugin replaces gets wrong. So the work is split in two:
 * `plan()` resolves the list against the tree and writes nothing, `run()` does
 * the same resolution inside a transaction and then carries it out.
 *
 * `run()` re-plans rather than trusting the plan the browser was shown. The
 * two are usually identical; when somebody else has created `Brand` in the
 * meantime they are not, and the one that decides is the one holding the
 * transaction.
 *
 * ## A line that already exists is a no-op, not an error
 *
 * This is what makes a list safe to paste twice, and safe to paste after
 * editing three lines of it. It is the same property that makes the importer
 * safe to run again, and it is why the counts below separate *lines* from
 * *folders*: eleven lines that produce two folders is the normal second run,
 * not a failure.
 *
 * ## Where the resolution can be off by one, and why that is survivable
 *
 * `plan()` matches existing names in PHP, case-folded, against one read of the
 * tree — 1,053 rows on the stress fixture, one query. `run()` matches them in
 * MySQL, through `findByName()`, under the table's own collation. The two
 * agree on every name anybody types; they can disagree on an exotic collation
 * (a site forced to `utf8mb4_bin`, or a Turkish dotted I).
 *
 * When they do, the *outcome* is still right — `getOrCreateByPath()` is the
 * thing that actually decides, and it either finds the row or makes it. Only
 * the count can be out. A preview that is occasionally off by one about how
 * many folders it made is a much better trade than 1,500 queries on every
 * keystroke of a paste.
 */
final class FolderBulk
{
    /**
     * Lines accepted in one submission.
     *
     * Not a performance ceiling — 500 paths is a few thousand inserts inside
     * one transaction, which InnoDB does not notice. It is a ceiling on how
     * much can go wrong in a single unreviewable paste, and on how long a
     * request can hold a transaction open on a shared host. Blank lines do not
     * count against it.
     */
    public const MAX_PATHS = 500;

    public function __construct(
        private readonly FolderService $folders = new FolderService(),
        private readonly FolderRepository $repository = new FolderRepository()
    ) {
    }

    /**
     * What creating this list would do. Writes nothing.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function plan(
        string $text,
        ?int $parentId = null,
        string $objectType = FolderRepository::DEFAULT_OBJECT_TYPE
    ): array|WP_Error {
        $destination = $this->destination($parentId, $objectType);

        if (is_wp_error($destination)) {
            return $destination;
        }

        $lines = $this->parse($text);

        if (is_wp_error($lines)) {
            return $lines;
        }

        $maxDepth = FolderPath::capDepth(apply_filters('folderfolio_max_depth', FolderPath::MAX_DEPTH));

        $index = $this->index($objectType);

        // Where the walk starts. `r:0` is the top level rather than a folder
        // with id 0, which cannot exist — it keeps every key in one namespace
        // so the index and the plan can share a lookup.
        $root = 'r:' . ($destination['id'] ?? 0);

        // Folders an earlier line has already decided to create, so that
        // `Brand/Logos` and `Brand/Icons` make one Brand and not two. Keyed
        // the same way as $index, valued with a synthetic `v:n` id.
        $planned = [];
        $virtual = 0;

        $rows = [];
        $counts = ['lines' => 0, 'folders' => 0, 'unchanged' => 0, 'errors' => 0];

        foreach ($lines as $line) {
            $counts['lines']++;

            if ($line['error'] !== null) {
                $rows[] = $line + ['new_from' => null];
                $counts['errors']++;

                continue;
            }

            $cursor = $root;

            // The position of the first segment that does not exist yet, or
            // null when the whole line is already there. One number rather
            // than a list of names, because nothing below a folder that does
            // not exist can exist either — so "new from here" is the whole
            // truth, and a list would leave the browser matching names to
            // positions and getting "Archive/Archive" wrong.
            $newFrom = null;
            $error = null;

            foreach ($line['segments'] as $position => $segment) {
                // A folder directly under the destination lands one level
                // below it, and the top level is depth 0 — so a destination
                // depth of -1 makes the arithmetic the same either way. This
                // is the same guard FolderService::create() applies; running
                // it here is what turns "the 9th segment will be rejected"
                // from a failed write into a line marked in the preview.
                if ($destination['depth'] + 1 + $position > $maxDepth) {
                    $error = sprintf(
                        /* translators: %d: maximum nesting depth. */
                        __('Folders can be nested up to %d levels deep.', 'folderfolio'),
                        $maxDepth
                    );

                    break;
                }

                $key = $cursor . "\0" . mb_strtolower($segment);

                if (isset($planned[$key])) {
                    $cursor = $planned[$key];

                    continue;
                }

                if (isset($index[$key])) {
                    $cursor = $index[$key];

                    continue;
                }

                // New. Everything below it is new too, and cannot be in
                // $index — its keys all start `r:` — so no further guard is
                // needed to stop the walk matching a real folder under a
                // parent that does not exist yet.
                $cursor = 'v:' . (++$virtual);
                $planned[$key] = $cursor;
                $newFrom ??= $position;
            }

            if ($error !== null) {
                $rows[] = [
                    'text' => $line['text'],
                    'segments' => $line['segments'],
                    'new_from' => null,
                    'error' => $error,
                ];
                $counts['errors']++;

                continue;
            }

            $rows[] = [
                'text' => $line['text'],
                'segments' => $line['segments'],
                'new_from' => $newFrom,
                'error' => null,
            ];

            if ($newFrom === null) {
                $counts['unchanged']++;
            } else {
                $counts['folders'] += count($line['segments']) - $newFrom;
            }
        }

        return [
            'destination' => $destination,
            'limit' => self::MAX_PATHS,
            'counts' => $counts,
            'rows' => $rows,
        ];
    }

    /**
     * Create the list, all of it or none of it.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function run(
        string $text,
        ?int $parentId = null,
        string $objectType = FolderRepository::DEFAULT_OBJECT_TYPE
    ): array|WP_Error {
        /** @var array<string, mixed>|WP_Error $result */
        $result = Transaction::run(function () use ($text, $parentId, $objectType): array|WP_Error {
            $plan = $this->plan($text, $parentId, $objectType);

            if (is_wp_error($plan)) {
                return $plan;
            }

            /** @var array{errors: int, folders: int} $counts */
            $counts = $plan['counts'];

            // All or nothing, and the refusal is the whole submission. A
            // partial run would leave the person reading a list where some
            // lines happened and some did not, with no way to tell which
            // without going to look — which is the state this screen exists
            // to avoid.
            if ($counts['errors'] > 0) {
                return new WP_Error(
                    'folderfolio_bulk_invalid',
                    __('Some lines could not be used. Fix the ones marked below and try again.', 'folderfolio'),
                    ['status' => 400]
                );
            }

            /** @var list<array{segments: list<string>, new_from: ?int}> $rows */
            $rows = $plan['rows'];

            foreach ($rows as $index => $row) {
                // Lines that create nothing are still walked: `getOrCreateByPath()`
                // is where the collation actually decides, and skipping the
                // ones PHP thought were complete would make that decision in
                // the wrong place.
                if ($row['segments'] === []) {
                    continue;
                }

                $folder = $this->folders->getOrCreateByPath(
                    implode('/', $row['segments']),
                    $objectType,
                    $parentId
                );

                if (is_wp_error($folder)) {
                    return $folder;
                }

                // The folder the line ends at, created or found. Folder upload
                // (tier 2 item 9) files each dropped file by this id, so its
                // upload request carries an id and never a path — see
                // Support\UploadTarget. One row per line, in the order sent,
                // blank lines excepted.
                $rows[$index]['folder_id'] = $folder->id;
            }

            $plan['rows'] = $rows;

            return $plan;
        });

        return $result;
    }

    /**
     * One line at a time, into the segments a path is made of.
     *
     * Names are put through `sanitize_text_field()` here rather than left for
     * `FolderService::create()` to do on the way in, so that the name in the
     * preview is the name in the database. Until tier 2 item 9 it mattered
     * for more than honesty — `getOrCreateByPath()` looked a segment up raw
     * and stored it sanitised — and it still keeps `plan()`'s index lookups
     * in the same spelling as the rows they are matched against.
     *
     * @return list<array{text: string, segments: list<string>, error: ?string}>|WP_Error
     */
    private function parse(string $text): array|WP_Error
    {
        $lines = [];

        // Not `\R` with /u: the delimiters are ASCII either way, and the
        // unicode flag would make the whole split fail on one bad byte.
        foreach (preg_split("/\r\n|\r|\n/", $text) ?: [] as $raw) {
            $line = trim($raw);

            // A blank line is how a person separates groups in a pasted list.
            // It is not a folder and it is not a mistake.
            if ($line === '') {
                continue;
            }

            if (count($lines) >= self::MAX_PATHS) {
                return new WP_Error(
                    'folderfolio_bulk_too_many',
                    sprintf(
                        /* translators: %d: maximum number of lines accepted at once. */
                        __('Up to %d lines at a time. Split the list and run it twice.', 'folderfolio'),
                        self::MAX_PATHS
                    ),
                    ['status' => 400]
                );
            }

            $segments = [];
            $error = null;

            foreach (explode('/', $line) as $part) {
                $part = sanitize_text_field($part);

                // Empty segments are dropped rather than refused, so "/a//b/"
                // and "a/b" mean the same thing — matching splitPath(), which
                // is what will read this path back on the way in.
                if ($part === '') {
                    continue;
                }

                if (mb_strlen($part) > 191) {
                    $error = __('Folder names must be 191 characters or fewer.', 'folderfolio');

                    break;
                }

                $segments[] = $part;
            }

            // Reached by a line that is only separators, or only markup the
            // sanitiser removed. It was typed, so it is answered rather than
            // quietly dropped the way a blank line is.
            if ($error === null && $segments === []) {
                $error = __('No folder name on this line.', 'folderfolio');
            }

            $lines[] = ['text' => $line, 'segments' => $segments, 'error' => $error];
        }

        return $lines;
    }

    /**
     * Every folder that exists, as `parentKey\0name` → `r:id`.
     *
     * One read of the tree instead of a `findByName()` per segment. A list of
     * 500 paths three deep is 1,500 lookups; on the 1,053-folder fixture this
     * is one query and an array.
     *
     * @return array<string, string>
     */
    private function index(string $objectType): array
    {
        $index = [];

        foreach ($this->repository->all($objectType) as $row) {
            $parent = $row['parent_id'] === null ? 0 : (int) $row['parent_id'];

            $index['r:' . $parent . "\0" . mb_strtolower((string) $row['name'])] = 'r:' . (int) $row['id'];
        }

        return $index;
    }

    /**
     * Where the list is planted, and how deep that already is.
     *
     * `null` and `0` are both the top level: `0` is what the rail calls
     * Unassigned, which is a view of files rather than a folder, and nothing
     * can be created inside it.
     *
     * @return array{id: ?int, name: string, depth: int}|WP_Error
     */
    private function destination(?int $parentId, string $objectType): array|WP_Error
    {
        if ($parentId === null || $parentId === 0) {
            // -1, so that a segment at position 0 lands at depth 0 — which is
            // what a root folder's own path reports.
            return ['id' => null, 'name' => '', 'depth' => -1];
        }

        $row = $this->repository->find($parentId);

        if ($row === null) {
            return new WP_Error(
                'folderfolio_invalid_parent',
                __('The selected parent folder does not exist.', 'folderfolio'),
                ['status' => 404]
            );
        }

        // One tree per object type (tier 3 item 12): a list is made in the
        // tree it was asked for, and a parent from another is not in it.
        if ((string) $row['object_type'] !== $objectType) {
            return new WP_Error(
                'folderfolio_wrong_type',
                __('That folder holds a different kind of content.', 'folderfolio'),
                ['status' => 400]
            );
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'depth' => FolderPath::depth((string) $row['path']),
        ];
    }
}
