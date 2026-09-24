<?php

declare(strict_types=1);

namespace FolderFolio\Domain;

use FolderFolio\Support\ZipWriter;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What goes into a folder's ZIP, and writing it — tier 2 item 11.
 *
 * Nick's answers (board `PpiAmXsixk3sG9yygJQnw5`):
 *
 * - **The folder and everything under it, as directories.** The archive's
 *   one top-level directory is the folder itself; each subfolder is a
 *   directory inside it, and an empty one still arrives. A file filed in two
 *   of them is in the archive twice, once in each — membership is
 *   many-to-many, and that is where the person filed it.
 * - **The original upload**, when WordPress kept one beside its `-scaled`
 *   copy (`wp_get_original_image_path()`); the attached file otherwise.
 * - **What cannot go in is named, not dropped silently**: a file missing from
 *   disk, or stored outside `uploads/`, is listed in `not-included.txt`. A
 *   file this person may not read is counted there and not named — its name
 *   is part of what they may not read.
 *
 * The manifest is built before a byte is written, and it is the same every
 * time for the same folder and files, so the length and an ETag come from it:
 * the response can carry a Content-Length and serve a range to resume.
 *
 * @phpstan-type Entry array{kind: 'dir'|'file'|'note', name: string, size: int, mtime: int, path?: string, id?: int, crc?: int|null, key?: string, content?: string}
 * @phpstan-type Manifest array{folder: Folder, filename: string, entries: list<Entry>, files: int, bytes: int, left_out: int, length: int, etag: string}
 */
final class FolderArchive
{
    /** Each file's CRC-32, kept against its size and modification time. */
    public const CRC_META = '_folderfolio_crc32';

    public function __construct(
        private readonly FolderService $folders = new FolderService(),
        private readonly FolderRepository $repository = new FolderRepository()
    ) {
    }

    /**
     * @return Manifest|null Null when there is no such folder.
     */
    public function manifest(int $folderId): ?array
    {
        $root = $this->folders->get($folderId);

        // Only media folders hold files. A folder of posts is not "not
        // found", but there is nothing in it a ZIP could carry — and the
        // download route answers the same way for both, so it says less.
        if (null === $root || FolderRepository::DEFAULT_OBJECT_TYPE !== $root->objectType) {
            return null;
        }

        $names = new ArchiveNames();
        $rows = $this->repository->subtree($root->path);

        /*
         * The directories first, parents before children — the query orders
         * by depth — so every folder's directory exists by the time its
         * children ask for it. A row whose parent is not in the map (a broken
         * path) is left out rather than hoisted somewhere it does not belong.
         */
        $directories = [
            $root->id => $names->unique('', ArchiveNames::clean($root->name, self::fallback($root->id)), true) . '/',
        ];

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            if ($id === $root->id || !isset($directories[(int) $row['parent_id']])) {
                continue;
            }

            $parent = $directories[(int) $row['parent_id']];
            $directories[$id] = $parent
                . $names->unique($parent, ArchiveNames::clean((string) $row['name'], self::fallback($id)), true) . '/';
        }

        $filed = [];

        foreach (array_keys($directories) as $id) {
            $filed[$id] = $this->folders->orderedAttachmentIds($id);
        }

        $all = array_values(array_unique(array_merge(...array_values($filed))));

        if ([] !== $all) {
            _prime_post_caches($all, false, true);
        }

        $entries = [];
        $leftOut = [];
        $hidden = 0;
        $files = 0;
        $bytes = 0;
        $latest = 0;
        $uploads = self::uploadsRoot();

