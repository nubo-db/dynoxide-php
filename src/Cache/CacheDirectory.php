<?php

declare(strict_types=1);

namespace Dynoxide\Cache;

use Dynoxide\Environment;
use Dynoxide\Exception\CacheException;

/**
 * The shared Dynoxide cache, which every wrapper (PHP, Python, Node) uses with
 * the same layout, and the checks that keep it to the current user.
 */
final class CacheDirectory
{
    private ?int $uid = null;

    /**
     * @param bool $checkOwnership false on Windows, where %LOCALAPPDATA% is per-user
     * @param int|null $uid the user to check against; worked out on first use when null
     */
    public function __construct(
        public readonly string $root,
        private readonly bool $checkOwnership = PHP_OS_FAMILY !== 'Windows',
        ?int $uid = null,
    ) {
        $this->uid = $uid;
    }

    /**
     * @param array<string, string> $env
     */
    public static function fromEnvironment(array $env, string $osFamily = PHP_OS_FAMILY): self
    {
        $env = Environment::normalise($env, $osFamily);
        $set = static fn (string $name): ?string => isset($env[$name]) && $env[$name] !== '' ? $env[$name] : null;
        $separator = $osFamily === 'Windows' ? '\\' : '/';

        $root = match (true) {
            $set('DYNOXIDE_CACHE_DIR') !== null => $set('DYNOXIDE_CACHE_DIR'),
            $set('XDG_CACHE_HOME') !== null => rtrim($set('XDG_CACHE_HOME'), '/\\') . $separator . 'dynoxide',
            $osFamily === 'Windows' && $set('LOCALAPPDATA') !== null => rtrim($set('LOCALAPPDATA'), '/\\') . '\\dynoxide',
            $osFamily !== 'Windows' && $set('HOME') !== null => rtrim($set('HOME'), '/') . '/.cache/dynoxide',
            default => throw new CacheException(
                'Couldn\'t work out where the Dynoxide cache goes: '
                . ($osFamily === 'Windows' ? 'LOCALAPPDATA' : 'HOME') . ' isn\'t set. '
                . 'Set DYNOXIDE_CACHE_DIR to a directory you can write to.',
            ),
        };

        return new self($root, $osFamily !== 'Windows');
    }

    /**
     * Creates the root and each directory below it as needed, mode 0700, and
     * checks each one. Returns the deepest path.
     */
    public function prepare(string ...$segments): string
    {
        $this->makeRoot();

        $path = $this->root;
        foreach ($segments as $segment) {
            $path .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($path)) {
                throw $this->refuse($path, 'it\'s a symlink, which the cache doesn\'t follow', "remove {$path}");
            }
            error_clear_last();
            if (!is_dir($path) && !@mkdir($path, 0700) && !is_dir($path)) {
                throw $this->unwritable($path);
            }
            $this->check($path);
        }

        return $path;
    }

    /**
     * The path below the root if it exists, checked as prepare() would check
     * it, or null. Creates nothing, so it works on a read-only cache.
     */
    public function find(string ...$segments): ?string
    {
        if (!is_dir($this->root)) {
            return null;
        }
        $this->check($this->root);

        $path = $this->root;
        foreach ($segments as $segment) {
            $path .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($path)) {
                throw $this->refuse($path, 'it\'s a symlink, which the cache doesn\'t follow', "remove {$path}");
            }
            if (!is_dir($path)) {
                return null;
            }
            $this->check($path);
        }

        return $path;
    }

    /**
     * On Linux and macOS, refuses a path that another user owns or that group
     * or other can write to. Callers refuse symlinks below the root before
     * this, so following one here only ever follows the root.
     */
    public function check(string $path): void
    {
        if (!$this->checkOwnership) {
            return;
        }

        clearstatcache(true, $path);
        $stat = @stat($path);
        if ($stat === false) {
            throw new CacheException("Couldn't read {$path} in the Dynoxide cache.");
        }

        $uid = $this->currentUid();
        if ($stat['uid'] !== $uid) {
            throw $this->refuse(
                $path,
                "it's owned by user {$stat['uid']}, not by you (user {$uid})",
                "chown it to yourself",
            );
        }
        if (($stat['mode'] & 0022) !== 0) {
            throw $this->refuse(
                $path,
                sprintf('group or other can write to it (mode %o)', $stat['mode'] & 0777),
                is_dir($path) ? "chmod 700 {$path}" : "chmod 755 {$path}",
            );
        }
    }

    private function makeRoot(): void
    {
        if (!is_dir($this->root)) {
            error_clear_last();
            if (!@mkdir($this->root, 0700, true) && !is_dir($this->root)) {
                throw $this->unwritable($this->root);
            }
        }
        $this->check($this->root);
    }

    private function currentUid(): int
    {
        if ($this->uid !== null) {
            return $this->uid;
        }
        if (function_exists('posix_geteuid')) {
            return $this->uid = posix_geteuid();
        }

        // Without the posix extension, the owner of a file we just made is us.
        // getmyuid() would be the owner of the script, not the process. The
        // probe goes in the temp directory so a read-only cache still works.
        $probe = @tempnam(sys_get_temp_dir(), 'dynoxide-owner-');
        $owner = $probe === false ? false : @fileowner($probe);
        if ($probe !== false) {
            @unlink($probe);
        }
        if ($owner === false) {
            throw new CacheException(
                'Couldn\'t work out which user this process runs as: the posix extension isn\'t loaded and '
                . 'the temp directory ' . sys_get_temp_dir() . ' isn\'t writable. Enable the posix extension.',
            );
        }

        return $this->uid = $owner;
    }

    private function unwritable(string $path): CacheException
    {
        $error = error_get_last()['message'] ?? '';

        return new CacheException(
            "Couldn't create the Dynoxide cache directory {$path}"
            . ($error === '' ? '' : ': ' . preg_replace('/^\w+\([^)]*\): /', '', $error))
            . '. Set DYNOXIDE_CACHE_DIR to a directory you can write to.',
        );
    }

    private function refuse(string $path, string $problem, string $fix): CacheException
    {
        return new CacheException(
            "Refusing to use {$path} in the Dynoxide cache: {$problem}. "
            . "Fix it ({$fix}), or set DYNOXIDE_CACHE_DIR to a directory of your own.",
        );
    }
}
