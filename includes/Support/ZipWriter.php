<?php

declare(strict_types=1);

namespace FolderFolio\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A ZIP archive written straight to an output, never to disk — tier 2 item 11.
 *
 * Nick's call after measuring both (record `…-23m-zip-stream-or-build`, page
 * `XdRT8n1BYg2Pam9uWnLQ5y`): stream the archive as it is read, rather than
 * build a file and serve it. Nothing is left behind — no temp file, no public
 * URL, no cleanup cron — and memory stays flat whatever the folder weighs.
 *
 * ## Why these choices
 *
 * - **Stored, never deflated.** The library is JPEG, PNG and MP4 — already
 *   compressed. ZipArchive's default Deflate spent 24s of CPU per GB and made
 *   the archive 160KB *larger*; CPU is what `max_execution_time` counts.
 * - **Each file's CRC just before its header**, not in a pass over the whole
 *   folder first: the first byte leaves after one file's read (3ms measured),
 *   not after the folder's (1s per GB on a cold disk, minutes on slow shared
 *   storage — long enough for a proxy to give up). No data descriptors, so
 *   readers that walk local headers from the front work too.
 * - **The length is known before a byte is written** (`length()`), because
 *   Stored sizes are the files' sizes. So the response carries a real
 *   Content-Length: the browser shows progress, and a download a host cuts
 *   off is reported as failed rather than saved as a broken ZIP.
 * - **The bytes are the same every time**, so a range can be served
 *   (`$skip`): a download cut off by a host's wall-clock limit resumes where
 *   it stopped instead of starting over. Nothing before `$skip` is written,
 *   and a file wholly before it is not even read — unless its CRC is needed
 *   for the central directory and was not handed in.
 * - **ZIP64** when a size, an offset, or the entry count needs it, and only
 *   then, so an ordinary archive is an ordinary archive.
 * - **UTF-8 names** (general purpose bit 11): a Greek folder name arrives as
 *   itself.
 *
 * Pure — no WordPress — so it is unit tested by reading its output back.
 */
final class ZipWriter
{
    private const MAX32 = 0xFFFFFFFF;

    private const MAX16 = 0xFFFF;

    /** Read and written this much at a time. */
    private const CHUNK = 1048576;

    /** Bit 11: the name is UTF-8. */
    private const UTF8 = 0x0800;

    /** Unix, and version 4.5 (ZIP64) of the spec — what "made by" says. */
    private const MADE_BY = (3 << 8) | 45;

    private int $offset = 0;

    /** @var list<array{name: string, size: int, crc: int, time: int, date: int, offset: int, dir: bool}> */
    private array $central = [];

    /** @var callable(string): void */
    private $out;

    /**
     * @param callable(string): void $out  Receives the archive's bytes, in order.
     * @param int                    $skip Bytes of the archive not to send — a range's start.
     */
    public function __construct(callable $out, private readonly int $skip = 0)
    {
        $this->out = $out;
    }

    /**
     * A file from disk. Returns its CRC-32, so a caller can keep it.
     *
     * @param int      $mtime When it was last changed, as a local timestamp.
     * @param int|null $crc   Its CRC-32 if already known — it is then not read twice.
     */
    public function addFile(string $name, string $path, int $size, int $mtime, ?int $crc = null): int
    {
        $crc ??= self::crcOf($path);
        [$time, $date] = self::dos($mtime);
        $start = $this->offset;

        $this->emit(self::localHeader($name, $size, $crc, $time, $date));

        if ($this->offset + $size <= $this->skip) {
            // Resumed past this file: its bytes were sent by an earlier request.
            $this->offset += $size;
        } else {
            $this->emitFile($path, $size);
        }

        $this->central[] = [
            'name' => $name,
            'size' => $size,
            'crc' => $crc,
            'time' => $time,
            'date' => $date,
            'offset' => $start,
            'dir' => false,
        ];

        return $crc;
    }

    /** A file whose content is in memory — the note of what was left out. */
    public function addString(string $name, string $content, int $mtime): void
    {
        [$time, $date] = self::dos($mtime);
        $crc = (int) hexdec(hash('crc32b', $content));
        $size = strlen($content);
        $start = $this->offset;

        $this->emit(self::localHeader($name, $size, $crc, $time, $date));
        $this->emit($content);

        $this->central[] = [
            'name' => $name,
            'size' => $size,
            'crc' => $crc,
            'time' => $time,
            'date' => $date,
            'offset' => $start,
            'dir' => false,
        ];
    }

    /**
     * A directory — so a folder with no files in it still arrives as a folder.
     *
     * @param string $name Ends in a slash.
     */
    public function addDirectory(string $name, int $mtime): void
    {
        [$time, $date] = self::dos($mtime);
        $start = $this->offset;

        $this->emit(self::localHeader($name, 0, 0, $time, $date));

        $this->central[] = [
            'name' => $name,
            'size' => 0,
            'crc' => 0,
            'time' => $time,
            'date' => $date,
            'offset' => $start,
            'dir' => true,
        ];
    }

    /** The central directory and the end records. The archive is complete after this. */
    public function finish(): void
    {
        $cdStart = $this->offset;

        foreach ($this->central as $entry) {
            $this->emit(self::centralHeader($entry));
        }

        $cdSize = $this->offset - $cdStart;

        $this->emit(self::endRecords(count($this->central), $cdSize, $cdStart, $this->offset));
    }

