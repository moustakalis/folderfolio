<?php

declare(strict_types=1);

namespace FolderFolio\Database;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Domain\AttachmentFolderRepository;
use FolderFolio\Domain\FolderPath;

/**
 * What the Status tab shows — screen 08's third tab.
 *
 * Diagnostics, not a dashboard. Every row here answers a question somebody
 * asks when something looks wrong: how many folders are there really, is the
 * derived path column still in step with parent_id, are there assignment rows
 * pointing at media that no longer exists. Nothing on this tab is a metric to
 * feel good about, and nothing on it links to an upgrade.
 *
 * Read-only, like Doctor, and for the same reason: reporting a problem and
 * fixing it are separate acts. The two repair buttons under the table are
 * their own form posts, and each says what it did.
 *
 * `text()` is the support-ticket form of the same thing. A user who can paste
 * twelve lines into a forum thread saves an exchange of four messages asking
 * for them one at a time.
 *
 * @phpstan-type Row array{label: string, value: string, bad: bool}
 */
final class StatusReport
{
    public function __construct(
        private readonly Doctor $doctor = new Doctor(),
        private readonly AttachmentFolderRepository $assignments = new AttachmentFolderRepository()
    ) {
    }

    /**
     * @return list<Row>
     */
    public function rows(): array
    {
        $findings = $this->doctor->check();
        $library = $this->assignments->libraryCounts();

        return [
            $this->row(__('Schema version', 'folderfolio'), $this->schemaVersion()),
            $this->row(
                __('Folders', 'folderfolio'),
                number_format_i18n($this->folderCount())
            ),
            $this->row(__('Deepest path', 'folderfolio'), $this->deepestPath()),
            $this->row(__('File assignments', 'folderfolio'), $this->assignmentSummary()),
            $this->row(
                __('Files in no folder', 'folderfolio'),
                number_format_i18n($library['unassigned'])
            ),
            $this->orphanRow($findings),
            $this->pathRow($findings),
        ];
    }

