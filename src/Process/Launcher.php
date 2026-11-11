<?php

declare(strict_types=1);

namespace Dynoxide\Process;

use Dynoxide\Binary\LocatedBinary;
use Dynoxide\Download\Url;
use Dynoxide\Exception\StartException;
use Dynoxide\Options;
use Dynoxide\Server;

/**
 * Starts a server and waits until it's ready.
 *
 * @internal
 */
final class Launcher
{
    private const POLL_MICROSECONDS = 25_000;

    /**
     * Turns a binary's path into the command that runs it; replaceable so
     * tests can run fake servers, which are PHP scripts, on every platform.
     *
     * @var \Closure(string): list<string>
     */
    private \Closure $command;

    /** Where each server's output directory is made; replaceable for tests. */
    private string $tempRoot;

    /** How long each step of stopping waits; shortened in tests. */
    private float $graceSeconds = 5.0;

    /**
     * @param array<string, string> $env the environment the server gets, less DYNOXIDE_ENCRYPTION_KEY
     */
    public function __construct(private readonly array $env, private readonly string $osFamily = PHP_OS_FAMILY)
    {
        $this->command = static fn (string $path): array => [$path];
        $this->tempRoot = sys_get_temp_dir();
    }

    public function start(LocatedBinary $binary, Options $options): Server
    {
        $log = $options->logger();
        Leftovers::sweep($this->tempRoot, $this->graceSeconds, $this->osFamily === 'Windows');
        $dir = $this->outputDir();
        $process = $this->spawn($binary, $options, $dir);
        Registry::add($process);
        $spawned = ProcessTree::of($process->pid);
        Leftovers::record($dir, $spawned);

        // Until the Server is returned nobody else can stop it, so anything
        // that goes wrong first, the log callback included, stops it here.
        try {
            $ready = $binary->launcherFlags
                ? $this->readyLine($process, $binary, $options->startTimeout)
                : $this->listeningLine($process, $binary, $options->startTimeout);
            Leftovers::record($dir, $process->rememberTree() + $spawned);

            $log("Dynoxide: {$binary->describe()}, listening on {$ready->endpoint}");
            if (!Url::isLoopback($ready->host)) {
                $log("Dynoxide: warning, the server bound {$ready->host}, which isn't a loopback address, "
                    . 'so other machines may be able to reach it. Dynoxide accepts any credentials.');
            }
        } catch (\Throwable $e) {
            $process->stop();
            throw $e;
        }

        return new Server($ready->endpoint, $ready->host, $ready->port, $process);
    }

    /** A new directory only this user can read, for the server's output. */
    private function outputDir(): string
    {
        $dir = rtrim($this->tempRoot, '/\\') . DIRECTORY_SEPARATOR . 'dynoxide-' . bin2hex(random_bytes(8));
        error_clear_last();
        if (!@mkdir($dir, 0700)) {
            throw new StartException(
                "Couldn't create {$dir} for the server's output: " . (error_get_last()['message'] ?? 'unknown error')
                . '. Check the system temporary directory (' . $this->tempRoot . ') is writable.',
            );
        }
        foreach (['stdout.log', 'stderr.log'] as $name) {
            $file = @fopen($dir . DIRECTORY_SEPARATOR . $name, 'xb');
            if ($file === false) {
                ServerProcess::removeDir($dir);
                throw new StartException("Couldn't create {$dir}" . DIRECTORY_SEPARATOR . "{$name} for the server's output.");
            }
            fclose($file);
        }
        Leftovers::record($dir);

        return $dir;
    }

    private function spawn(LocatedBinary $binary, Options $options, string $dir): ServerProcess
    {
        $args = [...($this->command)($binary->path), 'serve', '--port', '0'];
        if ($binary->launcherFlags) {
            $args[] = '--ready-json';
            $args[] = '--exit-with-parent';
        }
        // One argument each, so a path starting with "-" can't be read as a flag.
        if ($options->dbPath !== null) {
            $args[] = '--db-path=' . $options->dbPath;
        }
        if ($options->schema !== null) {
            $args[] = '--schema=' . $options->schema;
        }

        // Output goes to files: a pipe nobody reads fills up and stalls the
        // server, and PHP can't read one in the background.
        error_clear_last();
        $process = @proc_open(
            $args,
            [
                0 => ['pipe', 'r'],
                1 => ['file', $dir . DIRECTORY_SEPARATOR . 'stdout.log', 'a'],
                2 => ['file', $dir . DIRECTORY_SEPARATOR . 'stderr.log', 'a'],
            ],
            $pipes,
            null,
            $this->childEnv(),
        );
        if ($process === false) {
            ServerProcess::removeDir($dir);
            throw new StartException(
                "Couldn't start {$binary->path}: " . (error_get_last()['message'] ?? 'unknown error') . '.',
            );
        }

        return new ServerProcess($process, $pipes[0], $dir, $binary->launcherFlags, $this->graceSeconds, $this->osFamily === 'Windows');
    }

