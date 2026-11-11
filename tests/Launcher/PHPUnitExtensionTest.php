<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Launcher;

use Dynoxide\Process\ProcessTree;
use Dynoxide\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Runs the projects in tests/Fixtures/projects under PHPUnit, ParaTest and
 * Pest with the extension configured, and checks what their tests saw.
 *
 * The binary is DYNOXIDE_TEST_BINARY if it's set, or else the release this
 * package embeds, from the cache or a download when DYNOXIDE_TEST_DOWNLOAD=1.
 *
 * The Pest tests are in the pest group. Pest 3 and later need PHP 8.2, and
 * Pest 2, the last for PHP 8.1, installs only beside PHPUnit releases that
 * Composer blocks for a security advisory, so PHP 8.1 runs without them.
 */
#[Group('embedded')]
final class PHPUnitExtensionTest extends TestCase
{
    use TempDir;

    private const ROOT = __DIR__ . '/../..';

    private const PROJECTS = __DIR__ . '/../Fixtures/projects';

    /** @var array<string, string> */
    private array $binaryEnv = [];

    protected function setUp(): void
    {
        $binary = getenv('DYNOXIDE_TEST_BINARY');
        if ($binary !== false && $binary !== '') {
            $this->binaryEnv = ['DYNOXIDE_BINARY' => $binary];
        } elseif (getenv('DYNOXIDE_TEST_DOWNLOAD') === '1') {
            $this->binaryEnv = ['DYNOXIDE_BINARY' => ''];
        } else {
            self::markTestSkipped('Set DYNOXIDE_TEST_BINARY, or DYNOXIDE_TEST_DOWNLOAD=1 to use the embedded release.');
        }
    }

    public function testEveryTestReachesOneServerThatIsGoneAfterwards(): void
    {
        [$code, $output, $reports] = $this->runTool('phpunit', $this->config('phpunit'));

        self::assertSame(0, $code, $output);
        self::assertSame(['a', 'b', 'c', 'isolated'], self::labels($reports));
        $endpoints = array_unique(array_column($reports, 'endpoint'));
        self::assertCount(1, $endpoints, 'one server, which the isolated child process reaches too');
        self::assertMatchesRegularExpression('#^http://127\.0\.0\.1:\d+$#', $endpoints[0]);
        self::assertSame([200], array_values(array_unique(array_column($reports, 'status'))));
        self::assertSame(1, substr_count($output, ', listening on '), $output);
        self::assertAllStopped($endpoints);
    }

    public function testListingTestsStartsNoServer(): void
    {
        [$code, $output] = $this->runTool('phpunit', $this->config('phpunit'), ['--list-tests']);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('testReachesTheServer', $output);
        self::assertStringNotContainsString('Dynoxide', $output);
    }

    public function testParaTestGivesEachWorkerItsOwnServer(): void
    {
        [$code, $output, $reports] = $this->runTool('paratest', $this->config('phpunit'), ['--processes=3']);

        self::assertSame(0, $code, $output);
        self::assertSame(['a', 'b', 'c', 'isolated'], self::labels($reports));
        self::assertWorkersHaveTheirOwnServers($reports);
    }

    public function testParaTestWorkersEachGetTheirOwnDatabaseFile(): void
    {
        $config = $this->config('phpunit', '<parameter name="db-path" value="test.db"/>');
        [$code, $output, $reports] = $this->runTool('paratest', $config, ['--processes=3']);

        self::assertSame(0, $code, $output);
        $tokens = array_values(array_unique(array_column($reports, 'token')));
        sort($tokens);
        self::assertSame(['1', '2', '3'], $tokens);
        foreach ($tokens as $token) {
            self::assertFileExists(dirname($config) . "/test.db-{$token}");
        }
        self::assertFileDoesNotExist(dirname($config) . '/test.db', 'no worker uses the file a serial run would');
    }

    #[Group('pest')]
    public function testPest(): void
    {
        [$code, $output, $reports] = $this->runTool('pest', $this->config('pest'));

        self::assertSame(0, $code, $output);
        self::assertSame(['a', 'b', 'c'], self::labels($reports));
        self::assertCount(1, array_unique(array_column($reports, 'endpoint')));
        self::assertSame([200], array_values(array_unique(array_column($reports, 'status'))));
        self::assertAllStopped(array_unique(array_column($reports, 'endpoint')));
    }

    #[Group('pest')]
    public function testPestInParallel(): void
    {
        [$code, $output, $reports] = $this->runTool('pest', $this->config('pest'), ['--parallel', '--processes=3']);

        self::assertSame(0, $code, $output . "\n" . json_encode($reports, JSON_PRETTY_PRINT));
        self::assertSame(['a', 'b', 'c'], self::labels($reports));
        self::assertWorkersHaveTheirOwnServers($reports);
    }

