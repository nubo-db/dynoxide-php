<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Support;

use PHPUnit\Framework\Attributes\After;

/**
 * A fresh directory per test, removed afterwards.
 */
trait TempDir
{
    private ?string $tempDir = null;

    protected function tempDir(): string
    {
        if ($this->tempDir === null) {
            $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dynoxide-test-' . bin2hex(random_bytes(6));
            mkdir($dir, 0700);
            $this->tempDir = realpath($dir) ?: $dir;
        }

        return $this->tempDir;
    }

    #[After]
    protected function removeTempDir(): void
    {
        if ($this->tempDir !== null) {
            self::removeTree($this->tempDir);
            $this->tempDir = null;
        }
    }

    protected static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0600);
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        @chmod($path, 0700);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        rmdir($path);
    }

    /** @return list<string> */
    protected static function listTree(string $path): array
    {
        $found = [];
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $entry;
            $found[] = $entry;
            if (is_dir($full) && !is_link($full)) {
                foreach (self::listTree($full) as $child) {
                    $found[] = $entry . '/' . $child;
                }
            }
        }

        return $found;
    }
}
