<?php

declare(strict_types=1);

namespace FolderFolio\Modules\Import;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Domain\FolderExport;
use FolderFolio\Domain\FolderKinds;
use FolderFolio\Domain\FolderSorts;
use FolderFolio\Support\Swatches;
use WP_Error;

/**
 * A FolderFolio export file, read back in — tier 1 item 6b.
 *
 * ## Why this is not in `Catalog::all()`
 *
 * The plan said "a FolderFolio JSON source in Catalog", and that does not
 * survive the `Source` contract. `Catalog::all()` is the list of things to
 * *detect*: every entry is asked `hasData()` and `isPluginActive()` about a
 * plugin whose rows are in this database. A file has neither — it is not on
 * the site until somebody uploads it, and there is no plugin to be active.
 * Listing it there would put a permanent "no data found" row in step 1.
 *
 * So it is built from a decoded document, stored until the next upload
 * replaces it, and found by `Catalog::find()` — which the planner, the
 * runner's every batch and the run payload already go through — as a fallback
 * after the detected sources. Everything else about the wizard, preview
 * included, is the plugin importers' machinery unchanged: the same plan, the
 * same batches, the same report, the same undo.
 *
 * ## The key is the origin site, not "a file"
 *
 * Provenance links an imported folder to `(source key, source folder id)`, and
 * the ids in this file are the exporting site's. One key for every file would
 * let a file from site B reconcile into folders an earlier import from site A
 * made, because the two sites' ids overlap — a silent wrong merge. So the key
 * carries a hash of the site the file names: a second import of that site's
 * export reconciles, however the folders were renamed since; another site's
 * does not.
 *
 * ## Files only from the same site
 *
 * An assignment is `(folder, attachment id)`, and an attachment id means one
 * file on one site. On another site — even one whose media was copied across
 * by WordPress's own importer — the same number is a different file, or none.
 * Filing by those numbers would put the wrong pictures in folders and look
 * like it worked. So assignments are read only when the file names this site;
 * otherwise the folders come and the preview says why the files did not.
 */
final class JsonSource extends Source
{
    /** Not autoloaded: it can carry one row per filed file. */
    public const OPTION = 'folderfolio_import_file';

    /** Every file source's key starts with this; the rest is the origin site. */
    public const KEY_PREFIX = 'ff-file-';

    /** Well past any real tree; a guard against a file that is not one. */
    public const MAX_FOLDERS = 20000;

    /** The database's reason for the last refused `store()`, or ''. */
    private static string $refused = '';

    /**
     * @param list<SourceFolder>       $folders
     * @param array<int, list<int>>    $files       source folder id => attachment ids, empty when not applied
     */
    private function __construct(
        string $key,
        string $label,
        private readonly array $folders,
        private readonly array $files,
        public readonly string $site,
        public readonly bool $sameSite,
        public readonly int $assignmentsInFile
    ) {
        parent::__construct($key, $label, '');
    }

    /**
     * Read a decoded export, or say exactly why it cannot be read.
     *
     * Refused whole rather than read in part: a file that is wrong in one
     * place is not one to trust in the others, and the preview is a promise
     * about the whole of it.
     *
     * @param mixed $document
     */
    public static function fromDocument(mixed $document): self|WP_Error
    {
        if (!is_array($document) || !array_key_exists('folderfolio', $document)) {
            return self::refuse(__('This is not a FolderFolio export file.', 'folderfolio'));
        }

        // The format number first, and nothing guessed from the shape — the
        // rule FolderExport::FORMAT's own comment sets for every reader.
        if ($document['folderfolio'] !== FolderExport::FORMAT) {
            return self::refuse(sprintf(
                /* translators: %s: the format number found in the file. */
                __('This file is in export format %s, which this version of FolderFolio cannot read. Update the plugin and try again.', 'folderfolio'),
                is_scalar($document['folderfolio']) ? (string) $document['folderfolio'] : '?'
            ));
        }

        if (($document['object_type'] ?? 'attachment') !== 'attachment') {
            return self::refuse(__('This file holds folders for something other than the media library.', 'folderfolio'));
        }

        $rows = $document['folders'] ?? null;

        if (!is_array($rows) || array_values($rows) !== $rows || $rows === []) {
            return self::refuse(__('This file has no folders in it.', 'folderfolio'));
        }

        if (count($rows) > self::MAX_FOLDERS) {
            return self::refuse(__('This file has more folders than FolderFolio will read at once.', 'folderfolio'));
        }

        $folders = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            $folder = self::folder($row);

            if ($folder === null || isset($seen[$folder->id])) {
                return self::refuse(sprintf(
                    /* translators: %d: the position of the bad entry in the file, counting from 1. */
                    __('Folder %d in this file is incomplete or repeated, so none of it was read.', 'folderfolio'),
                    $index + 1
                ));
            }

            $seen[$folder->id] = true;
            $folders[] = $folder;
        }