        foreach ($directories as $id => $directory) {
            $entries[] = ['kind' => 'dir', 'name' => $directory, 'size' => 0, 'mtime' => 0];

            foreach ($filed[$id] as $attachmentId) {
                $post = get_post($attachmentId);

                // A row whose post has gone, or is in the trash: not a file in
                // this folder by any measure the library would show.
                if (!$post instanceof \WP_Post || 'attachment' !== $post->post_type
                    || !in_array($post->post_status, ['inherit', 'private'], true)) {
                    continue;
                }

                if (!current_user_can('read_post', $attachmentId)) {
                    $hidden++;

                    continue;
                }

                $path = self::sourceOf($attachmentId);
                $label = $directory . ArchiveNames::clean(
                    null !== $path ? wp_basename($path) : (string) get_the_title($attachmentId),
                    self::fallback($attachmentId)
                );

                if (null === $path || !is_file($path)) {
                    $leftOut[] = [$label, __('the file is missing from the server', 'folderfolio')];

                    continue;
                }

                if (null === $uploads || !self::isInside($path, $uploads)) {
                    $leftOut[] = [$label, __('the file is stored outside the uploads folder', 'folderfolio')];

                    continue;
                }

                $size = filesize($path);
                $mtime = filemtime($path);

                if (false === $size || false === $mtime || !is_readable($path)) {
                    $leftOut[] = [$label, __('the file could not be read', 'folderfolio')];

                    continue;
                }

                $key = $size . ':' . $mtime;
                $cached = (string) get_post_meta($attachmentId, self::CRC_META, true);
                $crc = str_starts_with($cached, $key . ':') ? (int) substr($cached, strlen($key) + 1) : null;
                $local = self::local($mtime);
                $latest = max($latest, $local);

                $entries[] = [
                    'kind' => 'file',
                    'name' => $directory . $names->unique(
                        $directory,
                        ArchiveNames::clean(wp_basename($path), self::fallback($attachmentId))
                    ),
                    'size' => $size,
                    'mtime' => $local,
                    'path' => $path,
                    'id' => $attachmentId,
                    'crc' => $crc,
                    'key' => $key,
                ];

                $files++;
                $bytes += $size;
            }
        }

        $note = self::note($leftOut, $hidden);

        if (null !== $note) {
            $rootDirectory = $directories[$root->id];
            $entries[] = [
                'kind' => 'note',
                'name' => $rootDirectory . $names->unique($rootDirectory, 'not-included.txt'),
                'size' => strlen($note),
                'mtime' => 0,
                'content' => $note,
            ];
        }

        // Directories and the note carry the newest file's time, or the
        // folder's own when it holds none: a fixed value, so the bytes — and
        // with them the ETag and any range — are the same on every request.
        $stamp = $latest > 0 ? $latest : self::local((int) strtotime($root->updatedAt . ' UTC'));

        foreach ($entries as $i => $entry) {
            if ('file' !== $entry['kind']) {
                $entries[$i]['mtime'] = $stamp;
            }
        }

        $length = ZipWriter::length(array_map(
            static fn (array $entry): array => [$entry['name'], $entry['size']],
            $entries
        ));

