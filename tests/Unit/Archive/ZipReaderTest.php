<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit\Archive;

use Dynoxide\Archive\ZipReader;
use Dynoxide\Exception\ArchiveException;
use Dynoxide\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('zip')]
final class ZipReaderTest extends TestCase
{
    use TempDir;

    /** @param array<string, string> $entries */
    private function zip(array $entries, ?\Closure $tweak = null): string
    {
        $path = $this->tempDir() . '/a.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL);
        foreach ($entries as $name => $data) {
            str_ends_with($name, '/') ? $zip->addEmptyDir(rtrim($name, '/')) : $zip->addFromString($name, $data);
        }
        if ($tweak !== null) {
            $tweak($zip);
        }
        $zip->close();

        return $path;
    }

    public function testExtractsTheOneMember(): void
    {
        $data = random_bytes(70000);
        $out = $this->tempDir() . '/dynoxide.exe';

        ZipReader::extract($this->zip(['dynoxide.exe' => $data]), 'dynoxide.exe', $out);

        self::assertSame($data, file_get_contents($out));
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function hostile(): iterable
    {
        yield 'two entries' => [['dynoxide.exe' => 'bin', 'README' => 'x'], 'has 2 entries'];
        yield 'the wrong name' => [['dynoxide' => 'bin'], 'holds "dynoxide"'];
        yield 'a parent-directory path' => [['../dynoxide.exe' => 'bin'], 'holds "../dynoxide.exe"'];
        yield 'a path in a directory' => [['dir/dynoxide.exe' => 'bin'], 'holds "dir/dynoxide.exe"'];
        yield 'a directory entry' => [['dynoxide.exe/' => ''], 'holds "dynoxide.exe/"'];
        yield 'no entries' => [[], 'has 0 entries'];
    }

    /** @param array<string, string> $entries */
    #[DataProvider('hostile')]
    public function testRefusesHostileArchives(array $entries, string $reason): void
    {
        $archive = $entries === [] ? $this->emptyZip() : $this->zip($entries);
        $out = $this->tempDir() . '/dynoxide.exe';

        try {
            ZipReader::extract($archive, 'dynoxide.exe', $out);
            self::fail('Expected the archive to be refused');
        } catch (ArchiveException $e) {
            self::assertStringContainsString('a.zip', $e->getMessage());
            self::assertStringContainsString($reason, $e->getMessage());
        }
        self::assertFileDoesNotExist($out);
    }

    public function testRefusesASymlinkEntry(): void
    {
        $archive = $this->zip(['dynoxide.exe' => '/bin/sh'], static function (\ZipArchive $zip): void {
            // Unix host, mode lrwxrwxrwx.
            $zip->setExternalAttributesName('dynoxide.exe', \ZipArchive::OPSYS_UNIX, (0120777 << 16));
        });
        $out = $this->tempDir() . '/dynoxide.exe';

        $this->expectException(ArchiveException::class);
        try {
            ZipReader::extract($archive, 'dynoxide.exe', $out);
        } finally {
            self::assertFileDoesNotExist($out);
        }
    }

    public function testRefusesATruncatedArchive(): void
    {
        $archive = $this->zip(['dynoxide.exe' => random_bytes(70000)]);
        $bytes = (string) file_get_contents($archive);
        file_put_contents($archive, substr($bytes, 0, intdiv(strlen($bytes), 2)));

        $this->expectException(ArchiveException::class);
        ZipReader::extract($archive, 'dynoxide.exe', $this->tempDir() . '/dynoxide.exe');
    }

    public function testRefusesACorruptMember(): void
    {
        // Stored, not deflated, so flipping a byte of the data leaves the
        // archive readable and only the CRC can catch it.
        $data = random_bytes(4096);
        $archive = $this->zip(['dynoxide.exe' => $data], static function (\ZipArchive $zip): void {
            $zip->setCompressionName('dynoxide.exe', \ZipArchive::CM_STORE);
        });
        $bytes = (string) file_get_contents($archive);
        $at = strpos($bytes, $data);
        self::assertNotFalse($at);
        $bytes[$at + 100] = chr(ord($bytes[$at + 100]) ^ 0xFF);
        file_put_contents($archive, $bytes);
        $out = $this->tempDir() . '/dynoxide.exe';

        $this->expectException(ArchiveException::class);
        try {
            ZipReader::extract($archive, 'dynoxide.exe', $out);
        } finally {
            self::assertFileDoesNotExist($out);
        }
    }

    private function emptyZip(): string
    {
        // ZipArchive won't write an archive with no entries, so write the
        // end-of-central-directory record by hand.
        $path = $this->tempDir() . '/a.zip';
        file_put_contents($path, "PK\x05\x06" . str_repeat("\x00", 18));

        return $path;
    }
}
