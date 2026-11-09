<?php

declare(strict_types=1);

namespace Dynoxide\Cache;

use Dynoxide\Archive\TarReader;
use Dynoxide\Archive\ZipReader;
use Dynoxide\Download\Downloader;
use Dynoxide\Download\Url;
use Dynoxide\Exception\CacheException;
use Dynoxide\Exception\DownloadException;
use Dynoxide\Platform;
use Dynoxide\Release;

/**
 * Puts a verified Dynoxide binary at <cache>/<version>/<target>/, safely when
 * several processes install at once.
 */
final class Installer
{
    public const DEFAULT_BASE_URL = 'https://github.com/nubo-db/dynoxide/releases/download';
    private const SUMS_FILE = 'dynoxide.sha256';
    private const RENAME_ATTEMPTS = 6;

    public function __construct(
        private readonly CacheDirectory $cache,
        private readonly Downloader $downloader,
        private readonly Platform $platform,
        private readonly string $version,
        private readonly string $archiveSha256,
        private readonly string $binarySha256,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
        Url::check($baseUrl);
    }

    /**
     * The installer for the release this package version embeds.
     *
     * @param array<string, string> $env
     */
    public static function forEmbeddedRelease(array $env, ?Platform $platform = null): self
    {
        $platform ??= Platform::detect();
        $base = isset($env['DYNOXIDE_DOWNLOAD_BASE_URL']) && $env['DYNOXIDE_DOWNLOAD_BASE_URL'] !== ''
            ? rtrim($env['DYNOXIDE_DOWNLOAD_BASE_URL'], '/')
            : self::DEFAULT_BASE_URL;

        return new self(
            CacheDirectory::fromEnvironment($env),
            new Downloader($env),
            $platform,
            Release::VERSION,
            Release::archiveSha256($platform->target),
            Release::binarySha256($platform->target),
            $base,
        );
    }

    /** Where the binary is, or will be once installed. */
    public function binaryPath(): string
    {
        return $this->cache->root . DIRECTORY_SEPARATOR . $this->version . DIRECTORY_SEPARATOR
            . $this->platform->target . DIRECTORY_SEPARATOR . $this->platform->binaryName();
    }

    /** The installed binary if there's a good one, without downloading. */
    public function installed(): ?string
    {
        $versionDir = $this->cache->prepare($this->version);
        $dir = $versionDir . DIRECTORY_SEPARATOR . $this->platform->target;

        return $this->verified($dir) ? $dir . DIRECTORY_SEPARATOR . $this->platform->binaryName() : null;
    }

    /**
     * Returns the path of a verified binary, downloading it first if needed.
     * With $force, any existing install is replaced.
     */
    public function install(bool $force = false): string
    {
        $versionDir = $this->cache->prepare($this->version);
        $dir = $versionDir . DIRECTORY_SEPARATOR . $this->platform->target;
        $binary = $dir . DIRECTORY_SEPARATOR . $this->platform->binaryName();

        if (!$force && $this->verified($dir)) {
            return $binary;
        }

        $stage = $this->download($versionDir);
        try {
            for ($attempt = 1; ; $attempt++) {
                if ($attempt > self::RENAME_ATTEMPTS) {
                    throw new CacheException(
                        "Couldn't move the new Dynoxide install into {$dir}: "
                        . (error_get_last()['message'] ?? 'something else keeps replacing it') . '.',
                    );
                }
                if (file_exists($dir) || is_link($dir)) {
                    if (!$force && $this->verified($dir)) {
                        return $binary;
                    }
                    $this->moveAside($versionDir, $dir);
                    $force = false;
                }
                error_clear_last();
                if (@rename($stage, $dir)) {
                    return $binary;
                }
                // If the target exists now, another process got there first, and
                // the next pass uses theirs if it checks out. If it doesn't, a
                // virus scanner on Windows may be holding the new .exe for a moment.
                if (!file_exists($dir)) {
                    usleep(50_000 * (2 ** $attempt));
                }
            }
        } finally {
            if (is_dir($stage)) {
                self::remove($stage);
            }
        }
    }

