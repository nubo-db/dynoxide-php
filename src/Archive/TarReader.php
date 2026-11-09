<?php

declare(strict_types=1);

namespace Dynoxide\Archive;

use Dynoxide\Exception\ArchiveException;

/**
 * Reads the one member out of a release tarball.
 *
 * Written by hand rather than with PharData, which caches archive listings by
 * path for the life of the process and turns links into empty files. This
 * reader accepts exactly one regular file with the expected name, followed by
 * nothing but zero bytes, and refuses everything else.
 */
final class TarReader
{
    private const BLOCK = 512;
    private const MAX_SIZE = 1 << 30;

    public static function extract(string $archive, string $member, string $destination): void
    {
        $in = @fopen('compress.zlib://' . $archive, 'rb');
        if ($in === false) {
            throw self::fail($archive, 'it could not be opened');
        }

        try {
            $header = self::readBlock($in, $archive);
            $size = self::checkHeader($header, $member, $archive);

            $out = @fopen($destination, 'xb');
            if ($out === false) {
                throw self::fail($archive, "{$destination} could not be created, or already exists");
            }
            try {
                self::copy($in, $out, $size, $archive);
            } catch (\Throwable $e) {
                fclose($out);
                @unlink($destination);
                throw $e;
            }
            if (fclose($out) === false) {
                @unlink($destination);
                throw self::fail($archive, "{$destination} could not be written");
            }

            try {
                self::checkTrailer($in, $size, $archive);
            } catch (\Throwable $e) {
                @unlink($destination);
                throw $e;
            }
        } finally {
            fclose($in);
        }
    }

    /**
     * @param resource $in
     */
    private static function readBlock($in, string $archive): string
    {
        $block = self::readExactly($in, self::BLOCK);
        if (strlen($block) !== self::BLOCK) {
            throw self::fail($archive, 'it ends part-way through a block');
        }

        return $block;
    }

    /**
     * @param resource $in
     */
    private static function readExactly($in, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = @fread($in, min(65536, $length - strlen($data)));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }

        return $data;
    }

    private static function checkHeader(string $header, string $member, string $archive): int
    {
        if ($header === str_repeat("\x00", self::BLOCK)) {
            throw self::fail($archive, 'it has no members');
        }

        $stored = self::octal(substr($header, 148, 8));
        $sum = 0;
        for ($i = 0; $i < self::BLOCK; $i++) {
            $sum += ($i >= 148 && $i < 156) ? 32 : ord($header[$i]);
        }
        if ($stored === null || $stored !== $sum) {
            throw self::fail($archive, 'a header checksum is wrong');
        }

        $magic = substr($header, 257, 8);
        if ($magic !== "ustar\x0000" && $magic !== "ustar  \x00") {
            throw self::fail($archive, 'it is not a ustar or GNU tar archive');
        }

        $name = rtrim(substr($header, 0, 100), "\x00");
        $prefix = rtrim(substr($header, 345, 155), "\x00");
        if ($name !== $member || $prefix !== '') {
            $shown = $prefix === '' ? $name : "{$prefix}/{$name}";
            throw self::fail($archive, "it holds \"{$shown}\" where only \"{$member}\" is expected");
        }

        $type = $header[156];
        if ($type !== '0' && $type !== "\x00") {
            throw self::fail($archive, "\"{$member}\" is not a regular file (type " . self::showType($type) . ')');
        }

        $size = self::octal(substr($header, 124, 12));
        if ($size === null || $size > self::MAX_SIZE) {
            throw self::fail($archive, "the size of \"{$member}\" is not one this reader accepts");
        }

        return $size;
    }

    /**
     * @param resource $in
     * @param resource $out
     */
    private static function copy($in, $out, int $size, string $archive): void
    {
        $left = $size;
        while ($left > 0) {
            $chunk = self::readExactly($in, min(65536, $left));
            if ($chunk === '') {
                throw self::fail($archive, 'it ends before the member does');
            }
            if (fwrite($out, $chunk) !== strlen($chunk)) {
                throw self::fail($archive, 'the member could not be written out');
            }
            $left -= strlen($chunk);
        }

        $padding = (self::BLOCK - $size % self::BLOCK) % self::BLOCK;
        if ($padding > 0 && strlen(self::readExactly($in, $padding)) !== $padding) {
            throw self::fail($archive, 'it ends part-way through a block');
        }
    }

    /**
     * After the member there must be an end-of-archive block, and nothing but
     * zero bytes to the end of the file.
     *
     * @param resource $in
     */
    private static function checkTrailer($in, int $size, string $archive): void
    {
        $end = self::readExactly($in, self::BLOCK);
        if ($end !== str_repeat("\x00", self::BLOCK)) {
            throw self::fail($archive, strlen($end) === self::BLOCK
                ? 'it has more than one member'
                : 'it has no end-of-archive marker');
        }

        while (($chunk = self::readExactly($in, 65536)) !== '') {
            if (trim($chunk, "\x00") !== '') {
                throw self::fail($archive, 'it has data after the end-of-archive marker');
            }
        }
        if (!feof($in)) {
            throw self::fail($archive, 'it could not be read to the end');
        }
    }

    private static function octal(string $field): ?int
    {
        $digits = trim($field, " \x00");
        if ($digits === '' || strspn($digits, '01234567') !== strlen($digits) || strlen($digits) > 11) {
            return null;
        }

        return (int) octdec($digits);
    }

    private static function showType(string $type): string
    {
        return ctype_print($type) ? "'{$type}'" : sprintf('0x%02x', ord($type));
    }

    private static function fail(string $archive, string $why): ArchiveException
    {
        return new ArchiveException("Refusing the archive {$archive}: {$why}.");
    }
}