        return [
            'folder' => $root,
            'filename' => rtrim($directories[$root->id], '/') . '.zip',
            'entries' => $entries,
            'files' => $files,
            'bytes' => $bytes,
            'left_out' => count($leftOut) + $hidden,
            'length' => $length,
            'etag' => '"' . md5((string) wp_json_encode([
                FOLDERFOLIO_VERSION,
                array_map(
                    static fn (array $entry): array => [$entry['name'], $entry['size'], $entry['mtime'], $entry['id'] ?? 0],
                    $entries
                ),
            ])) . '"',
        ];
    }

    /**
     * Write a folder's archive to a file — `wp folderfolio folder zip` and
     * `FolderFolio::zipFolder()` (24 Sep).
     *
     * The same manifest and bytes as the download, without its size limit:
     * that limit is about one HTTP response on a host that kills long ones,
     * and a command on the server is the way round it the refusal would
     * suggest if it could. An existing file is not replaced — pass a path
     * that is not there yet. A directory gets the download's own file name.
     *
     * @return array{path: string, files: int, bytes: int, left_out: int, length: int}|WP_Error
     */
    public function toFile(int $folderId, string $path): array|WP_Error
    {
        $manifest = $this->manifest($folderId);

        if (null === $manifest) {
            return new WP_Error(
                'folderfolio_folder_not_found',
                __('Folder not found. Only a media folder can be zipped.', 'folderfolio'),
                ['status' => 404]
            );
        }

        if (is_dir($path)) {
            $path = trailingslashit($path) . $manifest['filename'];
        }

        if (file_exists($path)) {
            return new WP_Error(
                'folderfolio_zip_exists',
                /* translators: %s: a file path. */
                sprintf(__('%s is already there. Choose another name, or move that file first.', 'folderfolio'), $path),
                ['status' => 409]
            );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = is_dir(dirname($path)) && is_writable(dirname($path)) ? fopen($path, 'xb') : false;

        if (false === $handle) {
            return new WP_Error(
                'folderfolio_zip_unwritable',
                /* translators: %s: a file path. */
                sprintf(__('%s could not be written. Check the folder exists and can be written to.', 'folderfolio'), $path),
                ['status' => 500]
            );
        }

        try {
            $this->write($manifest, static function (string $bytes) use ($handle): void {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
                if (false === fwrite($handle, $bytes)) {
                    throw new \RuntimeException('the disk refused a write');
                }
            });
        } catch (\RuntimeException $error) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($handle);
            wp_delete_file($path);

            return new WP_Error(
                'folderfolio_zip_failed',
                /* translators: %s: why, in a few words. */
                sprintf(__('The ZIP could not be finished: %s.', 'folderfolio'), $error->getMessage()),
                ['status' => 500]
            );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($handle);

        return [
            'path' => $path,
            'files' => $manifest['files'],
            'bytes' => $manifest['bytes'],
            'left_out' => $manifest['left_out'],
            'length' => $manifest['length'],
        ];
    }

    /**
     * Write the archive to `$out`, from byte `$skip`.
     *
     * A CRC read here is kept for next time, so a second download of the same
     * folder — or a resumed one — reads each file once rather than twice.
     *
     * @param Manifest                $manifest
     * @param callable(string): void  $out
     */
    public function write(array $manifest, callable $out, int $skip = 0): void
    {
        $writer = new ZipWriter($out, $skip);

        foreach ($manifest['entries'] as $entry) {
            if ('dir' === $entry['kind']) {
                $writer->addDirectory($entry['name'], $entry['mtime']);

                continue;
            }

            if ('note' === $entry['kind']) {
                $writer->addString($entry['name'], (string) ($entry['content'] ?? ''), $entry['mtime']);

                continue;
            }

            $known = $entry['crc'] ?? null;
            $crc = $writer->addFile($entry['name'], (string) ($entry['path'] ?? ''), $entry['size'], $entry['mtime'], $known);

            if (null === $known && isset($entry['id'], $entry['key'])) {
                update_post_meta($entry['id'], self::CRC_META, $entry['key'] . ':' . $crc);
            }
        }

        $writer->finish();
    }

    /**
     * The file to put in the archive: the original upload if WordPress kept
     * one beside a `-scaled` copy, the attached file otherwise.
     */
    private static function sourceOf(int $attachmentId): ?string
    {
        if (function_exists('wp_get_original_image_path')) {
            $original = wp_get_original_image_path($attachmentId);

            if (is_string($original) && '' !== $original && is_file($original)) {
                return $original;
            }
        }

        $attached = get_attached_file($attachmentId);

        return is_string($attached) && '' !== $attached ? $attached : null;
    }

    private static function uploadsRoot(): ?string
    {
        $base = realpath((string) wp_get_upload_dir()['basedir']);

        return false === $base ? null : $base;
    }

    /**
     * Whether a path resolves inside the uploads directory.
     *
     * `get_attached_file()` joins the uploads directory with a stored meta
     * value, and a stored value can be `../../wp-config.php`. The archive
     * holds media, and only media.
     */
    private static function isInside(string $path, string $root): bool
    {
        $real = realpath($path);

        return false !== $real && str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }

    /** A timestamp moved into the site's time zone — ZIP times have none of their own. */
    private static function local(int $timestamp): int
    {
        return $timestamp + wp_timezone()->getOffset(new \DateTimeImmutable('@' . $timestamp));
    }

    private static function fallback(int $id): string
    {
        return (string) $id;
    }

    /**
     * @param list<array{0: string, 1: string}> $leftOut
     */
    private static function note(array $leftOut, int $hidden): ?string
    {
        if ([] === $leftOut && 0 === $hidden) {
            return null;
        }

        $lines = [__('These files are in the folder but not in this download:', 'folderfolio'), ''];

        foreach ($leftOut as [$name, $reason]) {
            /* translators: 1: a file's path inside the download, 2: why it was left out. */
            $lines[] = sprintf(__('%1$s — %2$s', 'folderfolio'), $name, $reason);
        }

        if ($hidden > 0) {
            $lines[] = sprintf(
                /* translators: %d: how many files. */
                _n(
                    '%d file you do not have permission to view.',
                    '%d files you do not have permission to view.',
                    $hidden,
                    'folderfolio'
                ),
                $hidden
            );
        }

        // CRLF, so the note reads as lines in Notepad as well as everywhere else.
        return implode("\r\n", $lines) . "\r\n";
    }
}