    /**
     * Downloads, verifies and unpacks into a temporary directory inside the
     * version directory, ready to be renamed into place.
     */
    private function download(string $versionDir): string
    {
        $name = $this->platform->archiveName();
        $releaseUrl = "{$this->baseUrl}/v{$this->version}";
        $random = bin2hex(random_bytes(8));
        $archive = $versionDir . DIRECTORY_SEPARATOR . ".tmp-{$random}-{$name}";
        $stage = $versionDir . DIRECTORY_SEPARATOR . ".tmp-{$random}";

        try {
            $this->downloader->toFile("{$releaseUrl}/{$name}", $archive);

            $actual = (string) hash_file('sha256', $archive);
            if (!hash_equals($this->archiveSha256, $actual)) {
                throw new DownloadException(
                    "{$name} from " . Url::redact($releaseUrl) . " doesn't match the checksum this package "
                    . "carries for Dynoxide {$this->version} (expected {$this->archiveSha256}, got {$actual}). "
                    . 'Nothing was installed.',
                );
            }
            $listed = self::listedHash($this->downloader->toString("{$releaseUrl}/sha256sums.txt"), $name);
            if ($listed === null || !hash_equals($listed, $actual)) {
                throw new DownloadException(
                    "{$name} doesn't match its line in " . Url::redact($releaseUrl) . '/sha256sums.txt'
                    . ($listed === null ? ' (it has no line)' : " (expected {$listed}, got {$actual})")
                    . '. Nothing was installed.',
                );
            }

            if (!@mkdir($stage, 0700)) {
                throw new CacheException("Couldn't create {$stage} in the Dynoxide cache.");
            }
            $binary = $stage . DIRECTORY_SEPARATOR . $this->platform->binaryName();
            $this->platform->isWindows()
                ? ZipReader::extract($archive, $this->platform->binaryName(), $binary)
                : TarReader::extract($archive, $this->platform->binaryName(), $binary);

            $binaryHash = (string) hash_file('sha256', $binary);
            if (!hash_equals($this->binarySha256, $binaryHash)) {
                throw new DownloadException(
                    "The binary inside {$name} doesn't match the checksum this package carries for "
                    . "Dynoxide {$this->version}. Nothing was installed.",
                );
            }
            chmod($binary, 0755);
            if (file_put_contents($stage . DIRECTORY_SEPARATOR . self::SUMS_FILE, $binaryHash . "\n", LOCK_EX) === false) {
                throw new CacheException("Couldn't write the checksum file in {$stage}.");
            }

            return $stage;
        } catch (\Throwable $e) {
            if (is_dir($stage)) {
                self::remove($stage);
            }
            throw $e;
        } finally {
            if (is_file($archive)) {
                @unlink($archive);
            }
        }
    }

    /**
     * An install is usable only if it's a real directory, the binary hashes to
     * what dynoxide.sha256 says, and that is the hash this package expects.
     */
    private function verified(string $dir): bool
    {
        $binary = $dir . DIRECTORY_SEPARATOR . $this->platform->binaryName();
        $sums = $dir . DIRECTORY_SEPARATOR . self::SUMS_FILE;
        if (is_link($dir) || !is_dir($dir) || is_link($binary) || !is_file($binary) || is_link($sums) || !is_file($sums)) {
            return false;
        }

        foreach ([$dir, $binary] as $path) {
            $this->cache->check($path);
        }

        $recorded = @file_get_contents($sums);
        $actual = hash_file('sha256', $binary);

        return $recorded === $this->binarySha256 . "\n"
            && $actual !== false
            && hash_equals($this->binarySha256, $actual);
    }

    /** Renames a bad install out of the way, then deletes it. */
    private function moveAside(string $versionDir, string $dir): void
    {
        $aside = $versionDir . DIRECTORY_SEPARATOR . '.stale-' . bin2hex(random_bytes(8));
        if (!@rename($dir, $aside)) {
            if (!file_exists($dir) && !is_link($dir)) {
                return;
            }
            throw new CacheException(
                "{$dir} in the Dynoxide cache doesn't check out and couldn't be moved aside. Delete it and try again.",
            );
        }
        self::remove($aside);
    }

    private static function listedHash(string $sums, string $name): ?string
    {
        foreach (preg_split('/\r?\n/', $sums) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{64}) [ *]?(\S+)$/', trim($line), $m) === 1 && $m[2] === $name) {
                return $m[1];
            }
        }

        return null;
    }

    /** Deletes a tree without following symlinks. */
    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path) || @rmdir($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($path);
    }
}
