<?php

declare(strict_types=1);

namespace Dynoxide\Binary;

use Dynoxide\Exception\BinaryException;
use Dynoxide\Process\ProcessTree;

/**
 * Asks a binary of unknown version which version it is, and decides how it
 * can be started.
 */
final class VersionCheck
{
    /** The first version that knows --ready-json and --exit-with-parent. */
    public const LAUNCHER_FLAGS_SINCE = '1.7.0';

    /**
     * The oldest version that can be started at all: earlier ones print the
     * port asked for rather than the one bound, so --port 0 can't work.
     */
    public const OLDEST_SUPPORTED = '1.6.0';

    private const MAX_OUTPUT_BYTES = 4096;

    /**
     * Runs `<binary> --version` and returns the version it prints.
     *
     * Output goes to files, not pipes: pipes can't be waited on with a
     * timeout on Windows.
     *
     * @param list<string> $command the binary, or for tests an interpreter and a script
     */
    public static function run(array $command, string $path, float $timeoutSeconds = 10.0): string
    {
        $out = self::tempFile();
        try {
            $err = self::tempFile();
        } catch (BinaryException $e) {
            @unlink($out);
            throw $e;
        }
        try {
            error_clear_last();
            $process = @proc_open(
                [...$command, '--version'],
                [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
                $pipes,
            );
            if ($process === false) {
                throw new BinaryException(
                    "Couldn't run {$path}: " . (error_get_last()['message'] ?? 'unknown error') . '.',
                );
            }
            fclose($pipes[0]);

            // Keep the first status that says it has stopped: PHP reports an
            // exit code only once on some versions.
            $deadline = microtime(true) + $timeoutSeconds;
            while (($status = proc_get_status($process))['running']) {
                if (microtime(true) >= $deadline) {
                    // A wrapper's child would outlive the wrapper, and on
                    // Windows keep the output files open.
                    if (PHP_OS_FAMILY === 'Windows') {
                        ProcessTree::killOnWindows($status['pid']);
                    } else {
                        ProcessTree::signal(ProcessTree::of($status['pid']), 9);
                    }
                    proc_terminate($process, 9);
                    proc_close($process);
                    throw new BinaryException(
                        "{$path} --version didn't finish within {$timeoutSeconds} seconds. Is it a Dynoxide binary?",
                    );
                }
                usleep(10_000);
            }
            proc_close($process);

            $stdout = (string) @file_get_contents($out, false, null, 0, self::MAX_OUTPUT_BYTES);
            $stderr = (string) @file_get_contents($err, false, null, 0, self::MAX_OUTPUT_BYTES);
        } finally {
            @unlink($out);
            @unlink($err);
        }

        if ($status['signaled']) {
            throw new BinaryException("{$path} --version was killed by signal {$status['termsig']}." . self::tail($stderr));
        }
        if ($status['exitcode'] !== 0) {
            // A binary that couldn't be executed shows up as 127 (PHP before 8.3
            // reports it that way), or as 126 when the system handed the file
            // to /bin/sh, which couldn't run it either.
            throw new BinaryException(
                "{$path} --version failed with exit status {$status['exitcode']}"
                . (in_array($status['exitcode'], [126, 127], true) ? ', which usually means it couldn\'t be executed at all' : '')
                . '.' . self::tail($stderr),
            );
        }

        $version = self::parse($stdout);
        if ($version === null) {
            throw new BinaryException(
                "{$path} --version printed " . self::shown($stdout) . ', not "dynoxide <version>". Is it a Dynoxide binary?',
            );
        }

        return $version;
    }

    /** The version from `dynoxide 1.7.0` or `dynoxide 1.7.0-rc.1`, or null. */
    public static function parse(string $output): ?string
    {
        $line = trim(strtok(ltrim($output), "\n") ?: '');
        $ok = preg_match('/^dynoxide (\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?)$/', $line, $m);

        return $ok === 1 ? $m[1] : null;
    }

    /**
     * Whether this version is started with the launcher flags. A pre-release
     * counts as its release, so 1.7.0-rc.1 has them. Refuses anything older
     * than OLDEST_SUPPORTED, with $advice on what to do instead.
     */
    public static function supportsLauncherFlags(string $version, string $path, string $advice): bool
    {
        $numeric = self::numeric($version);
        if ($numeric === null) {
            throw new BinaryException("{$path} reports version {$version}, which isn't a version this package understands.");
        }
        if (version_compare($numeric, self::OLDEST_SUPPORTED, '<')) {
            throw new BinaryException(
                "{$path} is Dynoxide {$version}, and this package needs " . self::OLDEST_SUPPORTED . " or later. {$advice}",
            );
        }

        return version_compare($numeric, self::LAUNCHER_FLAGS_SINCE, '>=');
    }

    /** X.Y.Z without any pre-release or build part. */
    private static function numeric(string $version): ?string
    {
        return preg_match('/^(\d+\.\d+\.\d+)(?:[-+].*)?$/', $version, $m) === 1 ? $m[1] : null;
    }

    private static function tempFile(): string
    {
        $file = @tempnam(sys_get_temp_dir(), 'dynoxide-version-');
        if ($file === false) {
            throw new BinaryException("Couldn't create a temporary file in " . sys_get_temp_dir() . ' to check the binary\'s version.');
        }

        return $file;
    }

    private static function tail(string $stderr): string
    {
        $stderr = trim($stderr);

        return $stderr === '' ? '' : ' It said: ' . self::shown($stderr);
    }

    private static function shown(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return 'nothing';
        }
        $text = (string) preg_replace('/[^\x20-\x7e]+/', ' ', $text);

        return '"' . (strlen($text) > 200 ? substr($text, 0, 200) . '...' : $text) . '"';
    }
}