    /**
     * The same report as plain text, for pasting into a support thread.
     */
    public function text(): string
    {
        $lines = [
            sprintf('FolderFolio %s', FOLDERFOLIO_VERSION),
            sprintf('WordPress %s, PHP %s', get_bloginfo('version'), PHP_VERSION),
            sprintf('Multisite: %s', is_multisite() ? 'yes' : 'no'),
            '',
        ];

        foreach ($this->rows() as $row) {
            $lines[] = sprintf('%s: %s', $row['label'], $row['value']);
        }

        $findings = $this->doctor->check();

        if ([] !== $findings) {
            $lines[] = '';
            $lines[] = 'Findings:';

            foreach ($findings as $finding) {
                $lines[] = sprintf(
                    '- [%s] %s (%d) %s',
                    $finding['severity'],
                    $finding['code'],
                    $finding['count'],
                    // A sample of ids, because "12 folders are broken" is not
                    // something anyone can act on and the full list can be
                    // thousands.
                    [] === $finding['ids'] ? '' : 'ids: ' . implode(', ', $finding['ids'])
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{code: string, severity: string, message: string, count: int, ids: list<int>}> $findings
     *
     * @return Row
     */
    private function orphanRow(array $findings): array
    {
        $orphans = 0;

        foreach ($findings as $finding) {
            if (in_array($finding['code'], ['orphaned_assignment', 'assignment_without_media'], true)) {
                $orphans += $finding['count'];
            }
        }

        return $this->row(
            __('Orphaned assignments', 'folderfolio'),
            number_format_i18n($orphans),
            $orphans > 0
        );
    }

    /**
     * @param list<array{code: string, severity: string, message: string, count: int, ids: list<int>}> $findings
     *
     * @return Row
     */
    private function pathRow(array $findings): array
    {
        $drifted = 0;

        foreach ($findings as $finding) {
            if (in_array($finding['code'], ['path_drift', 'depth_drift', 'missing_parent', 'cycle'], true)) {
                $drifted += $finding['count'];
            }
        }

        if (0 === $drifted) {
            return $this->row(
                __('Materialised paths', 'folderfolio'),
                __('In step with the adjacency list', 'folderfolio')
            );
        }

        return $this->row(
            __('Materialised paths', 'folderfolio'),
            sprintf(
                /* translators: %s is a number of folders. */
                _n(
                    '%s folder disagrees with its parent — rebuild paths',
                    '%s folders disagree with their parent — rebuild paths',
                    $drifted,
                    'folderfolio'
                ),
                number_format_i18n($drifted)
            ),
            true
        );
    }

    private function schemaVersion(): string
    {
        $stored = (string) get_option('folderfolio_db_version', '0');
        $current = \FolderFolio\Plugin::DB_VERSION;

        if ($stored === $current) {
            /* translators: %s is a schema version number. */
            return sprintf(__('%s — current', 'folderfolio'), $stored);
        }

        return sprintf(
            /* translators: 1: stored schema version, 2: the version this release expects. */
            __('%1$s — upgrade to %2$s runs on the next admin request', 'folderfolio'),
            '' === $stored ? '0' : $stored,
            $current
        );
    }

    private function folderCount(): int
    {
        global $wpdb;

        // Identifiers cannot be bound, and this one is built from $wpdb->prefix.
        return (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'folderfolio_folders'
        );
    }

    /**
     * The longest branch, named rather than numbered.
     *
     * "5 levels" alone sends the reader hunting for which branch it is; the
     * trail is read straight out of the materialised path, which is what that
     * column is for.
     */
    private function deepestPath(): string
    {
        global $wpdb;

        $table = $wpdb->prefix . 'folderfolio_folders';

        /** @var array{path: string, depth: string}|null $deepest */
        $deepest = $wpdb->get_row(
            "SELECT path, depth FROM {$table} ORDER BY depth DESC, id ASC LIMIT 1",
            ARRAY_A
        );

        if (null === $deepest) {
            return __('No folders yet', 'folderfolio');
        }

        $ids = FolderPath::ids((string) $deepest['path']);

        if ([] === $ids) {
            return __('No folders yet', 'folderfolio');
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));

        /** @var list<array{id: string, name: string}> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT id, name FROM {$table} WHERE id IN ({$placeholders})",
                ...$ids
            ),
            ARRAY_A
        ) ?: [];

        $names = [];

        foreach ($rows as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }

        // Ordered by the path, not by whatever order the IN() came back in.
        $trail = [];

        foreach ($ids as $id) {
            if (isset($names[$id])) {
                $trail[] = $names[$id];
            }
        }

        $levels = (int) $deepest['depth'] + 1;

        return sprintf(
            /* translators: 1: number of levels, 2: the folder trail, e.g. "Brand / Logos". */
            _n('%1$s level (%2$s)', '%1$s levels (%2$s)', $levels, 'folderfolio'),
            number_format_i18n($levels),
            implode(' / ', $trail)
        );
    }

    /**
     * Rows and distinct files, because they are not the same number.
     *
     * A file filed in two folders is two rows and one file. Showing only the
     * row count makes a library look twice its size; showing only the file
     * count hides the thing that actually grows the table.
     */
    private function assignmentSummary(): string
    {
        global $wpdb;

        $table = $wpdb->prefix . 'folderfolio_attachment_folders';

        // `rows` is reserved in MySQL 8, so the aliases are not the obvious
        // words. An unquoted `AS rows` is a syntax error there and works on
        // 5.7, which is the kind of difference that ships.
        /** @var array{row_count: string, file_count: string}|null $counts */
        $counts = $wpdb->get_row(
            "SELECT COUNT(*) AS row_count, COUNT(DISTINCT attachment_id) AS file_count FROM {$table}",
            ARRAY_A
        );

        $rows = (int) ($counts['row_count'] ?? 0);
        $files = (int) ($counts['file_count'] ?? 0);

        return sprintf(
            /* translators: 1: e.g. "41 rows". 2: e.g. "38 distinct files". */
            __('%1$s, %2$s', 'folderfolio'),
            sprintf(
                /* translators: %s is a number of assignment rows. */
                _n('%s row', '%s rows', $rows, 'folderfolio'),
                number_format_i18n($rows)
            ),
            sprintf(
                /* translators: %s is a number of distinct files. */
                _n('%s distinct file', '%s distinct files', $files, 'folderfolio'),
                number_format_i18n($files)
            )
        );
    }

    /**
     * @return Row
     */
    private function row(string $label, string $value, bool $bad = false): array
    {
        return ['label' => $label, 'value' => $value, 'bad' => $bad];
    }
}
