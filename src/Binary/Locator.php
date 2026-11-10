<?php

declare(strict_types=1);

namespace Dynoxide\Binary;

use Dynoxide\Cache\Installer;
use Dynoxide\Environment;
use Dynoxide\Exception\BinaryException;

/**
 * Finds the binary to run, in the launcher contract's order: a path passed
 * in (or, if asked for, one on PATH), then DYNOXIDE_BINARY, then the cache,
 * then a download into the cache.
 */
final class Locator
{
    /**
     * Turns a binary's path into the command that runs it; replaceable so
     * tests can run fake binaries, which are PHP scripts, on every platform.
     *
     * @var \Closure(string): list<string>
     */
    private \Closure $command;

    /** @var array<string, string> */
    private readonly array $env;

    /**
     * @param array<string, string> $env DYNOXIDE_BINARY and PATH are read from here
     * @param \Closure(): Installer $installer called only when the cache is needed, so a
     *        platform without a release binary can still use DYNOXIDE_BINARY
     */
    public function __construct(
        array $env,
        private readonly \Closure $installer,
        private readonly string $osFamily = PHP_OS_FAMILY,
        private readonly float $versionTimeoutSeconds = 10.0,
    ) {
        $this->env = Environment::normalise($env, $osFamily);
        $this->command = static fn (string $path): array => [$path];
    }

    /** @param array<string, string> $env */
    public static function forEmbeddedRelease(array $env): self
    {
        return new self($env, static fn (): Installer => Installer::forEmbeddedRelease($env));
    }

    /**
     * @param string|null $binary a binary to use, from the caller
     * @param bool $fromPath look for dynoxide on PATH in place of $binary
     */
    public function locate(?string $binary = null, bool $fromPath = false): LocatedBinary
    {
        if ($binary !== null && $fromPath) {
            throw new BinaryException('Pass either the binary option or binaryFromPath, not both.');
        }
        if ($binary !== null) {
            return $this->userSupplied($binary, Source::Option);
        }
        if ($fromPath) {
            return $this->userSupplied($this->onPath(), Source::Path);
        }
        $fromEnv = $this->env['DYNOXIDE_BINARY'] ?? null;
        if ($fromEnv !== null && $fromEnv !== '') {
            return $this->userSupplied($fromEnv, Source::Environment);
        }

        $installer = ($this->installer)();
        $source = Source::Cache;
        $path = $installer->installed();
        if ($path === null) {
            $source = Source::Download;
            $path = $installer->install();
        }

        return new LocatedBinary($path, $source, $installer->version, VersionCheck::supportsLauncherFlags($installer->version, $path, self::newerAdvice($source)));
    }

    /**
     * A binary the user chose. A wrong path fails here rather than falling
     * through to the cache, so the user finds out it's wrong.
     */
    private function userSupplied(string $given, Source $source): LocatedBinary
    {
        $where = ucfirst($source->value) . ' points at ';
        if ($given === '') {
            throw new BinaryException(ucfirst($source->value) . ' is empty.');
        }
        // Absolute, so a bare name is never looked up on PATH when it runs.
        // Links aren't resolved: a version manager's shim decides what to run
        // by the name it was called with.
        $path = self::isAbsolute($given) ? $given : $this->fromWorkingDirectory($given, $where);
        if (!file_exists($path)) {
            throw new BinaryException("{$where}{$given}, which doesn't exist.");
        }
        if (!is_file($path)) {
            throw new BinaryException("{$where}{$path}, which isn't a file.");
        }
        if (!$this->isWindows() && !is_executable($path)) {
            throw new BinaryException("{$where}{$path}, which isn't executable. Run chmod +x on it.");
        }

        $version = VersionCheck::run(($this->command)($path), $path, $this->versionTimeoutSeconds);

        return new LocatedBinary($path, $source, $version, VersionCheck::supportsLauncherFlags($version, $path, self::newerAdvice($source)));
    }

    private function fromWorkingDirectory(string $given, string $where): string
    {
        if (preg_match('#^[A-Za-z]:#', $given) === 1) {
            throw new BinaryException("{$where}{$given}, which is relative to that drive's current directory. Give the full path.");
        }
        $cwd = getcwd();
        if ($cwd === false) {
            throw new BinaryException("{$where}{$given}, a relative path, and the current directory can't be read. Give the full path.");
        }

        return rtrim($cwd, '/\\') . DIRECTORY_SEPARATOR . $given;
    }

    /** What to do about a binary too old to start, in terms of how it was found. */
    private static function newerAdvice(Source $source): string
    {
        $download = 'to download the version this package expects';

        return match ($source) {
            Source::Option => "Pass a newer one, or leave the binary option out {$download}.",
            Source::Path => "Upgrade the dynoxide on PATH, or turn binaryFromPath off {$download}.",
            Source::Environment => "Point DYNOXIDE_BINARY at a newer one, or unset it {$download}.",
            Source::Cache, Source::Download => 'Update this package.',
        };
    }

    /**
     * The first dynoxide on PATH. Relative entries are skipped, since they
     * depend on the directory the tests happen to run from.
     */
    private function onPath(): string
    {
        $name = $this->isWindows() ? 'dynoxide.exe' : 'dynoxide';
        foreach (explode($this->isWindows() ? ';' : ':', $this->env['PATH'] ?? '') as $dir) {
            $dir = trim($dir, $this->isWindows() ? " \"" : '');
            if (!self::isAbsolute($dir)) {
                continue;
            }
            $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && ($this->isWindows() || is_executable($candidate))) {
                return $candidate;
            }
        }

        throw new BinaryException(
            "binaryFromPath is on, but there's no {$name} on PATH. Install Dynoxide there, "
            . 'or turn binaryFromPath off to use the version this package downloads.',
        );
    }

    private function isWindows(): bool
    {
        return $this->osFamily === 'Windows';
    }

    private static function isAbsolute(string $dir): bool
    {
        return str_starts_with($dir, '/') || str_starts_with($dir, '\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $dir) === 1;
    }
}
