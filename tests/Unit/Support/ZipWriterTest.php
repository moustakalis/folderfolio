<?php

declare(strict_types=1);

namespace FolderFolio\Tests\Unit\Support;

use FolderFolio\Support\ZipWriter;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * The streamed ZIP (tier 2 item 11), read back by libzip.
 *
 * What a download depends on, each asserted by reading the archive rather
 * than by trusting the writer: the length promised before a byte is written
 * is the length written; every entry comes back byte for byte under its UTF-8
 * name; a range of the archive is exactly that range of the whole, so a
 * browser's Resume stitches a valid file together; and ZIP64 takes over past
 * 65,535 entries without being asked. (ZIP64 by size — a 4 GB file — was
 * measured on the rig and is recorded in `…-23m-zip-stream-or-build`; it is
 * too heavy for a unit suite.)
 */
final class ZipWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::fail('The zip extension is needed to read the archives back.');
        }

        $this->dir = sys_get_temp_dir() . '/ff-zip-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/a.jpg', random_bytes(70000));
        file_put_contents($this->dir . '/b.png', random_bytes(1234));
        file_put_contents($this->dir . '/empty.txt', '');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->dir);
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private function layout(): array
    {
        return [
            ['Φωτογραφίες/', 0],
            ['Φωτογραφίες/a.jpg', 70000],
            ['Φωτογραφίες/Web/', 0],
            ['Φωτογραφίες/Web/b.png', 1234],
            ['Φωτογραφίες/Web/empty.txt', 0],
            ['Φωτογραφίες/not-included.txt', 5],
        ];
    }

    private function write(int $skip = 0): string
    {
        $bytes = '';
        $writer = new ZipWriter(static function (string $chunk) use (&$bytes): void {
            $bytes .= $chunk;
        }, $skip);

        // A fixed time, so two writes are the same bytes.
        $time = 1790000000;
        $writer->addDirectory('Φωτογραφίες/', $time);
        $writer->addFile('Φωτογραφίες/a.jpg', $this->dir . '/a.jpg', 70000, $time);
        $writer->addDirectory('Φωτογραφίες/Web/', $time);
        $writer->addFile('Φωτογραφίες/Web/b.png', $this->dir . '/b.png', 1234, $time);
        $writer->addFile('Φωτογραφίες/Web/empty.txt', $this->dir . '/empty.txt', 0, $time);
        $writer->addString('Φωτογραφίες/not-included.txt', 'hello', $time);
        $writer->finish();

        return $bytes;
    }

    private function open(string $bytes): ZipArchive
    {
        $path = $this->dir . '/out.zip';
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CHECKCONS), 'libzip refused the archive.');

        return $zip;
    }

    public function test_the_promised_length_is_the_written_length(): void
    {
        $this->assertSame(ZipWriter::length($this->layout()), strlen($this->write()));
    }

    public function test_every_entry_reads_back_byte_for_byte_under_its_utf8_name(): void
    {
        $zip = $this->open($this->write());

        $this->assertSame(6, $zip->numFiles);
        $this->assertSame(file_get_contents($this->dir . '/a.jpg'), $zip->getFromName('Φωτογραφίες/a.jpg'));
        $this->assertSame(file_get_contents($this->dir . '/b.png'), $zip->getFromName('Φωτογραφίες/Web/b.png'));
        $this->assertSame('', $zip->getFromName('Φωτογραφίες/Web/empty.txt'));
        $this->assertSame('hello', $zip->getFromName('Φωτογραφίες/not-included.txt'));

        // Stored, not deflated: already-compressed media gains nothing.
        $stat = $zip->statName('Φωτογραφίες/a.jpg');
        self::assertIsArray($stat);
        $this->assertSame(ZipArchive::CM_STORE, $stat['comp_method']);

        // A directory entry, so an empty folder still arrives as a folder.
        $this->assertNotFalse($zip->statName('Φωτογραφίες/Web/'));
        $zip->close();
    }

    public function test_a_range_is_exactly_that_range_of_the_whole(): void
    {
        $whole = $this->write();

        // Inside a header, inside a file's bytes, at a file boundary, inside
        // the central directory: every place a cut-off download can resume.
        foreach ([1, 29, 1000, 70050, 71400, strlen($whole) - 40, strlen($whole) - 1] as $from) {
            $this->assertSame(substr($whole, $from), $this->write($from), "Resuming at byte {$from}.");
        }
    }

    public function test_zip64_takes_over_past_65535_entries(): void
    {
        $bytes = '';
        $writer = new ZipWriter(static function (string $chunk) use (&$bytes): void {
            $bytes .= $chunk;
        });
        $layout = [];

        for ($i = 0; $i < 65540; $i++) {
            $writer->addString("n/{$i}.txt", (string) $i, 1790000000);
            $layout[] = ["n/{$i}.txt", strlen((string) $i)];
        }

        $writer->finish();

        $this->assertSame(ZipWriter::length($layout), strlen($bytes));

        $zip = $this->open($bytes);
        $this->assertSame(65540, $zip->numFiles);
        $this->assertSame('65539', $zip->getFromName('n/65539.txt'));
        $zip->close();
    }

    public function test_a_file_that_shrank_since_it_was_measured_stops_the_archive(): void
    {
        $writer = new ZipWriter(static function (string $chunk): void {
        });

        $this->expectException(\RuntimeException::class);
        $writer->addFile('x.bin', $this->dir . '/b.png', 5000, 1790000000);
    }
}
