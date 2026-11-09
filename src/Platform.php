<?php

declare(strict_types=1);

namespace Dynoxide;

use Dynoxide\Exception\DynoxideException;

/**
 * The release target this machine needs, and the names that follow from it.
 */
final class Platform
{
    public const TARGETS = [
        'x86_64-unknown-linux-musl',
        'aarch64-unknown-linux-musl',
        'x86_64-apple-darwin',
        'aarch64-apple-darwin',
        'x86_64-pc-windows-msvc',
    ];

    private const ARCHITECTURES = [
        'x86_64' => 'x86_64',
        'amd64' => 'x86_64',
        'arm64' => 'aarch64',
        'aarch64' => 'aarch64',
    ];

    private function __construct(public readonly string $target)
    {
    }

    /**
     * Works out the target from the running PHP. The arguments are for tests.
     */
    public static function detect(?string $osFamily = null, ?string $machine = null): self
    {
        $osFamily ??= PHP_OS_FAMILY;
        $machine ??= php_uname('m');
        $arch = self::ARCHITECTURES[strtolower($machine)] ?? null;

        $target = match (true) {
            $arch === null => null,
            $osFamily === 'Linux' => "{$arch}-unknown-linux-musl",
            $osFamily === 'Darwin' => "{$arch}-apple-darwin",
            $osFamily === 'Windows' && $arch === 'x86_64' => 'x86_64-pc-windows-msvc',
            default => null,
        };

        if ($target === null) {
            throw new DynoxideException(sprintf(
                'Dynoxide has no release binary for %s on %s. Build it from source and '
                . 'point DYNOXIDE_BINARY at it, or pass the binary option.',
                $osFamily,
                $machine,
            ));
        }

        return new self($target);
    }

    public static function fromTarget(string $target): self
    {
        if (!in_array($target, self::TARGETS, true)) {
            throw new DynoxideException("Unknown Dynoxide target: {$target}");
        }

        return new self($target);
    }

    public function isWindows(): bool
    {
        return $this->target === 'x86_64-pc-windows-msvc';
    }

    public function archiveName(): string
    {
        return 'dynoxide-' . $this->target . ($this->isWindows() ? '.zip' : '.tar.gz');
    }

    public function binaryName(): string
    {
        return $this->isWindows() ? 'dynoxide.exe' : 'dynoxide';
    }
}
