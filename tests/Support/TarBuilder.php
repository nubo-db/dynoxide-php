<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Support;

/**
 * Builds tar archives byte by byte, so tests can make the hostile ones no
 * well-behaved tar tool would write.
 */
final class TarBuilder
{
    public const POSIX = "ustar\x0000";
    public const GNU = "ustar  \x00";

    /** @var list<string> */
    private array $blocks = [];

    public function __construct(private readonly string $magic = self::POSIX)
    {
    }

    /**
     * @param array{type?: string, linkname?: string, prefix?: string, mode?: int, checksum?: string, size?: string} $options
     */
    public function add(string $name, string $data, array $options = []): self
    {
        $type = $options['type'] ?? '0';
        $size = $options['size'] ?? sprintf('%011o', strlen($data)) . "\x00";

        $header = str_pad($name, 100, "\x00")
            . sprintf('%07o', $options['mode'] ?? 0755) . "\x00"
            . sprintf('%07o', 0) . "\x00"
            . sprintf('%07o', 0) . "\x00"
            . $size
            . sprintf('%011o', 0) . "\x00"
            . '        '
            . $type
            . str_pad($options['linkname'] ?? '', 100, "\x00")
            . $this->magic
            . str_pad('runner', 32, "\x00")
            . str_pad('runner', 32, "\x00")
            . str_repeat("\x00", 16)
            . str_pad($options['prefix'] ?? '', 155, "\x00");
        $header = str_pad($header, 512, "\x00");

        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ord($header[$i]);
        }
        $checksum = $options['checksum'] ?? sprintf('%06o', $sum) . "\x00 ";
        $header = substr_replace($header, $checksum, 148, 8);

        $this->blocks[] = $header;
        if ($data !== '') {
            $this->blocks[] = str_pad($data, (int) ceil(strlen($data) / 512) * 512, "\x00");
        }

        return $this;
    }

    public function raw(string $bytes): self
    {
        $this->blocks[] = $bytes;

        return $this;
    }

    public function tar(bool $endBlocks = true): string
    {
        return implode('', $this->blocks) . ($endBlocks ? str_repeat("\x00", 1024) : '');
    }

    public function writeGz(string $path, bool $endBlocks = true): string
    {
        file_put_contents($path, gzencode($this->tar($endBlocks)));

        return $path;
    }
}
