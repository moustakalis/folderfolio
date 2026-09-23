<?php

declare(strict_types=1);

namespace FolderFolio\Domain;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The folder tree, as a file — tier 1 item 6.
 *
 * Nine importers in and nothing out is a roach motel. This is the way out,
 * and it exists for three jobs that are not the same: moving a structure to
 * another site, keeping a copy before a large reorganisation, and being able
 * to leave. The third one is the reason it is in 1.0 rather than 1.1.
 *
 * ## What is in it, and what is deliberately not
 *
 * Every folder's own data: its id, its parent, its name and slug, its colour
 * and icon, its position among its siblings, and the two per-folder orders
 * from `FolderSorts`. **Not `path` and not `depth`** — they are derived from
 * the parent chain, `FolderService` rewrites them on every move, and a file
 * carrying its own copy would be a second source of truth that goes stale the
 * moment anything disagrees.
 *
 * The ids are ours and they are kept. On a re-import they become *source*
 * ids, which is what lets provenance reconcile a second run instead of
 * duplicating everything — the same mechanic `Modules\Import\Provenance`
 * gives the nine plugin importers.
 *
 * ## Assignments are opt-in, and off
 *
 * The tree is a few hundred rows at worst. The file→folder map is one row per
 * filed attachment and there is no ceiling on it: a library with a hundred
 * thousand files has a hundred thousand of them. A default that quietly
 * produces a 20MB download from a button labelled "Export" is not a default.
 *
 * They are a **list of objects**, not an object keyed by folder id. A JSON
 * object's keys are strings, so `{"12": [...]}` comes back from `json_decode`
 * with a string key and every consumer has to remember to cast; a list has no
 * such edge.
 */
final class FolderExport
{
    /**
     * The format's version, not the plugin's.
     *
     * It goes up when a reader written against version 1 would misread a
     * version 2 file — not when a field is added, which an old reader simply
     * ignores. The first thing any importer of this format should do is look
     * at this number and refuse what it does not understand, rather than
     * guess from the shape.
     */
    public const FORMAT = 1;

    public function __construct(
        private readonly FolderRepository $folders = new FolderRepository(),
        private readonly AttachmentFolderRepository $assignments = new AttachmentFolderRepository(),
        private readonly FolderSorts $sorts = new FolderSorts(),
        private readonly FolderKinds $kinds = new FolderKinds()
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function document(
        bool $withAssignments = false,
        string $objectType = FolderRepository::DEFAULT_OBJECT_TYPE
    ): array {
        $sorts = $this->sorts->all();
        $folders = [];

        foreach ($this->folders->all($objectType) as $row) {
            $id = (int) $row['id'];
            $own = $sorts[$id] ?? ['folders' => null, 'files' => null];

            $folders[] = [
                'id' => $id,
                'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
                'name' => (string) $row['name'],
                'slug' => $row['slug'] === null ? null : (string) $row['slug'],
                'color' => $row['color'] === null ? null : (string) $row['color'],
                'icon' => $row['icon'] === null ? null : (string) $row['icon'],
                'sort_order' => (int) $row['sort_order'],
                'sort_folders' => $own['folders'],
                'sort_files' => $own['files'],
                // Tier 3 item 14. Absent from files written before it, which
                // read as plain folders.
                'kind' => $this->kinds->kindOf($id),
            ];
        }

        $document = [
            'folderfolio' => self::FORMAT,
            // The plugin's version as well as the format's: when a file turns
            // up in a support thread, "which build wrote this" is the first
            // question and the format number cannot answer it.
            'plugin' => defined('FOLDERFOLIO_VERSION') ? FOLDERFOLIO_VERSION : null,
            'generated_at' => gmdate('c'),
            // Where it came from, so a file found on a disk a year later can
            // be placed. Not used for anything on import.
            'site' => home_url(),
            'object_type' => $objectType,
            'folders' => $folders,
        ];

        if ($withAssignments) {
            $document['assignments'] = $this->assignmentList();
        }

        return $document;
    }

    /**
     * @return list<array{folder: int, attachments: list<int>}>
     */
    private function assignmentList(): array
    {
        $grouped = [];

        foreach ($this->assignments->all() as $pair) {
            $grouped[$pair['folder_id']][] = $pair['attachment_id'];
        }

        $out = [];

        foreach ($grouped as $folderId => $attachmentIds) {
            $out[] = ['folder' => $folderId, 'attachments' => $attachmentIds];
        }

        return $out;
    }

    /**
     * A filename a person can find again.
     *
     * The host and the date, because the two questions asked of a file in a
     * downloads folder six months later are "which site" and "when". Sanitised
     * because a host can carry a colon (a dev site on a port, like this one's
     * playground:8890) and Windows will not have it in a filename.
     */
    public static function filename(): string
    {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $host = sanitize_key(str_replace('.', '-', $host)) ?: 'site';

        return sprintf('folderfolio-%s-%s.json', $host, gmdate('Y-m-d'));
    }
}
