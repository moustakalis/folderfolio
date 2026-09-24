<?php

declare(strict_types=1);

namespace FolderFolio\Database;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Domain\AttachmentFolderRepository;
use FolderFolio\Domain\FolderPath;
use FolderFolio\Support\PostTypes;

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

        $rows = [
            $this->row(__('Database', 'folderfolio'), $this->schemaVersion()),
            $this->row(__('Folders', 'folderfolio'), $this->folderSummary()),
            $this->row(__('Deepest folder', 'folderfolio'), $this->deepestPath()),
            $this->row(__('Files in folders', 'folderfolio'), $this->assignmentSummary()),
            $this->row(
                __('Files in no folder', 'folderfolio'),
                number_format_i18n($library['unassigned'])
            ),
        ];

        // Tier 3 item 12: posts and pages are filed in the same table. Counted
        // apart, so a filed page is never reported as a file — and only when
        // there is something to say, so a media-only site reads as it did.
        $elsewhere = $this->filedElsewhere();

        if ($elsewhere !== '') {
            $rows[] = $this->row(__('Filed on other screens', 'folderfolio'), $elsewhere);
        }

        $rows[] = $this->orphanRow($findings);
        $rows[] = $this->pathRow($findings);

        return $rows;
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
            __('Files pointing at nothing', 'folderfolio'),
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
                __('Folder tree', 'folderfolio'),
                __('Every folder agrees with its parent', 'folderfolio')
            );
        }

        return $this->row(
            __('Folder tree', 'folderfolio'),
            sprintf(
                /* translators: %s is a number of folders. */
                _n(
                    '%s folder disagrees with its parent — run the repair below',
                    '%s folders disagree with their parent — run the repair below',
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
            return sprintf(__('Up to date (version %s)', 'folderfolio'), $stored);
        }

        return sprintf(
            /* translators: 1: stored schema version, 2: the version this release expects. */
            __('Version %1$s — the update to %2$s runs on the next admin page you open', 'folderfolio'),
            '' === $stored ? '0' : $stored,
            $current
        );
    }

    /**
     * Every folder, and — once a second tree exists — how many are in each,
     * named as the screens name them: "1,053 · Media 1,049 · Pages 4".
     */
    private function folderSummary(): string
    {
        global $wpdb;

        // Identifiers cannot be bound, and this one is built from $wpdb->prefix.
        /** @var list<array{object_type: string, n: string}> $counts */
        $counts = $wpdb->get_results(
            'SELECT object_type, COUNT(*) AS n FROM ' . $wpdb->prefix . 'folderfolio_folders GROUP BY object_type ORDER BY object_type = \'attachment\' DESC, object_type',
            ARRAY_A
        ) ?: [];

        $total = (int) array_sum(array_column($counts, 'n'));

        if (count($counts) < 2) {
            return number_format_i18n($total);
        }

        $parts = [number_format_i18n($total)];

        foreach ($counts as $count) {
            $parts[] = sprintf(
                /* translators: 1: a screen's name, e.g. "Pages", 2: how many folders it has. */
                __('%1$s %2$s', 'folderfolio'),
                PostTypes::label((string) $count['object_type']),
                number_format_i18n((int) $count['n'])
            );
        }

        return implode(' · ', $parts);
    }

    /**
     * Posts, pages and other items filed in folders on their own screens —
     * "Pages 3 · Posts 1" — or '' when there are none.
     */
    private function filedElsewhere(): string
    {
        global $wpdb;

        $table = $wpdb->prefix . 'folderfolio_attachment_folders';
        $folders = $wpdb->prefix . 'folderfolio_folders';

        /** @var list<array{object_type: string, n: string}> $counts */
        $counts = $wpdb->get_results(
            "SELECT f.object_type, COUNT(DISTINCT a.attachment_id) AS n
             FROM {$table} a
             JOIN {$folders} f ON f.id = a.folder_id
             WHERE f.object_type <> 'attachment'
             GROUP BY f.object_type
             ORDER BY f.object_type",
            ARRAY_A
        ) ?: [];

        $parts = [];

        foreach ($counts as $count) {
            $parts[] = sprintf(
                /* translators: 1: a screen's name, e.g. "Pages", 2: how many of its items are in folders. */
                __('%1$s %2$s', 'folderfolio'),
                PostTypes::label((string) $count['object_type']),
                number_format_i18n((int) $count['n'])
            );
        }

        return implode(' · ', $parts);
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

        /** @var array{path: string, depth: string, object_type: string}|null $deepest */
        $deepest = $wpdb->get_row(
            "SELECT path, depth, object_type FROM {$table} ORDER BY depth DESC, id ASC LIMIT 1",
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

        $where = sprintf(
            /* translators: 1: number of levels, 2: the folder trail, e.g. "Brand / Logos". */
            _n('%1$s level down: %2$s', '%1$s levels down: %2$s', $levels, 'folderfolio'),
            number_format_i18n($levels),
            implode(' › ', $trail)
        );

        // A folder in another tree says which: "Landing › 2024" alone would
        // be looked for in the media library.
        if ((string) $deepest['object_type'] !== PostTypes::MEDIA) {
            $where = sprintf(
                /* translators: 1: e.g. "3 levels down: Landing › 2024", 2: a screen's name, e.g. "Pages". */
                __('%1$s (%2$s)', 'folderfolio'),
                $where,
                PostTypes::label((string) $deepest['object_type'])
            );
        }

        return $where;
    }

    /**
     * Filings and files, because they are not the same number.
     *
     * A file filed in two folders is two filings and one file. Showing only
     * the filing count makes a library look twice its size; showing only the
     * file count hides the thing that actually grows the table.
     *
     * It used to say "41 rows, 38 distinct files" — the shape of the query
     * rather than the shape of the library, on the tab a worried site owner
     * opens first.
     */
    private function assignmentSummary(): string
    {
        global $wpdb;

        $table = $wpdb->prefix . 'folderfolio_attachment_folders';

        // `rows` is reserved in MySQL 8, so the aliases are not the obvious
        // words. An unquoted `AS rows` is a syntax error there and works on
        // 5.7, which is the kind of difference that ships.
        $folders = $wpdb->prefix . 'folderfolio_folders';

        // Media folders only: a page filed in a Pages folder is not a file
        // (tier 3 item 12 put both in this table). `filedElsewhere()` says
        // those.
        /** @var array{row_count: string, file_count: string}|null $counts */
        $counts = $wpdb->get_row(
            "SELECT COUNT(*) AS row_count, COUNT(DISTINCT a.attachment_id) AS file_count
             FROM {$table} a
             JOIN {$folders} f ON f.id = a.folder_id
             WHERE f.object_type = 'attachment'",
            ARRAY_A
        );

        $rows = (int) ($counts['row_count'] ?? 0);
        $files = (int) ($counts['file_count'] ?? 0);

        return sprintf(
            /* translators: 1: e.g. "38 files". 2: e.g. "filed 41 times". */
            __('%1$s, %2$s', 'folderfolio'),
            sprintf(
                /* translators: %s is a number of files. */
                _n('%s file', '%s files', $files, 'folderfolio'),
                number_format_i18n($files)
            ),
            sprintf(
                /* translators: %s is a number of times a file has been filed. */
                _n('filed %s time', 'filed %s times', $rows, 'folderfolio'),
                number_format_i18n($rows)
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
