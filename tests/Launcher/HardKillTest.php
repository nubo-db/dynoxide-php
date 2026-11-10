<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Launcher;

use Dynoxide\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Kills the process that started a server outright, with no chance to run
 * shutdown functions, and checks the server goes too. proc_terminate() with
 * signal 9 is SIGKILL on Unix and TerminateProcess on Windows.
 *
 * DYNOXIDE_TEST_BINARY names a 1.7.0 or later binary; without it these tests
 * are skipped.
 */
final class HardKillTest extends TestCase
{
    use TempDir;

    private const ROOT = __DIR__ . '/../..';

    private const SCRIPT = __DIR__ . '/../Fixtures/scripts/hold-server.php';

    /** @var list<resource> */
    private array $processes = [];

    /** @var array<string, int> the server's process ID for each endpoint a script reported */
    private array $pids = [];

    /** @var list<string> */
    private array $endpoints = [];

    private string $binary;

    protected function setUp(): void
    {
        $binary = getenv('DYNOXIDE_TEST_BINARY');
        if ($binary === false || $binary === '') {
            self::markTestSkipped('Set DYNOXIDE_TEST_BINARY to a Dynoxide 1.7.0 or later binary.');
        }
        $this->binary = $binary;
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            proc_terminate($process, 9);
            proc_close($process);
        }
        // A server left by a test that failed, or by the control that leaves
        // one on purpose. Those started under PHPUnit or ParaTest are found by
        // their port.
        // Only servers still answering, so a process ID that has been freed
        // and reused is never killed.
        $strays = [];
        foreach (array_unique($this->endpoints) as $endpoint) {
            if (self::answers($endpoint)) {
                $found = isset($this->pids[$endpoint]) ? [$this->pids[$endpoint]] : self::listeningOn((int) parse_url($endpoint, PHP_URL_PORT));
                array_push($strays, ...$found);
            }
        }
        // Through the shell's own kill, since slim images have no kill program.
        foreach (array_unique($strays) as $pid) {
            self::command(PHP_OS_FAMILY === 'Windows' ? ['taskkill', '/F', '/PID', (string) $pid] : ['sh', '-c', 'kill -9 "$1" 2>/dev/null', 'sh', (string) $pid]);
        }
        // Windows keeps a killed process's files open for a moment, and the
        // temporary directory is removed next.
        $deadline = microtime(true) + 5;
        while (array_filter($this->endpoints, self::answers(...)) !== [] && microtime(true) < $deadline) {
            usleep(50_000);
        }
    }

    public function testAKilledScriptTakesItsServerWithIt(): void
    {
        [$process, $line] = $this->hold('package');
        self::assertTrue(self::answers($line['endpoint']), 'up before the kill, so the port shows whether it is running');

        proc_terminate($process, 9);

        self::assertGoneWithin(5.0, [$line['endpoint']]);
    }

    /**
     * Control: with stdin on a file rather than a pipe, end-of-file comes at
     * once and the server stops by itself, cleanly, with nothing killed. So
     * end-of-file on stdin is what stops a server whose parent has died.
     */
    public function testAServerWithStdinOnAFileStopsStraightAway(): void
    {
        [, $line] = $this->hold('stdin-file');

        self::assertSame(0, $line['exited'], 'it stopped by itself, within ten seconds, and cleanly');
        self::assertFalse(self::answers($line['endpoint']));
    }

    /**
     * Control: without --exit-with-parent the server outlives a killed
     * parent, so the first test would catch the flag going missing.
     */
    public function testWithoutTheFlagTheServerOutlivesAKilledParent(): void
    {
        [$process, $line] = $this->hold('no-flag');

        proc_terminate($process, 9);
        usleep(5_000_000);

        self::assertTrue(self::answers($line['endpoint']), 'still up five seconds after its parent was killed');
    }

    public function testAKilledPHPUnitRunTakesItsServerWithIt(): void
    {
        [$process, $endpoints] = $this->runHeld('phpunit', [], 1, 600);

        proc_terminate($process, 9);

        self::assertGoneWithin(5.0, $endpoints);
    }

    /**
     * Killing ParaTest's parent leaves its workers inside their tests. Each
     * worker finishes the test it's in, finds its parent gone and exits
     * without starting another, and its server goes with it. So a server
     * outlives the parent by up to the length of one test. The tests here
     * hold for six seconds.
     */
    public function testAKilledParaTestRunTakesItsServersWithItAfterTheirCurrentTests(): void
    {
        [$process, $endpoints, $report] = $this->runHeld('paratest', ['--processes=3'], 3, 6);
        $lines = file($report, FILE_SKIP_EMPTY_LINES) ?: [];
        $workers = array_values(array_unique(array_map(static fn (string $line): int => json_decode($line, true, 8, JSON_THROW_ON_ERROR)['pid'], $lines)));

        proc_terminate($process, 9);

        self::assertGoneWithin(6.0 + 5.0, $endpoints);
        // A worker stops its server and then tidies its own files, so wait
        // for the workers too, before the temporary directory goes.
        $deadline = microtime(true) + 10;
        while (array_filter($workers, self::isRunning(...)) !== [] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        $left = array_values(array_filter($workers, self::isRunning(...)));
        self::assertSame([], $left, 'every worker exited: ' . implode('; ', array_map(self::describe(...), $left)));
        self::assertCount(count($lines), file($report, FILE_SKIP_EMPTY_LINES) ?: [], 'no worker started another test');
    }

    /** What reached the database file before the kill is there for the next server. */
    public function testADatabaseFileKeepsWhatWasWrittenBeforeAHardKill(): void
    {
        $dbPath = $this->tempDir() . DIRECTORY_SEPARATOR . 'killed.db';
        [$process, $line] = $this->hold('package', $dbPath);
        PersistenceTest::write($line['endpoint']);

        proc_terminate($process, 9);
        self::assertGoneWithin(5.0, [$line['endpoint']]);

        [, $again] = $this->hold('package', $dbPath);
        self::assertSame(['S' => 'still here'], PersistenceTest::read($again['endpoint']));
    }

    /** @return array{resource, array{endpoint: string, pid: int, exited?: int|null}} */
    private function hold(string $mode, ?string $dbPath = null): array
    {
        $out = $this->tempDir() . "/{$mode}.txt";
        $process = proc_open(
            [PHP_BINARY, self::SCRIPT, $mode, $this->binary, $this->tempDir(), ...($dbPath === null ? [] : [$dbPath])],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $out, 'a']],
            $pipes,
            null,
            $this->childEnv(),
        );
        self::assertIsResource($process);
        $this->processes[] = $process;
        $line = fgets($pipes[1]);
        self::assertIsString($line, 'the script printed nothing: ' . file_get_contents($out));
        $line = json_decode($line, true, 4, JSON_THROW_ON_ERROR);
        $this->pids[$line['endpoint']] = $line['pid'];
        $this->endpoints[] = $line['endpoint'];

        return [$process, $line];
    }

    /**
     * The environment for a child: this one, less any test runner's, with
     * the temporary directory inside this test's, so the output directories
     * of servers whose parents are killed don't build up.
     *
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function childEnv(array $extra = []): array
    {
        $env = getenv();
        foreach (array_keys($env) as $name) {
            if (in_array(strtoupper((string) $name), ['PARATEST', 'TEST_TOKEN', 'UNIQUE_TEST_TOKEN', 'AWS_ENDPOINT_URL_DYNAMODB', 'DYNAMODB_ENDPOINT', 'TMPDIR', 'TEMP', 'TMP'], true)) {
                unset($env[$name]);
            }
        }
        $tmp = $this->tempDir() . DIRECTORY_SEPARATOR . 'tmp';
        @mkdir($tmp);

        return array_merge($env, ['TMPDIR' => $tmp, 'TEMP' => $tmp, 'TMP' => $tmp], $extra);
    }

    /**
     * Runs the fixture project's tests under a tool with each test held open,
     * and returns once the given number of servers have tests inside them.
     *
     * @param list<string> $args
     * @return array{resource, list<string>, string}
     */
    private function runHeld(string $tool, array $args, int $inside, int $holdSeconds): array
    {
        $script = self::ROOT . "/vendor/bin/{$tool}";
        if (!is_file($script)) {
            self::markTestSkipped("{$tool} isn't installed.");
        }
        $dir = $this->tempDir();
        $projects = realpath(__DIR__ . '/../Fixtures/projects') ?: '';
        file_put_contents("{$dir}/phpunit.xml", strtr((string) file_get_contents("{$projects}/phpunit/phpunit.xml.in"), [
            '{{AUTOLOAD}}' => realpath(self::ROOT . '/vendor/autoload.php'),
            '{{TESTS}}' => "{$projects}/phpunit/tests",
            '{{PARAMETERS}}' => '<parameter name="binary" value="' . htmlspecialchars($this->binary) . '"/>',
        ]));
        $report = "{$dir}/report.jsonl";
        touch($report);
        $env = $this->childEnv(['FIXTURE_REPORT' => $report, 'FIXTURE_HOLD' => (string) $holdSeconds]);

        $process = proc_open(
            [PHP_BINARY, $script, '-c', "{$dir}/phpunit.xml", '--colors=never', ...$args],
            [0 => ['pipe', 'r'], 1 => ['file', "{$dir}/output.txt", 'a'], 2 => ['file', "{$dir}/output.txt", 'a']],
            $pipes,
            realpath(self::ROOT) ?: self::ROOT,
            $env,
        );
        self::assertIsResource($process);
        $this->processes[] = $process;

        // Waits for that many servers, not lines: a worker that finishes before
        // another has started could report twice from one server.
        $deadline = microtime(true) + 60;
        do {
            $endpoints = [];
            foreach (file($report, FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $seen = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
                self::assertSame(200, $seen['status'], 'the test reached its server');
                $endpoints[$seen['endpoint']] = true;
            }
            $endpoints = array_keys($endpoints);
            if (count($endpoints) >= $inside) {
                break;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        array_push($this->endpoints, ...$endpoints);
        self::assertGreaterThanOrEqual($inside, count($endpoints), "{$tool} didn't get {$inside} servers going:\n" . file_get_contents("{$dir}/output.txt"));

        return [$process, $endpoints, $report];
    }

    /** @param list<string> $endpoints */
    private static function assertGoneWithin(float $seconds, array $endpoints): void
    {
        $deadline = microtime(true) + $seconds;
        $left = $endpoints;
        while ($left !== [] && microtime(true) < $deadline) {
            $left = array_values(array_filter($left, self::answers(...)));
            if ($left !== []) {
                usleep(50_000);
            }
        }
        self::assertSame([], $left, "still answering after {$seconds} seconds");
    }

    /**
     * The processes listening on a loopback port: from /proc on Linux, which
     * slim images have without lsof, from lsof on macOS and from netstat on
     * Windows. Only for cleaning up after a failed test.
     *
     * @return list<int>
     */
    private static function listeningOn(int $port): array
    {
        if (PHP_OS_FAMILY === 'Linux') {
            return self::listeningOnFromProc($port);
        }
        if (PHP_OS_FAMILY === 'Windows') {
            preg_match_all('/^\s*TCP\s+127\.0\.0\.1:' . $port . '\s+\S+\s+LISTENING\s+(\d+)\s*$/mi', self::command(['netstat', '-ano', '-p', 'TCP']), $m);
        } else {
            preg_match_all('/^(\d+)$/m', self::command(['lsof', '-nP', '-t', "-iTCP@127.0.0.1:{$port}", '-sTCP:LISTEN']), $m);
        }

        return array_map('intval', $m[1]);
    }

    /**
     * /proc/net/tcp lists each listening socket (state 0A) with its port in
     * hex and its inode; the process holding the socket has a descriptor
     * linking to socket:[inode].
     *
     * @return list<int>
     */
    private static function listeningOnFromProc(int $port): array
    {
        $inodes = [];
        foreach (@file('/proc/net/tcp') ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            if (($fields[3] ?? '') === '0A' && str_ends_with($fields[1] ?? '', sprintf(':%04X', $port))) {
                $inodes["socket:[{$fields[9]}]"] = true;
            }
        }
        $pids = [];
        foreach ($inodes === [] ? [] : (glob('/proc/[0-9]*/fd/*') ?: []) as $fd) {
            if (isset($inodes[(string) @readlink($fd)])) {
                $pids[] = (int) explode('/', $fd)[2];
            }
        }

        return array_values(array_unique($pids));
    }

    /** What's known about a process, for a failure message. */
    private static function describe(int $pid): string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return (string) $pid;
        }
        $stat = (string) @file_get_contents("/proc/{$pid}/stat");
        $cmd = str_replace("\0", ' ', (string) @file_get_contents("/proc/{$pid}/cmdline"));
        $wchan = (string) @file_get_contents("/proc/{$pid}/wchan");

        return "{$pid} [{$cmd}] state " . (preg_match('/\) (\S) /', $stat, $m) === 1 ? $m[1] : '?') . " waiting in {$wchan}";
    }

    private static function isRunning(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return str_contains(self::command(['tasklist', '/FI', "PID eq {$pid}", '/NH']), " {$pid} ");
        }
        if (PHP_OS_FAMILY === 'Linux') {
            // Read rather than is_dir(), whose answer PHP caches. A zombie
            // has exited and only waits for its parent to collect it.
            $stat = @file_get_contents("/proc/{$pid}/stat");

            return $stat !== false && !str_contains($stat, ') Z ');
        }

        return self::command(['sh', '-c', 'kill -0 "$1" 2>/dev/null && echo yes', 'sh', (string) $pid]) === "yes\n";
    }

    /** @param list<string> $command */
    private static function command(array $command): string
    {
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if ($process === false) {
            return '';
        }
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $output;
    }

    private static function answers(string $endpoint): bool
    {
        $socket = @stream_socket_client(str_replace('http://', 'tcp://', $endpoint), $errno, $errstr, 0.5);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }
}
