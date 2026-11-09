<?php

declare(strict_types=1);

namespace Dynoxide\Cache;

use Dynoxide\Archive\TarReader;
use Dynoxide\Archive\ZipReader;
use Dynoxide\Download\Downloader;
use Dynoxide\Download\Sha256Sums;
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

    /**
     * Temporary files older than this belong to an install that was killed:
     * no download runs this long.
     */
    private const STALE_AFTER_SECONDS = 3600;

    private const DOWNLOAD_HINT = ' Behind a proxy? Set https_proxy. To use a mirror of the release files, set '
        . 'DYNOXIDE_DOWNLOAD_BASE_URL. Or point DYNOXIDE_BINARY at a Dynoxide binary you already have.';

    private readonly string $baseUrl;

    /** Moves a directory into place; replaceable so tests can make it fail. */
    private \Closure $rename;

    /** The first wait between rename attempts, doubling each time. */
    private int $backoffMicroseconds = 50_000;

    public function __construct(
        private readonly CacheDirectory $cache,
        private readonly Downloader $downloader,
        private readonly Platform $platform,
        private readonly string $version,
        private readonly string $archiveSha256,
        private readonly string $binarySha256,
        string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        Url::check($this->baseUrl);
        $this->rename = static fn (string $from, string $to): bool => @rename($from, $to);
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
            ? $env['DYNOXIDE_DOWNLOAD_BASE_URL']
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

    /**
     * The installed binary if there's a good one. Changes nothing on disk, so
     * it works on a cache that's read-only.
     */
    public function installed(): ?string
    {
        $versionDir = $this->cache->find($this->version);
        if ($versionDir === null) {
            return null;
        }
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

        $this->sweep($versionDir);
        $stage = $this->download($versionDir);
        try {
            $replace = $force;
            $error = null;
            for ($attempt = 1; $attempt <= self::RENAME_ATTEMPTS; $attempt++) {
                if (file_exists($dir) || is_link($dir)) {
                    if (!$replace && $this->verified($dir)) {
                        return $binary;
                    }
                    $this->moveAside($versionDir, $dir, keepIfGood: !$replace);
                    $replace = false;
                    if (file_exists($dir)) {
                        // Another process's good install, which moveAside put back.
                        continue;
                    }
                }
                error_clear_last();
                if (($this->rename)($stage, $dir)) {
                    return $binary;
                }
                $error = error_get_last()['message'] ?? null;
                // If the target exists now, another process got there first, and
                // the next pass uses theirs if it checks out. If it doesn't, a
                // virus scanner on Windows may be holding the new .exe for a moment.
                if ($attempt < self::RENAME_ATTEMPTS && !file_exists($dir)) {
                    usleep($this->backoffMicroseconds * (2 ** ($attempt - 1)));
                }
            }

            throw new CacheException(
                "Couldn't move the new Dynoxide install into {$dir}: "
                . ($error === null ? 'something else keeps replacing it' : preg_replace('/^\w+\([^)]*\): /', '', $error))
                . '. Check nothing else is changing the Dynoxide cache, or set DYNOXIDE_CACHE_DIR to another directory.',
            );
        } finally {
            if (is_dir($stage)) {
                self::remove($stage);
            }
        }
    }

    /**
     * Removes temporary files and directories that a killed install left
     * behind. Recent ones may belong to an install still running, so stay.
     */
    private function sweep(string $versionDir): void
    {
        $cutoff = time() - self::STALE_AFTER_SECONDS;
        foreach (@scandir($versionDir) ?: [] as $entry) {
            if (!str_starts_with($entry, '.tmp-') && !str_starts_with($entry, '.stale-')) {
                continue;
            }
            $path = $versionDir . DIRECTORY_SEPARATOR . $entry;
            $stat = @lstat($path);
            if ($stat !== false && $stat['mtime'] < $cutoff) {
                self::remove($path);
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
        $mirrorHint = " If DYNOXIDE_DOWNLOAD_BASE_URL points at a mirror, check it serves the Dynoxide {$this->version} "
            . 'release files unchanged.';

        try {
            try {
                $this->downloader->toFile("{$releaseUrl}/{$name}", $archive);
                $sums = $this->downloader->toString("{$releaseUrl}/sha256sums.txt");
            } catch (DownloadException $e) {
                throw new DownloadException($e->getMessage() . self::DOWNLOAD_HINT, 0, $e);
            }

            $actual = (string) hash_file('sha256', $archive);
            if (!hash_equals($this->archiveSha256, $actual)) {
                throw new DownloadException(
                    "{$name} from " . Url::redact($releaseUrl) . " doesn't match the checksum this package "
                    . "carries for Dynoxide {$this->version} (expected {$this->archiveSha256}, got {$actual}). "
                    . 'Nothing was installed.' . $mirrorHint,
                );
            }
            $listed = Sha256Sums::parse($sums)[$name] ?? null;
            if ($listed === null || !hash_equals($listed, $actual)) {
                throw new DownloadException(
                    "{$name} doesn't match its line in " . Url::redact($releaseUrl) . '/sha256sums.txt'
                    . ($listed === null ? ' (it has no line)' : " (expected {$listed}, got {$actual})")
                    . '. Nothing was installed.' . $mirrorHint,
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
                    . "Dynoxide {$this->version} (expected {$this->binarySha256}, got {$binaryHash}). "
                    . 'Nothing was installed.' . $mirrorHint,
                );
            }
            $record = $binaryHash . "\n";
            if (!chmod($binary, 0755)
                || file_put_contents($stage . DIRECTORY_SEPARATOR . self::SUMS_FILE, $record, LOCK_EX) !== strlen($record)) {
                throw new CacheException("Couldn't finish writing the install in {$stage}; is the disk full?");
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
     *
     * dynoxide.sha256 is read leniently, as its first word in any case, so an
     * install another wrapper wrote as "<hash>  dynoxide" or without a newline
     * is still used. That costs nothing: the binary itself is hashed too.
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
        if ($recorded === false) {
            return false;
        }
        $words = preg_split('/\s+/', trim($recorded), 2) ?: [''];
        if (!hash_equals($this->binarySha256, strtolower($words[0]))) {
            return false;
        }
        $actual = hash_file('sha256', $binary);

        return $actual !== false && hash_equals($this->binarySha256, $actual);
    }

    /**
     * Renames an install out of the way, then deletes it. Between deciding to
     * do this and doing it, another process may have put a good install
     * there; with $keepIfGood that one is put back instead.
     */
    private function moveAside(string $versionDir, string $dir, bool $keepIfGood): void
    {
        $aside = $versionDir . DIRECTORY_SEPARATOR . '.stale-' . bin2hex(random_bytes(8));
        if (!($this->rename)($dir, $aside)) {
            if (!file_exists($dir) && !is_link($dir)) {
                return;
            }
            throw new CacheException(
                "Couldn't move {$dir} aside to replace it. If a Dynoxide server from it is still running, stop "
                . 'it; otherwise delete the directory and try again, or set DYNOXIDE_CACHE_DIR to another directory.',
            );
        }
        // A rename keeps the old modification time, which would make this look
        // like something a killed install left behind to another process's sweep.
        // A link is never kept, and touching it would touch whatever it points at.
        if (!is_link($aside)) {
            @touch($aside);
        }
        if ($keepIfGood && $this->verified($aside) && ($this->rename)($aside, $dir)) {
            return;
        }
        self::remove($aside);
    }

    /** Deletes a tree without following symlinks. */
    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path) || @rmdir($path);

            return;
        }
        foreach (@scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($path);
    }
}
