<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit;

use Dynoxide\Exception\DynoxideException;
use Dynoxide\Platform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlatformTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string}> */
    public static function supported(): iterable
    {
        yield 'linux x86_64' => ['Linux', 'x86_64', 'x86_64-unknown-linux-musl', 'dynoxide-x86_64-unknown-linux-musl.tar.gz'];
        yield 'linux amd64' => ['Linux', 'amd64', 'x86_64-unknown-linux-musl', 'dynoxide-x86_64-unknown-linux-musl.tar.gz'];
        yield 'linux aarch64' => ['Linux', 'aarch64', 'aarch64-unknown-linux-musl', 'dynoxide-aarch64-unknown-linux-musl.tar.gz'];
        yield 'linux arm64' => ['Linux', 'arm64', 'aarch64-unknown-linux-musl', 'dynoxide-aarch64-unknown-linux-musl.tar.gz'];
        yield 'macos x86_64' => ['Darwin', 'x86_64', 'x86_64-apple-darwin', 'dynoxide-x86_64-apple-darwin.tar.gz'];
        yield 'macos arm64' => ['Darwin', 'arm64', 'aarch64-apple-darwin', 'dynoxide-aarch64-apple-darwin.tar.gz'];
        yield 'windows AMD64' => ['Windows', 'AMD64', 'x86_64-pc-windows-msvc', 'dynoxide-x86_64-pc-windows-msvc.zip'];
    }

    #[DataProvider('supported')]
    public function testMapsEachSupportedPlatform(string $os, string $machine, string $target, string $archive): void
    {
        $platform = Platform::detect($os, $machine);

        self::assertSame($target, $platform->target);
        self::assertSame($archive, $platform->archiveName());
        self::assertSame($os === 'Windows' ? 'dynoxide.exe' : 'dynoxide', $platform->binaryName());
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsupported(): iterable
    {
        yield 'windows on arm' => ['Windows', 'ARM64'];
        yield 'freebsd' => ['BSD', 'amd64'];
        yield '32-bit linux' => ['Linux', 'i686'];
        yield 'solaris' => ['Solaris', 'x86_64'];
    }

    #[DataProvider('unsupported')]
    public function testRefusesPlatformsWithoutABinary(string $os, string $machine): void
    {
        try {
            Platform::detect($os, $machine);
            self::fail('Expected an exception');
        } catch (DynoxideException $e) {
            self::assertStringContainsString($os, $e->getMessage());
            self::assertStringContainsString($machine, $e->getMessage());
            self::assertStringContainsString('DYNOXIDE_BINARY', $e->getMessage());
        }
    }

    public function testDetectsTheRunningMachine(): void
    {
        self::assertContains(Platform::detect()->target, Platform::TARGETS);
    }

    public function testRefusesAnUnknownTarget(): void
    {
        $this->expectException(DynoxideException::class);
        Platform::fromTarget('riscv64-unknown-linux-gnu');
    }
}