    /**
     * A server that can't start fails the run with the reason, and the tests,
     * which still run, are pointed at a loopback port where nothing listens
     * rather than at whatever the environment named.
     */
    public function testABinaryThatIsNotThereFailsTheRunAndKeepsTestsOffAws(): void
    {
        $config = $this->config('phpunit', '<parameter name="binary" value="no-such-dynoxide"/>');
        [$code, $output, $reports] = $this->runTool('phpunit', $config, [], ['AWS_ENDPOINT_URL_DYNAMODB' => 'https://dynamodb.eu-west-2.amazonaws.com']);

        self::assertNotSame(0, $code);
        self::assertStringContainsString("Dynoxide didn't start", $output);
        self::assertStringContainsString('no-such-dynoxide', $output);
        self::assertSame(['a', 'b', 'c', 'isolated'], self::labels($reports));
        self::assertNowhere($reports);
    }

    /** A parameter that can't be read fails the run the same way. */
    public function testABadParameterFailsTheRunAndKeepsTestsOffAws(): void
    {
        $config = $this->config('phpunit', '<parameter name="timeout" value="soon"/>');
        [$code, $output, $reports] = $this->runTool('phpunit', $config, [], ['AWS_ENDPOINT_URL_DYNAMODB' => 'https://dynamodb.eu-west-2.amazonaws.com']);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('timeout parameter must be a number of seconds', $output);
        self::assertSame(['a', 'b', 'c', 'isolated'], self::labels($reports));
        self::assertNowhere($reports);
    }

    /** Without worker numbers ParaTest's workers would share one file, so they don't start. */
    public function testParaTestWithoutTestTokensWontShareADatabaseFile(): void
    {
        $config = $this->config('phpunit', '<parameter name="db-path" value="test.db"/>');
        [$code, $output, $reports] = $this->runTool('paratest', $config, ['--processes=2', '--no-test-tokens']);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('--no-test-tokens', $output);
        self::assertCount(4, $reports);
        self::assertNowhere($reports);
        self::assertSame([], glob(dirname($config) . '/test.db*'), 'no database file was opened');
    }

    /** ExecutionFinished still comes when a test fails, and stops the server. */
    public function testAFailingTestStillStopsTheServer(): void
    {
        [$code, $output, $reports] = $this->runTool('phpunit', $this->config('phpunit'), [], ['FIXTURE_FAIL' => '1']);

        self::assertSame(1, $code, $output);
        self::assertStringContainsString('failing on purpose', $output);
        self::assertSame([200], array_values(array_unique(array_column($reports, 'status'))));
        self::assertAllStopped(array_unique(array_column($reports, 'endpoint')));
    }

    /**
     * A worker hears that execution started once per test file; it tries to
     * start once. The binary here is a script that notes each time it's run
     * and then fails.
     */
    public function testAParaTestWorkerTriesToStartOnlyOnce(): void
    {
        $dir = $this->tempDir();
        $runs = $dir . DIRECTORY_SEPARATOR . 'runs.txt';
        if (PHP_OS_FAMILY === 'Windows') {
            $binary = $dir . DIRECTORY_SEPARATOR . 'counting.bat';
            file_put_contents($binary, "@echo run>>\"%COUNT_FILE%\"\r\n@exit /b 1\r\n");
        } else {
            $binary = $dir . DIRECTORY_SEPARATOR . 'counting';
            file_put_contents($binary, "#!/bin/sh\necho run >> \"\$COUNT_FILE\"\nexit 1\n");
            chmod($binary, 0755);
        }
        $config = $this->config('phpunit', '<parameter name="binary" value="' . htmlspecialchars($binary) . '"/>');
        [$code, $output, $reports] = $this->runTool('paratest', $config, ['--processes=1'], ['COUNT_FILE' => $runs]);

        self::assertNotSame(0, $code);
        self::assertStringContainsString("Dynoxide didn't start", $output);
        self::assertCount(4, $reports, 'four test files ran in the one worker');
        self::assertSame(1, count(file($runs, FILE_SKIP_EMPTY_LINES) ?: []), $output);
    }

    /** Writes a configuration for one of the fixture projects into a fresh directory. */
    private function config(string $project, string $parameters = ''): string
    {
        $dir = $this->tempDir() . DIRECTORY_SEPARATOR . 'config';
        @mkdir($dir);
        $config = $dir . DIRECTORY_SEPARATOR . 'phpunit.xml';
        file_put_contents($config, strtr((string) file_get_contents(self::PROJECTS . "/{$project}/phpunit.xml.in"), [
            '{{AUTOLOAD}}' => self::path(self::ROOT . '/vendor/autoload.php'),
            '{{TESTS}}' => self::path(self::PROJECTS . "/{$project}/tests"),
            '{{PARAMETERS}}' => $parameters,
        ]));

        return $config;
    }

