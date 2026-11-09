<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit\Archive;

use Dynoxide\Archive\TarReader;
use Dynoxide\Exception\ArchiveException;
use Dynoxide\Tests\Support\TarBuilder;
use Dynoxide\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TarReaderTest extends TestCase
{
    use TempDir;

    /** @return iterable<string, array{string}> */
    public static function magics(): iterable
    {
        yield 'POSIX header (bsdtar, macOS)' => [TarBuilder::POSIX];
        yield 'GNU header (GNU tar, Linux)' => [TarBuilder::GNU];
    }

    #[DataProvider('magics')]
    public function testExtractsTheOneMember(string $magic): void
    {
        $data = random_bytes(1500);
        $archive = (new TarBuilder($magic))->add('dynoxide', $data)->writeGz($this->tempDir() . '/a.tar.gz');
        $out = $this->tempDir() . '/dynoxide';

        TarReader::extract($archive, 'dynoxide', $out);

        self::assertSame($data, file_get_contents($out));
    }

    public function testExtractsAMemberThatFillsWholeBlocks(): void
    {
        $data = random_bytes(1024);
        $archive = (new TarBuilder())->add('dynoxide', $data)->writeGz($this->tempDir() . '/a.tar.gz');
        $out = $this->tempDir() . '/dynoxide';

        TarReader::extract($archive, 'dynoxide', $out);

        self::assertSame($data, file_get_contents($out));
    }

    /** @return iterable<string, array{\Closure(): TarBuilder, bool}> */
    public static function hostile(): iterable
    {
        yield 'an extra entry after the binary' => [fn () => (new TarBuilder())->add('dynoxide', 'bin')->add('README', 'x'), true];
        yield 'an extra entry before the binary' => [fn () => (new TarBuilder())->add('README', 'x')->add('dynoxide', 'bin'), true];
        yield 'a parent-directory path' => [fn () => (new TarBuilder())->add('../dynoxide', 'bin'), true];
        yield 'an absolute path' => [fn () => (new TarBuilder())->add('/dynoxide', 'bin'), true];
        yield 'a path in a directory' => [fn () => (new TarBuilder())->add('dir/dynoxide', 'bin'), true];
        yield 'a name split into the prefix field' => [fn () => (new TarBuilder())->add('dynoxide', 'bin', ['prefix' => 'dir']), true];
        yield 'a symlink named dynoxide' => [fn () => (new TarBuilder())->add('dynoxide', '', ['type' => '2', 'linkname' => '/bin/sh']), true];
        yield 'a hardlink named dynoxide' => [fn () => (new TarBuilder())->add('dynoxide', '', ['type' => '1', 'linkname' => 'other']), true];
        yield 'a directory named dynoxide' => [fn () => (new TarBuilder())->add('dynoxide', '', ['type' => '5']), true];
        yield 'a pax header before the binary' => [fn () => (new TarBuilder())->add('PaxHeader/dynoxide', "30 path=../../evil/dynoxide\n", ['type' => 'x'])->add('dynoxide', 'bin'), true];
        yield 'a GNU long-name entry' => [fn () => (new TarBuilder(TarBuilder::GNU))->add('././@LongLink', "dynoxide\x00", ['type' => 'L'])->add('dynoxide', 'bin'), true];
        yield 'a bad header checksum' => [fn () => (new TarBuilder())->add('dynoxide', 'bin', ['checksum' => "0000000\x00"]), true];
        yield 'an unknown magic' => [fn () => (new TarBuilder("nottar\x0000"))->add('dynoxide', 'bin'), true];
        yield 'a base-256 size' => [fn () => (new TarBuilder())->add('dynoxide', 'bin', ['size' => "\x80" . str_repeat("\x00", 10) . "\x03"]), true];
        yield 'no member at all' => [fn () => new TarBuilder(), true];
        yield 'garbage after the end blocks' => [fn () => (new TarBuilder())->add('dynoxide', 'bin')->raw(str_repeat("\x00", 1024))->add('README', 'x'), false];
    }

    /** @param \Closure(): TarBuilder $build */
    #[DataProvider('hostile')]
    public function testRefusesHostileArchives(\Closure $build, bool $endBlocks): void
    {
        $archive = $build()->writeGz($this->tempDir() . '/a.tar.gz', $endBlocks);
        $out = $this->tempDir() . '/dynoxide';

        try {
            TarReader::extract($archive, 'dynoxide', $out);
            self::fail('Expected the archive to be refused');
        } catch (ArchiveException $e) {
            self::assertStringContainsString('a.tar.gz', $e->getMessage());
        }
        self::assertFileDoesNotExist($out);
    }

    public function testRefusesATruncatedMember(): void
    {
        $tar = (new TarBuilder())->add('dynoxide', random_bytes(4000))->tar();
        $archive = $this->tempDir() . '/a.tar.gz';
        file_put_contents($archive, gzencode(substr($tar, 0, 2048)));
        $out = $this->tempDir() . '/dynoxide';

        $this->expectException(ArchiveException::class);
        $this->expectExceptionMessage('it ends before the member does');
        try {
            TarReader::extract($archive, 'dynoxide', $out);
        } finally {
            self::assertFileDoesNotExist($out);
        }
    }

    public function testRefusesAMissingEndBlock(): void
    {
        $archive = (new TarBuilder())->add('dynoxide', 'bin')->writeGz($this->tempDir() . '/a.tar.gz', false);

        $this->expectException(ArchiveException::class);
        TarReader::extract($archive, 'dynoxide', $this->tempDir() . '/dynoxide');
    }

    public function testRefusesATruncatedGzipStream(): void
    {
        $archive = $this->tempDir() . '/a.tar.gz';
        $gz = gzencode((new TarBuilder())->add('dynoxide', random_bytes(4000))->tar());
        file_put_contents($archive, substr($gz, 0, intdiv(strlen($gz), 2)));
        $out = $this->tempDir() . '/dynoxide';

        $this->expectException(ArchiveException::class);
        try {
            TarReader::extract($archive, 'dynoxide', $out);
        } finally {
            self::assertFileDoesNotExist($out);
        }
    }

    public function testRefusesToOverwriteTheDestination(): void
    {
        $archive = (new TarBuilder())->add('dynoxide', 'bin')->writeGz($this->tempDir() . '/a.tar.gz');
        $out = $this->tempDir() . '/dynoxide';
        file_put_contents($out, 'already here');

        try {
            TarReader::extract($archive, 'dynoxide', $out);
            self::fail('Expected the extraction to refuse an existing file');
        } catch (ArchiveException) {
            self::assertSame('already here', file_get_contents($out));
        }
    }
}