        $site = is_string($document['site'] ?? null) ? (string) $document['site'] : '';
        $sameSite = $site !== '' && self::normalise($site) === self::normalise(home_url());

        $pairs = $document['assignments'] ?? [];

        if (!is_array($pairs) || array_values($pairs) !== $pairs) {
            return self::refuse(__('The file assignments in this file are not in the expected shape.', 'folderfolio'));
        }

        $files = [];
        $inFile = 0;

        foreach ($pairs as $pair) {
            if (
                !is_array($pair)
                || !is_int($pair['folder'] ?? null)
                || !is_array($pair['attachments'] ?? null)
            ) {
                return self::refuse(__('The file assignments in this file are not in the expected shape.', 'folderfolio'));
            }

            $ids = array_values(array_filter(
                $pair['attachments'],
                static fn ($id): bool => is_int($id) && $id > 0
            ));

            $inFile += count($ids);

            if ($sameSite && isset($seen[$pair['folder']])) {
                $files[$pair['folder']] = array_values(array_unique(
                    [...($files[$pair['folder']] ?? []), ...$ids]
                ));
            }
        }

        $host = (string) wp_parse_url($site, PHP_URL_HOST);

        return new self(
            self::KEY_PREFIX . substr(md5(self::normalise($site)), 0, 8),
            $host !== ''
                ? sprintf(
                    /* translators: %s: the host name of the site the file was exported from. */
                    __('FolderFolio export from %s', 'folderfolio'),
                    $host
                )
                : __('FolderFolio export file', 'folderfolio'),
            $folders,
            $files,
            $site,
            $sameSite,
            $inFile
        );
    }

    /**
     * The file uploaded last, if there is one.
     */
    public static function stored(): ?self
    {
        $document = get_option(self::OPTION, null);

        if (!is_array($document)) {
            return null;
        }

        $source = self::fromDocument($document);

        return $source instanceof self ? $source : null;
    }

    /**
     * Keep the document for the run's later batches.
     *
     * The document itself, not this object: `step()` re-reads its source every
     * batch, and re-reading means validating again, which costs one decode of
     * something the size of a tree.
     *
     * **Whether it was kept**, because a write can be refused and
     * `update_option()` does not say so. Measured on 23 Sep: an export at the
     * 20,000-folder ceiling with 100,000 assignments is a 6.2MB option, and a
     * database whose `max_allowed_packet` is 4MB — MySQL 5.7's default —
     * rejects the INSERT. The route then answered as if it had worked and the
     * preview failed a step later with nothing to say why.
     *
     * `update_option()` is also false when the value is unchanged — the same
     * file chosen twice — so false alone is not a refusal. A refusal leaves
     * `$wpdb->last_error`; an unchanged value runs no write at all, and the
     * read before it clears the error.
     *
     * @param array<string, mixed> $document
     */
    public static function store(array $document): bool
    {
        global $wpdb;

        self::$refused = '';

        if (update_option(self::OPTION, $document, false)) {
            return true;
        }

        self::$refused = (string) $wpdb->last_error;

        return self::$refused === '';
    }

    /**
     * Why a document could not be kept, in words a site owner can take to a
     * host. The database's own message is not shown: it names a server
     * variable and nothing about the file.
     *
     * @param array<string, mixed> $document
     */
    public static function refusal(array $document): string
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a server variable, not data; read only to word a refusal.
        $packet = (int) $wpdb->get_var('SELECT @@max_allowed_packet');

        // The database's own reason first. The size is the fallback, and it
        // is escaped because that is what travels: a serialized array is full
        // of quotes, ~15% on top. Even then it is short of the whole query,
        // so the sentence names the limit, which is exact, and not the file's
        // size, which would read "needs 1.0 MB, takes at most 1.0 MB".
        $tooBig = str_contains(self::$refused, 'max_allowed_packet')
            || ($packet > 0 && strlen($wpdb->_real_escape(serialize($document))) >= $packet);

        if ($tooBig && $packet > 0) {
            return sprintf(
                /* translators: %s: the most the database accepts in one write, e.g. "4 MB". */
                __('The file was read, but it is too large for this site’s database to keep while it is imported — the database takes at most %s in one write. Your host can raise its max_allowed_packet setting.', 'folderfolio'),
                // A no-break space, or "1" ends one line and "MB" starts the
                // next — which it did in the step-1 row at 1280.
                str_replace(' ', "\u{00A0}", (string) size_format($packet))
            );
        }

        return __('The file was read, but this site’s database would not keep it for the import. Try again, and ask your host if it keeps happening.', 'folderfolio');
    }

    /**
     * Let go of the stored document once a run from it is over.
     *
     * Kept only while something still reads it: a run re-reads its source
     * every batch, and nothing does after the last one. Undo needs the run
     * record, not the file. A preview that was cancelled leaves it until the
     * next upload replaces it.
     */
    public static function forget(string $sourceKey): void
    {
        if (str_starts_with($sourceKey, self::KEY_PREFIX)) {
            delete_option(self::OPTION);
        }
    }

    /** No plugin, so never "active" — the report's retire line stays away. */
    public function isPluginActive(): bool
    {
        return false;
    }

    public function hasData(): bool
    {
        return $this->folders !== [];
    }

    public function folderCount(): int
    {
        return count($this->folders);
    }

    /** The assignments that will be applied, which is none from another site. */
    public function assignmentCount(): int
    {
        return array_sum(array_map('count', $this->files));
    }

    public function folders(): array
    {
        return $this->folders;
    }

    public function attachmentIdsFor(int $sourceFolderId): array
    {
        return $this->files[$sourceFolderId] ?? [];
    }

    /**
     * What the preview needs to say that the plugin importers never do.
     *
     * @return array{site: string, same_site: bool, assignments_in_file: int, assignments_applied: int}
     */
    public function facts(): array
    {
        return [
            'site' => $this->site,
            'same_site' => $this->sameSite,
            'assignments_in_file' => $this->assignmentsInFile,
            'assignments_applied' => $this->assignmentCount(),
        ];
    }

    /**
     * One folder entry, or null when it is not one.
     *
     * The name is the only field required to be usable: a colour that is not
     * one of the ten, an icon, or an order this version does not know is
     * dropped rather than refusing the file, because each has a sensible
     * absence and none is worth losing a whole tree over.
     */
    private static function folder(mixed $row): ?SourceFolder
    {
        if (!is_array($row) || !is_int($row['id'] ?? null) || $row['id'] <= 0) {
            return null;
        }

        $parent = $row['parent_id'] ?? null;

        if ($parent !== null && (!is_int($parent) || $parent <= 0)) {
            return null;
        }

        $name = is_string($row['name'] ?? null) ? trim((string) $row['name']) : '';

        if ($name === '' || mb_strlen($name) > 191) {
            return null;
        }

        $color = is_string($row['color'] ?? null) ? Swatches::normalize((string) $row['color']) : null;
        $icon = is_string($row['icon'] ?? null) ? sanitize_key((string) $row['icon']) : '';

        return new SourceFolder(
            $row['id'],
            $parent,
            $name,
            is_int($row['sort_order'] ?? null) ? $row['sort_order'] : 0,
            $color !== null && Swatches::isKey($color) ? $color : null,
            $icon !== '' ? $icon : null,
            self::order($row['sort_folders'] ?? null, FolderSorts::FOLDER_ORDERS),
            self::order($row['sort_files'] ?? null, FolderSorts::FILE_ORDERS),
            ($row['kind'] ?? null) === FolderKinds::GALLERY
        );
    }

    /**
     * @param list<string> $allowed
     */
    private static function order(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * A site URL with the parts that do not change which site it is taken off:
     * the scheme, a `www.`, a trailing slash, and case.
     */
    private static function normalise(string $url): string
    {
        $url = strtolower(trim($url));
        $url = (string) preg_replace('#^https?://#', '', $url);
        $url = (string) preg_replace('#^www\.#', '', $url);

        return rtrim($url, '/');
    }

    private static function refuse(string $message): WP_Error
    {
        return new WP_Error('folderfolio_import_file', $message, ['status' => 400]);
    }
}