    /**
     * Runs a tool from vendor/bin on a configuration, from the package root
     * (Pest names test classes after their path from there).
     *
     * @param list<string> $args
     * @param array<string, string> $env
     * @return array{int, string, list<array{label: string, pid: int, token: string, endpoint: string, status: int}>}
     */
    private function runTool(string $tool, string $config, array $args = [], array $env = []): array
    {
        $script = self::ROOT . "/vendor/bin/{$tool}";
        if (!is_file($script)) {
            self::markTestSkipped("{$tool} isn't installed.");
        }
        $report = $this->tempDir() . DIRECTORY_SEPARATOR . 'report-' . bin2hex(random_bytes(4)) . '.jsonl';
        touch($report);
        $output = $this->tempDir() . DIRECTORY_SEPARATOR . 'output-' . bin2hex(random_bytes(4)) . '.txt';

        $childEnv = getenv();
        foreach (array_keys($childEnv) as $name) {
            if (in_array(strtoupper((string) $name), ['PARATEST', 'TEST_TOKEN', 'UNIQUE_TEST_TOKEN', 'AWS_ENDPOINT_URL_DYNAMODB', 'DYNAMODB_ENDPOINT'], true)) {
                unset($childEnv[$name]);
            }
        }
        $childEnv = array_merge($childEnv, $this->binaryEnv, ['FIXTURE_REPORT' => $report], $env);

        $process = proc_open(
            [PHP_BINARY, $script, '-c', $config, '--colors=never', ...$args],
            // Both in append mode, so neither writes over the other.
            [0 => ['pipe', 'r'], 1 => ['file', $output, 'a'], 2 => ['file', $output, 'a']],
            $pipes,
            self::path(self::ROOT),
            $childEnv,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        // FIXTURE_DEADLINE shortens the wait, to check what a timeout leaves.
        $seconds = (float) (getenv('FIXTURE_DEADLINE') ?: 300);
        $deadline = microtime(true) + $seconds;
        while (($status = proc_get_status($process))['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        if ($status['running']) {
            // Under ParaTest and Pest --parallel this is the parent: its
            // workers, and their servers, are its children.
            if (PHP_OS_FAMILY === 'Windows') {
                ProcessTree::killOnWindows($status['pid']);
            } else {
                ProcessTree::signal(ProcessTree::of($status['pid']), 9);
            }
            proc_terminate($process, 9);
            self::fail("{$tool} was still running after {$seconds} seconds:\n" . file_get_contents($output));
        }
        proc_close($process);

        $reports = [];
        foreach (file($report, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $reports[] = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
        }

        return [$status['exitcode'], (string) file_get_contents($output), $reports];
    }

    /** @param list<array{label: string}> $reports */
    private static function labels(array $reports): array
    {
        $labels = array_column($reports, 'label');
        sort($labels);

        return $labels;
    }

    /** @param list<array{token: string, endpoint: string, status: int}> $reports */
    private static function assertWorkersHaveTheirOwnServers(array $reports): void
    {
        $byToken = [];
        foreach ($reports as $report) {
            self::assertNotSame('', $report['token'], 'ran in a worker');
            self::assertSame(200, $report['status'], json_encode($report) ?: '');
            $byToken[$report['token']][] = $report['endpoint'];
        }
        self::assertGreaterThanOrEqual(2, count($byToken), 'more than one worker ran tests');
        $endpoints = [];
        foreach ($byToken as $token => $seen) {
            self::assertCount(1, array_unique($seen), "worker {$token} used one server");
            $endpoints[] = $seen[0];
        }
        self::assertCount(count($byToken), array_unique($endpoints), 'and no two workers shared one');
        self::assertAllStopped($endpoints);
    }

    /** @param list<array{endpoint: string, status: int}> $reports */
    private static function assertNowhere(array $reports): void
    {
        foreach ($reports as $report) {
            self::assertSame('http://127.0.0.1:1', $report['endpoint']);
            self::assertSame(0, $report['status'], 'nothing answered');
        }
    }

    /** @param iterable<string> $endpoints */
    private static function assertAllStopped(iterable $endpoints): void
    {
        foreach ($endpoints as $endpoint) {
            $socket = @stream_socket_client(str_replace('http://', 'tcp://', $endpoint), $errno, $errstr, 1.0);
            self::assertFalse($socket, "{$endpoint} is still answering after the run");
        }
    }

    private static function path(string $path): string
    {
        return realpath($path) ?: $path;
    }
}