    /**
     * The archive's exact length, before any of it exists.
     *
     * Built from the same header functions the writer uses, with a zero CRC —
     * a CRC is four bytes whatever its value — so the two cannot disagree.
     *
     * @param list<array{0: string, 1: int}> $entries Name and size, in order. A name ending in `/` is a directory.
     */
    public static function length(array $entries): int
    {
        $offset = 0;
        $cd = 0;

        foreach ($entries as [$name, $size]) {
            $cd += strlen(self::centralHeader([
                'name' => $name,
                'size' => $size,
                'crc' => 0,
                'time' => 0,
                'date' => 0,
                'offset' => $offset,
                'dir' => str_ends_with($name, '/'),
            ]));
            $offset += strlen(self::localHeader($name, $size, 0, 0, 0)) + $size;
        }

        return $offset + $cd + strlen(self::endRecords(count($entries), $cd, $offset, $offset + $cd));
    }

    public static function crcOf(string $path): int
    {
        $hex = hash_file('crc32b', $path);

        if (false === $hex) {
            throw new \RuntimeException(sprintf('Could not read %s.', $path));
        }

        return (int) hexdec($hex);
    }

    private function emit(string $bytes): void
    {
        $length = strlen($bytes);

        if ($this->offset + $length > $this->skip) {
            ($this->out)($this->offset >= $this->skip ? $bytes : substr($bytes, $this->skip - $this->offset));
        }

        $this->offset += $length;
    }

    private function emitFile(string $path, int $size): void
    {
        $handle = fopen($path, 'rb');

        if (false === $handle) {
            throw new \RuntimeException(sprintf('Could not open %s.', $path));
        }

        try {
            $from = max(0, $this->skip - $this->offset);

            if ($from > 0) {
                fseek($handle, $from);
                $this->offset += $from;
            }

            $left = $size - $from;

            while ($left > 0) {
                $chunk = fread($handle, (int) min(self::CHUNK, $left));

                // A file that shrank since it was measured would leave the
                // archive short of the length already promised. Stop loudly:
                // the connection closes early and the browser reports the
                // download as failed, which is the truth.
                if (false === $chunk || '' === $chunk) {
                    throw new \RuntimeException(sprintf('%s is shorter than it was.', $path));
                }

                $left -= strlen($chunk);
                $this->emit($chunk);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * MS-DOS time and date. Seconds are stored halved, years from 1980 to 2107.
     *
     * @return array{0: int, 1: int}
     */
    private static function dos(int $timestamp): array
    {
        // 1980-01-01 and 2107-12-31, as UTC timestamps; the caller has
        // already moved the timestamp into the site's time zone.
        $timestamp = max(315532800, min(4354819199, $timestamp));
        [$y, $mo, $day, $h, $mi, $s] = array_map('intval', explode(' ', gmdate('Y n j G i s', $timestamp)));

        return [
            ($h << 11) | ($mi << 5) | intdiv($s, 2),
            (($y - 1980) << 9) | ($mo << 5) | $day,
        ];
    }

    private static function localHeader(string $name, int $size, int $crc, int $time, int $date): string
    {
        $zip64 = $size >= self::MAX32;
        $extra = $zip64 ? pack('vvPP', 0x0001, 16, $size, $size) : '';

        return pack(
            'VvvvvvVVVvv',
            0x04034b50,
            $zip64 ? 45 : 20,
            self::UTF8,
            0, // stored
            $time,
            $date,
            $crc,
            $zip64 ? self::MAX32 : $size,
            $zip64 ? self::MAX32 : $size,
            strlen($name),
            strlen($extra)
        ) . $name . $extra;
    }

    /**
     * @param array{name: string, size: int, crc: int, time: int, date: int, offset: int, dir: bool} $entry
     */
    private static function centralHeader(array $entry): string
    {
        $bigSize = $entry['size'] >= self::MAX32;
        $bigOffset = $entry['offset'] >= self::MAX32;
        $extra = '';

        if ($bigSize || $bigOffset) {
            // Only the fields whose 32-bit slot holds 0xFFFFFFFF, in this order.
            $fields = ($bigSize ? pack('PP', $entry['size'], $entry['size']) : '')
                . ($bigOffset ? pack('P', $entry['offset']) : '');
            $extra = pack('vv', 0x0001, strlen($fields)) . $fields;
        }

        // Unix mode in the high half: rw-r--r-- for a file, rwxr-xr-x and the
        // MS-DOS directory bit for a directory.
        $attributes = $entry['dir'] ? ((040755 << 16) | 0x10) : (0100644 << 16);

        return pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            self::MADE_BY,
            ($bigSize || $bigOffset) ? 45 : 20,
            self::UTF8,
            0,
            $entry['time'],
            $entry['date'],
            $entry['crc'],
            $bigSize ? self::MAX32 : $entry['size'],
            $bigSize ? self::MAX32 : $entry['size'],
            strlen($entry['name']),
            strlen($extra),
            0, // comment
            0, // disk
            0, // internal attributes
            $attributes,
            $bigOffset ? self::MAX32 : $entry['offset']
        ) . $entry['name'] . $extra;
    }

    private static function endRecords(int $count, int $cdSize, int $cdStart, int $zip64At): string
    {
        $zip64 = $count >= self::MAX16 || $cdSize >= self::MAX32 || $cdStart >= self::MAX32;
        $records = '';

        if ($zip64) {
            $records .= pack('VPvvVVPPPP', 0x06064b50, 44, self::MADE_BY, 45, 0, 0, $count, $count, $cdSize, $cdStart);
            $records .= pack('VVPV', 0x07064b50, 0, $zip64At, 1);
        }

        return $records . pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            $zip64 ? self::MAX16 : $count,
            $zip64 ? self::MAX16 : $count,
            $cdSize >= self::MAX32 ? self::MAX32 : $cdSize,
            $cdStart >= self::MAX32 ? self::MAX32 : $cdStart,
            0
        );
    }
}
