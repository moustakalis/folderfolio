<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

use FolderFolio\Domain\FolderArchive;
use FolderFolio\Support\Capabilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Download a folder as a ZIP — the response itself (tier 2 item 11).
 *
 * `admin-post.php`, not a REST route: REST answers JSON through output
 * buffers and filters that want the whole body, and this body is gigabytes
 * written as it is read. And a GET, not a POST: a browser's Resume re-sends
 * the download's own request with a `Range` header, which it can do for a
 * GET and never for a form post. The nonce is in the URL for that reason; it
 * is a CSRF token bound to this person and this folder, not a credential —
 * the request still needs their login cookie.
 *
 * The rail asks `GET /folders/{id}/zip` first (count, size, the confirmation
 * above a size) and then points the browser here; see `FolderController`.
 */
final class FolderDownload
{
    public const ACTION = 'folderfolio_zip';

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    /** The download's URL, for this person, for this folder. */
    public static function url(int $folderId): string
    {
        return add_query_arg(
            [
                'action' => self::ACTION,
                'folder' => $folderId,
                '_wpnonce' => wp_create_nonce(self::ACTION . '-' . $folderId),
            ],
            admin_url('admin-post.php')
        );
    }

    /** Above this, the rail says the size and asks before starting. */
    public static function confirmBytes(): int
    {
        /**
         * Filters the folder size, in bytes, above which the rail asks before
         * a ZIP download starts. 1 GB by default.
         *
         * @param int $bytes
         */
        return max(0, (int) apply_filters('folderfolio_zip_confirm_bytes', GB_IN_BYTES));
    }

    /** Above this, the download is refused. 0 — the default — is no limit. */
    public static function maxBytes(): int
    {
        /**
         * Filters the largest folder, in bytes, that may be downloaded as a
         * ZIP. 0, the default, is no limit: the archive is streamed, so its
         * size costs no memory and no disk — only the time the transfer takes,
         * which is the host's to limit if it wants to.
         *
         * @param int $bytes
         */
        return max(0, (int) apply_filters('folderfolio_zip_max_bytes', 0));
    }

    public static function allowed(): bool
    {
        return Capabilities::canUseFolders() && Capabilities::can('download');
    }

    public function handle(): void
    {
        $folderId = isset($_GET['folder']) ? absint(wp_unslash($_GET['folder'])) : 0;
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

        if (!wp_verify_nonce($nonce, self::ACTION . '-' . $folderId)) {
            $this->fail(403, __('This download link has expired. Start the download again from the folder’s menu.', 'folderfolio'));
        }

        if (!self::allowed()) {
            $this->fail(403, __('You are not allowed to download folders.', 'folderfolio'));
        }

        $archive = new FolderArchive();
        $manifest = $archive->manifest($folderId);

        if (null === $manifest) {
            $this->fail(404, __('Folder not found.', 'folderfolio'));
        }

        $max = self::maxBytes();

        if ($max > 0 && $manifest['bytes'] > $max) {
            $this->fail(413, sprintf(
                /* translators: %s: a size such as "2 GB". */
                __('This folder is larger than this site allows in one download (%s). Download a subfolder at a time instead.', 'folderfolio'),
                size_format($max)
            ));
        }

        $length = $manifest['length'];
        [$start, $end] = $this->range($length, $manifest['etag']);

        $this->prepareOutput();

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; ' . self::filename($manifest['filename']));
        header('Accept-Ranges: bytes');
        header('ETag: ' . $manifest['etag']);
        header('X-Content-Type-Options: nosniff');
        // nginx: send it as it comes rather than buffering it to disk first.
        header('X-Accel-Buffering: no');

        if (0 !== $start || $end !== $length - 1) {
            status_header(206);
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $length));
        }

        header('Content-Length: ' . ($end - $start + 1));

        if ('HEAD' === strtoupper(sanitize_key(wp_unslash((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))))) {
            exit;
        }

        $remaining = $end - $start + 1;

        try {
            $archive->write(
                $manifest,
                static function (string $bytes) use (&$remaining): void {
                    if (strlen($bytes) >= $remaining) {
                        echo substr($bytes, 0, $remaining); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary archive bytes.
                        flush();

                        throw new \OverflowException('range complete');
                    }

                    echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary archive bytes.
                    $remaining -= strlen($bytes);
                    flush();
                },
                $start
            );
        } catch (\OverflowException) {
            // The range asked for ended before the archive did.
        } catch (\RuntimeException $error) {
            // A file changed or vanished mid-download. Nothing can be said to
            // the browser now — headers and bytes are out — so the connection
            // closes short of its Content-Length and the browser reports the
            // download as failed, which is true.
            error_log('FolderFolio ZIP: ' . $error->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }

        exit;
    }

    /**
     * The one byte range asked for, or the whole archive.
     *
     * One range only (`bytes=500-`, `bytes=500-999`, `bytes=-500`): it is what
     * a browser's Resume sends. An `If-Range` that names another ETag means
     * the folder changed since the first part was saved, so the whole new
     * archive is sent instead — the browser starts over, as it must.
     *
     * @return array{0: int, 1: int}
     */
    private function range(int $length, string $etag): array
    {
        $whole = [0, $length - 1];
        $header = isset($_SERVER['HTTP_RANGE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_RANGE'])) : '';

        if ('' === $header || 1 !== preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) {
            return $whole;
        }

        // An ETag or a date, compared byte for byte with our own ETag and never
        // printed: sanitising it could only turn a match into a mismatch.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $ifRange = isset($_SERVER['HTTP_IF_RANGE']) ? trim(wp_unslash((string) $_SERVER['HTTP_IF_RANGE'])) : '';

        if ('' !== $ifRange && $ifRange !== $etag) {
            return $whole;
        }

        if ('' === $m[1]) {
            if ('' === $m[2]) {
                return $whole;
            }

            // The last N bytes.
            return [max(0, $length - (int) $m[2]), $length - 1];
        }

        $start = (int) $m[1];
        $end = '' === $m[2] ? $length - 1 : min((int) $m[2], $length - 1);

        if ($start >= $length || $start > $end) {
            status_header(416);
            header('Content-Range: bytes */' . $length);
            exit;
        }

        return [$start, $end];
    }

    /**
     * Everything between these bytes and the browser that would hold them.
     *
     * Any open output buffer (ours, WordPress's, a caching plugin's) would
     * collect the whole archive in memory; `zlib.output_compression` would
     * compress a ZIP of JPEGs for nothing and drop the Content-Length. The time
     * limit comes off where the host allows it — though on Linux it counts
     * CPU time, and a download mostly waits on the network (measured: 0.95s of
     * CPU over a 49s download).
     */
    private function prepareOutput(): void
    {
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        @ini_set('zlib.output_compression', '0'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- a compressed stream would break Content-Length and Range

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- a download is as long as the network makes it
        }
    }

    /**
     * `filename=` for browsers that read only that, `filename*=` for the
     * folder's real name — a Greek folder downloads as itself.
     */
    private static function filename(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'folder.zip';
        $ascii = str_replace(['"', '\\'], '_', $ascii);

        return sprintf('filename="%s"; filename*=UTF-8\'\'%s', $ascii, rawurlencode($name));
    }

    private function fail(int $status, string $message): never
    {
        wp_die(esc_html($message), '', ['response' => (int) $status]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an HTTP status code, not output
    }
}
