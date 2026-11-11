<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Support;

use Dynoxide\Cache\CacheDirectory;
use Dynoxide\Cache\Installer;
use Dynoxide\Download\Downloader;
use Dynoxide\Platform;

/**
 * A fake Dynoxide release served over HTTP: one target's archive and its
 * sha256sums.txt, laid out as GitHub serves them, and an Installer pointed
 * at it.
 */
final class ServedRelease
{
    public ?Served $server = null;
    public string $binary = '';
    public string $archiveHash = '';
    public string $binaryHash = '';

    public function __construct(private readonly string $dir)
    {
    }

    /**
     * Serves a release for one target. $archive and $sums replace the real
     * ones, and are what get hashed. $corrupt replaces the archive after it
     * was hashed, as a download damaged on the way would.
     */
    public function publish(
        string $version,
        string $target,
        ?string $archive = null,
        ?string $sums = null,
        ?string $binary = null,
        ?string $corrupt = null,
    ): void {
        $platform = Platform::fromTarget($target);
        $dir = $this->dir . '/release-' . $version;
        @mkdir($dir);
        $this->binary = $binary ?? "binary for {$version} " . random_bytes(3000);
        $path = "{$dir}/" . $platform->archiveName();

        if ($archive !== null) {
            file_put_contents($path, $archive);
        } elseif ($platform->isWindows()) {
            $zip = new \ZipArchive();
            $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            $zip->addFromString('dynoxide.exe', $this->binary);
            $zip->close();
        } else {
            (new TarBuilder(TarBuilder::GNU))->add('dynoxide', $this->binary)->writeGz($path);
        }
        $this->archiveHash = (string) hash_file('sha256', $path);
        $this->binaryHash = hash('sha256', $this->binary);
        if ($corrupt !== null) {
            file_put_contents($path, $corrupt);
        }
        file_put_contents("{$dir}/sha256sums.txt", $sums ?? "{$this->archiveHash}  {$platform->archiveName()}\n");

        $routes = [
            "/v{$version}/" . $platform->archiveName() => ['file' => $path],
            "/v{$version}/sha256sums.txt" => ['file' => "{$dir}/sha256sums.txt"],
        ];
        if ($this->server === null) {
            $this->server = Served::http($this->dir, $routes);
        } else {
            $this->server->routes($routes);
        }
    }

    public function installer(
        string $cacheRoot,
        string $version,
        string $target,
        ?string $archiveHash = null,
        ?string $binaryHash = null,
    ): Installer {
        return new Installer(
            new CacheDirectory($cacheRoot),
            new Downloader([], 10.0, 5.0),
            Platform::fromTarget($target),
            $version,
            $archiveHash ?? $this->archiveHash,
            $binaryHash ?? $this->binaryHash,
            $this->server?->url() ?? 'https://example.invalid',
        );
    }

    /** How many archives have been downloaded. */
    public function downloads(): int
    {
        return count(array_filter(
            $this->server?->log() ?? [],
            static fn (array $r): bool => str_ends_with($r['target'], '.tar.gz') || str_ends_with($r['target'], '.zip'),
        ));
    }

    public function stop(): void
    {
        $this->server?->stop();
    }
}