    /**
     * The parent's environment without DYNOXIDE_ENCRYPTION_KEY: a test server
     * never wants encryption, and a release binary refuses to start with it set.
     *
     * @return array<string, string>
     */
    private function childEnv(): array
    {
        $env = [];
        foreach ($this->env as $name => $value) {
            $drop = $this->osFamily === 'Windows'
                ? strcasecmp((string) $name, 'DYNOXIDE_ENCRYPTION_KEY') === 0
                : $name === 'DYNOXIDE_ENCRYPTION_KEY';
            if (!$drop) {
                $env[(string) $name] = (string) $value;
            }
        }

        return $env;
    }

    /** Waits for the first stdout line of a server started with --ready-json. */
    private function readyLine(ServerProcess $process, LocatedBinary $binary, float $timeout): ReadyLine
    {
        $stdout = $process->outputDir . DIRECTORY_SEPARATOR . 'stdout.log';
        $deadline = microtime(true) + $timeout;
        while (true) {
            $text = (string) @file_get_contents($stdout, false, null, 0, 65536);
            $end = strpos($text, "\n");
            if ($end !== false) {
                try {
                    return ReadyLine::parse(substr($text, 0, $end));
                } catch (\UnexpectedValueException $e) {
                    throw $this->failure($process, $binary, $e->getMessage(), $e);
                }
            }
            $this->checkStillRunning($process, $binary);
            if (microtime(true) >= $deadline) {
                throw $this->failure($process, $binary, "didn't print its ready line within {$timeout} seconds");
            }
            usleep(self::POLL_MICROSECONDS);
        }
    }

    /**
     * For a binary older than the launcher flags: waits for the stderr line
     * "Dynoxide listening on http://<host>:<port>", then for GET / to answer 200.
     */
    private function listeningLine(ServerProcess $process, LocatedBinary $binary, float $timeout): ReadyLine
    {
        $stderr = $process->outputDir . DIRECTORY_SEPARATOR . 'stderr.log';
        $deadline = microtime(true) + $timeout;
        $ready = null;
        while (true) {
            if ($ready === null) {
                $text = (string) @file_get_contents($stderr, false, null, 0, 65536);
                // Only a whole line counts: half-written, "...:8080" could read as port 80.
                if (preg_match('#Dynoxide listening on (http://(\[[0-9a-fA-F:.]+\]|[^\s:/]+):(\d{1,5}))[ \t]*\r?\n#', $text, $m) === 1) {
                    try {
                        $ready = ReadyLine::of($m[1], trim($m[2], '[]'), (int) $m[3]);
                    } catch (\UnexpectedValueException $e) {
                        throw $this->failure($process, $binary, $e->getMessage(), $e);
                    }
                }
            }
            if ($ready !== null && self::answersGet($ready->host, $ready->port, 0.5)) {
                return $ready;
            }
            $this->checkStillRunning($process, $binary);
            if (microtime(true) >= $deadline) {
                throw $this->failure(
                    $process,
                    $binary,
                    $ready === null
                        ? "didn't say it was listening within {$timeout} seconds"
                        : "didn't answer GET {$ready->endpoint}/ within {$timeout} seconds",
                );
            }
            usleep(self::POLL_MICROSECONDS);
        }
    }

    private function checkStillRunning(ServerProcess $process, LocatedBinary $binary): void
    {
        $exited = $process->exited();
        if ($exited === null) {
            return;
        }
        $how = match (true) {
            $exited['signaled'] => "was killed by signal {$exited['termsig']}",
            in_array($exited['exitcode'], [126, 127], true) => "exited with status {$exited['exitcode']}, which usually means it couldn't be executed",
            default => "exited with status {$exited['exitcode']}",
        };

        throw $this->failure($process, $binary, "{$how} before it was ready");
    }

    private function failure(ServerProcess $process, LocatedBinary $binary, string $what, ?\Throwable $previous = null): StartException
    {
        $tail = $process->stderrTail();

        return new StartException(
            "The Dynoxide server ({$binary->describe()}) {$what}."
            . ($tail === '' ? ' It wrote nothing to stderr.' : " Its last stderr lines:\n{$tail}"),
            0,
            $previous,
        );
    }

    /** Whether GET / on the server answers 200, within $timeout seconds. */
    private static function answersGet(string $host, int $port, float $timeout): bool
    {
        $address = str_contains($host, ':') ? "[{$host}]" : $host;
        $socket = @stream_socket_client("tcp://{$address}:{$port}", $errno, $errstr, $timeout);
        if ($socket === false) {
            return false;
        }
        stream_set_timeout($socket, (int) $timeout, (int) (fmod($timeout, 1.0) * 1e6));
        @fwrite($socket, "GET / HTTP/1.1\r\nHost: {$address}:{$port}\r\nConnection: close\r\n\r\n");
        $status = @fgets($socket, 256);
        fclose($socket);

        return is_string($status) && preg_match('#^HTTP/1\.[01] 200\b#', $status) === 1;
    }
}
