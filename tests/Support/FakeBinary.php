<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Support;

use Dynoxide\Process\ProcessTree;

/**
 * A stand-in for the dynoxide binary: a PHP script, run through the PHP that
 * runs the tests, so the same fake works on Windows.
 */
final class FakeBinary
{
    /** @param list<string> $command */
    private function __construct(public readonly string $path, public readonly array $command)
    {
    }

    public static function printing(string $dir, string $output, string $name = 'dynoxide'): self
    {
        return self::script($dir, 'echo ' . var_export($output, true) . ';', $name);
    }

    /** A fake whose body is $php, in its own directory under $dir. */
    public static function script(string $dir, string $php, string $name = 'dynoxide'): self
    {
        $home = $dir . DIRECTORY_SEPARATOR . 'fake-' . bin2hex(random_bytes(4));
        mkdir($home, 0700);
        $path = $home . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, "<?php\n{$php}\n");
        chmod($path, 0755);
        $path = realpath($path) ?: $path;

        return new self($path, self::command($path));
    }

    /**
     * A wrapper that starts a long-running child of its own, writes the
     * child's process ID to $pidFile, then sleeps: the kind of wrapper whose
     * child outlives it when only the wrapper is killed. The child inherits
     * the wrapper's stdout and stderr, as a forked process would. Once a
     * file named $pidFile . '.exit' appears, the wrapper exits by itself and
     * leaves the child running.
     */
    public static function forking(string $dir, string $pidFile): self
    {
        $grandchild = var_export([PHP_BINARY, '-r', 'sleep(20);'], true);
        $file = var_export($pidFile, true);

        return self::script($dir, <<<PHP
            \$child = proc_open({$grandchild}, [], \$pipes);
            file_put_contents({$file}, (string) proc_get_status(\$child)['pid']);
            \$until = microtime(true) + 20;
            while (!is_file({$file} . '.exit') && microtime(true) < \$until) {
                usleep(20_000);
            }
            exit(0);
            PHP);
    }

    /**
     * A process ID with what identifies it, read while it's known to be the
     * fake's, so a clean-up later can't kill a process that reused the ID.
     *
     * @return array<int, string>
     */
    public static function identify(int $pid): array
    {
        return PHP_OS_FAMILY === 'Windows' ? [$pid => ''] : array_slice(ProcessTree::of($pid), 0, 1, true);
    }

    /** @param array<int, string> $identified from identify() */
    public static function killIdentified(array $identified): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            foreach (array_keys($identified) as $pid) {
                self::kill($pid);
            }

            return;
        }
        ProcessTree::signal($identified, 9);
    }

    /**
     * Whether a process with this ID is running. A zombie isn't: in a
     * container whose first process doesn't reap orphans, a killed child
     * stays one.
     */
    public static function alive(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            exec("tasklist /FI \"PID eq {$pid}\" /NH", $lines);

            return preg_match('/\\b' . $pid . '\\b/', implode("\n", $lines)) === 1;
        }
        if (is_dir('/proc/self')) {
            $stat = @file_get_contents("/proc/{$pid}/stat");

            return $stat !== false && substr($stat, (int) strrpos($stat, ')') + 2, 1) !== 'Z';
        }
        exec('ps -o stat= -p ' . $pid . ' 2>/dev/null', $lines, $status);
        $state = trim($lines[0] ?? '');

        return $status === 0 && $state !== '' && $state[0] !== 'Z';
    }

    /** Kills a process left behind by a test that failed. */
    public static function kill(int $pid): void
    {
        exec(PHP_OS_FAMILY === 'Windows' ? "taskkill /F /PID {$pid} 2>NUL" : "kill -9 {$pid} 2>/dev/null");
    }

    /** @return list<string> */
    public static function command(string $path): array
    {
        return [PHP_BINARY, $path];
    }
}
