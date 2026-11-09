<?php

declare(strict_types=1);

namespace Dynoxide\Archive;

use Dynoxide\Exception\ArchiveException;

/**
 * Reads the one member out of the Windows release zip.
 */
final class ZipReader
{
    private const MAX_SIZE = 1 << 30;

    public static function extract(string $archive, string $member, string $destination): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new ArchiveException(
                'Installing Dynoxide on Windows needs the zip extension. Enable it in php.ini '
                . '(extension=zip), or point DYNOXIDE_BINARY at a dynoxide.exe you already have.',
            );
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($archive, \ZipArchive::RDONLY);
        if ($opened !== true) {
            throw self::fail($archive, "it could not be opened (zip error {$opened})");
        }

        try {
            if ($zip->count() !== 1) {
                throw self::fail($archive, "it has {$zip->count()} entries where exactly one is expected");
            }
            $stat = $zip->statIndex(0);
            if ($stat === false || $stat['name'] !== $member) {
                $name = $stat === false ? '?' : $stat['name'];
                throw self::fail($archive, "it holds \"{$name}\" where only \"{$member}\" is expected");
            }
            if ($stat['size'] > self::MAX_SIZE) {
                throw self::fail($archive, "the size of \"{$member}\" is not one this reader accepts");
            }

            $opsys = 0;
            $attributes = 0;
            $zip->getExternalAttributesIndex(0, $opsys, $attributes);
            if ($opsys === \ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                throw self::fail($archive, "\"{$member}\" is a symlink");
            }

            self::copy($zip, $member, $destination, $archive, (int) $stat['size'], (int) $stat['crc']);
        } finally {
            $zip->close();
        }
    }

    private static function copy(\ZipArchive $zip, string $member, string $destination, string $archive, int $size, int $crc): void
    {
        $in = $zip->getStream($member);
        if ($in === false) {
            throw self::fail($archive, "\"{$member}\" could not be read");
        }
        $out = @fopen($destination, 'xb');
        if ($out === false) {
            fclose($in);
            throw self::fail($archive, "{$destination} could not be created, or already exists");
        }

        $hash = hash_init('crc32b');
        $written = 0;
        $ok = true;
        while (!feof($in)) {
            $chunk = @fread($in, 65536);
            if ($chunk === false) {
                $ok = false;
                break;
            }
            hash_update($hash, $chunk);
            if (fwrite($out, $chunk) !== strlen($chunk)) {
                $ok = false;
                break;
            }
            $written += strlen($chunk);
        }
        fclose($in);
        $ok = fclose($out) && $ok;

        // The zip stream doesn't always report a bad CRC, so check it here.
        if (!$ok || $written !== $size || hexdec(hash_final($hash)) !== $crc) {
            @unlink($destination);
            throw self::fail($archive, "\"{$member}\" is damaged");
        }
    }

    private static function fail(string $archive, string $why): ArchiveException
    {
        return new ArchiveException("Refusing the archive {$archive}: {$why}.");
    }
}
