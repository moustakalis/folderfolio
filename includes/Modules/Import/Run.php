<?php

declare(strict_types=1);

namespace FolderFolio\Modules\Import;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One import, in progress or finished.
 *
 * Kept in an option rather than a table. Screen 07 says "you can leave this
 * page — the import continues and picks up where it left off", and what makes
 * that true is that the cursor lives on the server, not in the tab. A table
 * for a record there is exactly one of, holding a few hundred integers, would
 * be a migration and an uninstall step for nothing.
 *
 * ## What is stored, and what is not
 *
 * `createdFolderIds` is bounded by the source's folder count — hundreds, at
 * worst thousands. The **assignments** an import makes are not stored, because
 * there can be a hundred thousand of them and they do not need to be: each row
 * carries this run's `id` in its `import_run` column, so undo finds them with
 * one indexed delete and no list.
 *
 * That was a window on `assigned_at` until it met a test: the timestamps are
 * second-granular, and a file filed by hand in the same second — or at any
 * point during a run the user was invited to walk away from — fell inside the
 * window and was deleted by an undo that promises to keep it.
 */
final class Run
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const STOPPING = 'stopping';
    public const DONE = 'done';
    public const UNDONE = 'undone';

    /**
     * @param list<int>                                          $createdFolderIds
     * @param list<int>                                          $touchedFolderIds Created and merged-into alike.
     * @param list<int>                                          $skipped
     * @param list<array{id: int, name: string, reason: string}> $unreachable
     * @param list<string>                                       $warnings What went wrong with one folder or one
     *                                                                     file, without stopping the run — kept
     *                                                                     apart from `error`, which did stop it.
     * @param int|null                                           $lastSourceId The source folder the cursor is after.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $sourceKey,
        public readonly string $sourceLabel,
        public string $status = self::PENDING,
        public int $cursor = 0,
        public int $total = 0,
        public int $foldersCreated = 0,
        public int $foldersMerged = 0,
        public int $duplicatesCollapsed = 0,
        public int $filesAdded = 0,
        public array $createdFolderIds = [],
        public array $touchedFolderIds = [],
        public array $skipped = [],
        public int $skippedTotal = 0,
        public array $unreachable = [],
        public string $startedAt = '',
        public string $finishedAt = '',
        public string $error = '',
        public array $warnings = [],
        public ?int $lastSourceId = null,
        public int $foldersSkipped = 0,
        public int $filesNotImages = 0
    ) {
    }

    /**
     * How many warnings a run keeps. The option is read on every batch, and a
     * source with a thousand bad names needs the count and a sample, not a
     * thousand sentences.
     */
    public const WARNINGS_KEPT = 50;

    public function warn(string $message): void
    {
        if (count($this->warnings) < self::WARNINGS_KEPT) {
            $this->warnings[] = $message;
        }
    }

    public function isFinished(): bool
    {
        return self::DONE === $this->status || self::UNDONE === $this->status;
    }

    public function progress(): int
    {
        if ($this->total < 1) {
            return 100;
        }

        return (int) min(100, round(($this->cursor / $this->total) * 100));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->sourceKey,
            'label' => $this->sourceLabel,
            'status' => $this->status,
            'cursor' => $this->cursor,
            'total' => $this->total,
            'progress' => $this->progress(),
            'folders_created' => $this->foldersCreated,
            'folders_merged' => $this->foldersMerged,
            'duplicates_collapsed' => $this->duplicatesCollapsed,
            'files_added' => $this->filesAdded,
            'skipped' => $this->skipped,
            'skipped_total' => $this->skippedTotal,
            'unreachable' => $this->unreachable,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'error' => $this->error,
            'warnings' => $this->warnings,
            'folders_skipped' => $this->foldersSkipped,
            'files_not_images' => $this->filesNotImages,
            // Undo is offered only while the folders this run made are still
            // the folders it made. Once another import has run, "undo this
            // import" stops being a sentence with one meaning.
            'can_undo' => self::DONE === $this->status,
        ];
    }

    /**
     * @param array<string, mixed> $stored
     */
    public static function fromArray(array $stored): ?self
    {
        if (!is_string($stored['id'] ?? null) || !is_string($stored['source'] ?? null)) {
            return null;
        }

        /** @var list<int> $created */
        $created = array_map('intval', (array) ($stored['created_folder_ids'] ?? []));
        /** @var list<int> $touched */
        $touched = array_map('intval', (array) ($stored['touched_folder_ids'] ?? []));
        /** @var list<int> $skipped */
        $skipped = array_map('intval', (array) ($stored['skipped'] ?? []));

        /** @var list<array{id: int, name: string, reason: string}> $unreachable */
        $unreachable = array_values(array_filter(
            (array) ($stored['unreachable'] ?? []),
            static fn ($row): bool => is_array($row) && isset($row['id'], $row['name'], $row['reason'])
        ));

        return new self(
            (string) $stored['id'],
            (string) $stored['source'],
            (string) ($stored['label'] ?? $stored['source']),
            (string) ($stored['status'] ?? self::PENDING),
            (int) ($stored['cursor'] ?? 0),
            (int) ($stored['total'] ?? 0),
            (int) ($stored['folders_created'] ?? 0),
            (int) ($stored['folders_merged'] ?? 0),
            (int) ($stored['duplicates_collapsed'] ?? 0),
            (int) ($stored['files_added'] ?? 0),
            $created,
            $touched,
            $skipped,
            (int) ($stored['skipped_total'] ?? count($skipped)),
            $unreachable,
            (string) ($stored['started_at'] ?? ''),
            (string) ($stored['finished_at'] ?? ''),
            (string) ($stored['error'] ?? ''),
            array_values(array_map('strval', (array) ($stored['warnings'] ?? []))),
            isset($stored['last_source_id']) ? (int) $stored['last_source_id'] : null,
            (int) ($stored['folders_skipped'] ?? 0),
            (int) ($stored['files_not_images'] ?? 0)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function store(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->sourceKey,
            'label' => $this->sourceLabel,
            'status' => $this->status,
            'cursor' => $this->cursor,
            'total' => $this->total,
            'folders_created' => $this->foldersCreated,
            'folders_merged' => $this->foldersMerged,
            'duplicates_collapsed' => $this->duplicatesCollapsed,
            'files_added' => $this->filesAdded,
            'created_folder_ids' => $this->createdFolderIds,
            'touched_folder_ids' => $this->touchedFolderIds,
            'skipped' => $this->skipped,
            'skipped_total' => $this->skippedTotal,
            'unreachable' => $this->unreachable,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'error' => $this->error,
            'warnings' => $this->warnings,
            'last_source_id' => $this->lastSourceId,
            'folders_skipped' => $this->foldersSkipped,
            'files_not_images' => $this->filesNotImages,
        ];
    }
}
