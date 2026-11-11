<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit\Process;

use Dynoxide\Exception\StartException;
use Dynoxide\Options;
use Dynoxide\Process\Registry;
use Dynoxide\Server;
use Dynoxide\Tests\Support\FakeServer;
use Dynoxide\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class LauncherTest extends TestCase
{
    use TempDir;

    /** @var list<Server> */
    private array $servers = [];

    /** @var list<string> */
    private array $logged = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
    }

    /** @param array<string, string> $env */
    private function start(array $env = [], float $grace = 5.0, ?Options $options = null, bool $flags = true): Server
    {
        $options ??= $this->options();
        $server = FakeServer::launcher($env, $grace)->start(FakeServer::binary($flags), $options);
        $this->servers[] = $server;

        return $server;
    }

    private function options(float $timeout = 10.0, ?string $dbPath = null, ?string $schema = null): Options
    {
        return new Options(dbPath: $dbPath, schema: $schema, startTimeout: $timeout, log: function (string $line): void {
            $this->logged[] = $line;
        });
    }

    /**
     * Starts a fake that must fail, and checks the failure left nothing
     * behind: no registered server and no output directory.
     *
     * @param array<string, string> $env
     */
    private function assertStartFails(array $env, string $pattern, bool $flags = true, float $timeout = 10.0, ?Options $options = null): StartException
    {
        $before = Registry::count();
        $root = $this->tempDir() . '/output-' . bin2hex(random_bytes(4));
        mkdir($root);
        try {
            FakeServer::launcher($env, 1.0, $root)->start(FakeServer::binary($flags), $options ?? $this->options(timeout: $timeout));
            self::fail('expected the start to fail');
        } catch (StartException $e) {
            self::assertMatchesRegularExpression($pattern, $e->getMessage());
        }
        self::assertSame($before, Registry::count(), 'the server that failed to start is let go');
        self::assertSame([], self::listTree($root), 'and stopped, which clears its output away');

        return $e;
    }

    private function outputDir(Server $server): string
    {
        return (function (): string {
            return $this->process->outputDir;
        })->call($server);
    }

    /** @return array{args: list<string>, encryptionKeys: list<string>} */
    private function recorded(string $file): array
    {
        return json_decode((string) file_get_contents($file), true);
    }

    public function testStartsAndReturnsWhereItListens(): void
    {
        $before = Registry::count();
        $server = $this->start();

        self::assertSame(
            ['http://127.0.0.1:54321', '127.0.0.1', 54321, 'us-east-1', 'dynoxide', 'dynoxide'],
            [$server->endpoint, $server->host, $server->port, $server->region, $server->accessKeyId, $server->secretAccessKey],
        );
        self::assertTrue($server->isRunning());
        self::assertSame($before + 1, Registry::count());
        self::assertCount(1, $this->logged);
        self::assertStringContainsString('from the binary option (not checksum-verified), listening on http://127.0.0.1:54321', $this->logged[0]);
    }

    public function testStopsByClosingStdin(): void
    {
        $marker = $this->tempDir() . '/stopped';
        $before = Registry::count();
        $server = $this->start(['FAKE_MARKER' => $marker]);
        $dir = $this->outputDir($server);

        $started = microtime(true);
        self::assertSame(0, $server->stop());

        self::assertLessThan(2.5, microtime(true) - $started, 'well within the 5 second grace period');
        self::assertFileExists($marker);
        self::assertFalse($server->isRunning());
        self::assertDirectoryDoesNotExist($dir);
        self::assertSame($before, Registry::count());
        self::assertSame(0, $server->stop(), 'a second stop is harmless');
    }

    public function testPassesTheContractsArgumentsAndNoHost(): void
    {
        $record = $this->tempDir() . '/record.json';
        $this->start(['FAKE_RECORD' => $record], options: $this->options(dbPath: '/data/a.db', schema: '/data/schema.json'));

        self::assertSame(
            ['serve', '--port', '0', '--ready-json', '--exit-with-parent', '--db-path=/data/a.db', '--schema=/data/schema.json'],
            $this->recorded($record)['args'],
        );
    }

    public function testDoesNotPassOnTheEncryptionKey(): void
    {
        $record = $this->tempDir() . '/record.json';
        $this->start(['FAKE_RECORD' => $record, 'DYNOXIDE_ENCRYPTION_KEY' => 'secret']);

        self::assertSame([], $this->recorded($record)['encryptionKeys']);
    }

    /** Windows reads variable names in any case, so the key goes in any case there. */
    public function testDropsTheEncryptionKeyInAnyCaseOnWindowsOnly(): void
    {
        $record = $this->tempDir() . '/windows.json';
        $options = $this->options();
        $this->servers[] = FakeServer::launcher(['FAKE_RECORD' => $record, 'dynoxide_Encryption_Key' => 'secret'], osFamily: 'Windows')
            ->start(FakeServer::binary(), $options);
        self::assertSame([], $this->recorded($record)['encryptionKeys']);

        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }
        $record = $this->tempDir() . '/linux.json';
        $this->servers[] = FakeServer::launcher(['FAKE_RECORD' => $record, 'dynoxide_Encryption_Key' => 'secret'], osFamily: 'Linux')
            ->start(FakeServer::binary(), $options);
        self::assertSame(['dynoxide_Encryption_Key'], $this->recorded($record)['encryptionKeys'], 'another name elsewhere');
    }

    /**
     * PHP doesn't hand one server's stdin pipe to a server started after it.
     * If it did, closing A's stdin wouldn't reach A while B held a copy, and
     * stopping A would wait out the grace period and end in a signal.
     */
    public function testStoppingOneServerDoesNotWaitOnAnother(): void
    {
        $a = $this->start();
        $b = $this->start();

        $started = microtime(true);
        self::assertSame(0, $a->stop());

        self::assertLessThan(2.5, microtime(true) - $started, 'well within the 5 second grace period');
        self::assertTrue($b->isRunning());
    }

    public function testFailsWhenNoReadyLineArrivesInTime(): void
    {
        $started = microtime(true);
        $this->assertStartFails(['FAKE_MODE' => 'silent'], '/didn\'t print its ready line within 0\.5 seconds\. Its last stderr lines:\nstill warming up$/', timeout: 0.5);
        self::assertLessThan(4.0, microtime(true) - $started);
    }

    public function testFailsWhenItExitsBeforeItIsReady(): void
    {
        $this->assertStartFails(['FAKE_MODE' => 'exit'], '/exited with status 3 before it was ready\. Its last stderr lines:\nboom: the database is locked$/');
    }

    public function testSaysWhenTheBinaryCouldNotBeExecuted(): void
    {
        $this->assertStartFails(['FAKE_MODE' => 'exit', 'FAKE_EXIT_CODE' => '127'], '/exited with status 127, which usually means it couldn\'t be executed/');
    }

    #[Group('unix')]
    public function testSaysWhenItWasKilledBeforeItWasReady(): void
    {
        $script = $this->tempDir() . '/killed';
        file_put_contents($script, "#!/bin/sh\nkill -9 \$\$\n");
        chmod($script, 0755);
        $before = Registry::count();

        $this->expectException(StartException::class);
        $this->expectExceptionMessage('was killed by signal 9 before it was ready');
        try {
            FakeServer::direct(FakeServer::launcher())->start(FakeServer::binary(path: $script), $this->options());
        } finally {
            self::assertSame($before, Registry::count());
        }
    }

    public function testFailsClearlyWhenTheBinaryIsNotThere(): void
    {
        // PHP 8.3 and later refuse in proc_open; 8.1 and 8.2 start a child that exits 127.
        $before = Registry::count();
        $root = $this->tempDir() . '/output';
        mkdir($root);
        try {
            FakeServer::direct(FakeServer::launcher(tempRoot: $root))->start(FakeServer::binary(path: $root . '/no-such-binary'), $this->options());
            self::fail('expected the start to fail');
        } catch (StartException $e) {
            self::assertMatchesRegularExpression("/Couldn't start .*no-such-binary|status 127/", $e->getMessage());
        }
        self::assertSame($before, Registry::count());
        self::assertSame([], self::listTree($root));
    }

    public function testFailsOnAFirstLineThatIsNotJsonNamingTheBinaryAndItsStderr(): void
    {
        $this->assertStartFails(
            ['FAKE_MODE' => 'garbage-noisy'],
            '/^The Dynoxide server \(Dynoxide 1\.7\.0 at .*fake-server\.php, from the binary option \(not checksum-verified\)\) '
            . 'printed a ready line that isn\'t JSON: "Dynoxide is starting"\. Its last stderr lines:\nerror: unexpected argument$/',
        );
    }

    public function testFailsOnPortZero(): void
    {
        $this->assertStartFails(['FAKE_MODE' => 'port0'], "/reported a port that can't be used/");
    }

    /** Nothing has the server until start() returns, so a log callback that throws mustn't strand it. */
    public function testStopsTheServerWhenTheLogCallbackThrows(): void
    {
        $marker = $this->tempDir() . '/stopped';
        $before = Registry::count();
        $root = $this->tempDir() . '/output';
        mkdir($root);
        $options = new Options(log: static function (): void {
            throw new \RuntimeException('the log is full');
        });

        try {
            FakeServer::launcher(['FAKE_MARKER' => $marker], tempRoot: $root)->start(FakeServer::binary(), $options);
            self::fail('expected the log callback\'s exception');
        } catch (\RuntimeException $e) {
            self::assertSame('the log is full', $e->getMessage());
        }
        self::assertFileExists($marker);
        self::assertSame($before, Registry::count());
        self::assertSame([], self::listTree($root));
    }

    public function testWarnsWhenTheServerIsNotOnLoopback(): void
    {
        $this->start(['FAKE_MODE' => 'wide']);

        self::assertCount(2, $this->logged);
        self::assertStringContainsString("the server bound 0.0.0.0, which isn't a loopback address", $this->logged[1]);
    }

    /** One that ignores stdin is stopped by SIGTERM, or killed on Windows, after the grace period. */
    public function testStopsAServerThatIgnoresStdin(): void
    {
        $server = $this->start(['FAKE_MODE' => 'deaf'], grace: 0.3);

        $started = microtime(true);
        $server->stop();
        $took = microtime(true) - $started;

        self::assertFalse($server->isRunning());
        self::assertGreaterThanOrEqual(0.3, $took);
        self::assertLessThan(3.0, $took);
    }

    #[Group('unix')]
    public function testKillsAServerThatIgnoresStdinAndSigterm(): void
    {
        $script = $this->tempDir() . '/stubborn';
        file_put_contents($script, "#!/bin/sh\ntrap '' TERM\n"
            . "echo '{\"event\":\"ready\",\"endpoint\":\"http://127.0.0.1:54321\",\"host\":\"127.0.0.1\",\"port\":54321}'\n"
            . "while :; do sleep 0.1; done\n");
        chmod($script, 0755);
        $server = FakeServer::direct(FakeServer::launcher([], 0.3))->start(FakeServer::binary(path: $script), $this->options());
        $this->servers[] = $server;

        $started = microtime(true);
        self::assertNull($server->stop(), 'killed by a signal');

        self::assertGreaterThanOrEqual(0.6, microtime(true) - $started, 'both grace periods passed first');
        self::assertFalse($server->isRunning());
    }

    public function testLosingTheServerObjectDoesNotStopTheServer(): void
    {
        $marker = $this->tempDir() . '/stopped';
        $before = Registry::count();
        FakeServer::launcher(['FAKE_MARKER' => $marker])->start(FakeServer::binary(), $this->options());
        gc_collect_cycles();
        usleep(300_000);

        self::assertFileDoesNotExist($marker);
        self::assertSame($before + 1, Registry::count());

        Registry::stopAll();
        self::assertFileExists($marker);
        self::assertSame(0, Registry::count());
    }

    /**
     * An earlier shutdown function that calls exit() stops PHP running the
     * rest, the Registry's included. Destructors still run, so the server is
     * stopped and its output cleared even so. The fake is a 1.6.x-style
     * server, which nothing else would stop.
     */
    public function testAServerIsStoppedEvenWhenAnotherShutdownFunctionExits(): void
    {
        $marker = $this->tempDir() . '/stopped';
        exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, __DIR__ . '/../../Fixtures/launcher-script.php', 'shutdown-exit', $marker])), $output, $code);

        self::assertSame(2, $code, 'the other shutdown function ran');
        self::assertCount(2, $output);
        self::assertDirectoryDoesNotExist($output[0]);
        $socket = @stream_socket_client('tcp://127.0.0.1:' . (int) $output[1], $errno, $errstr, 1.0);
        self::assertFalse($socket, 'nothing is listening on its port');
    }

    /**
     * A fatal error runs no destructors, and an earlier shutdown function that
     * exits stops the rest, so a 1.6.x-style server outlives PHP, and its
     * output directory stays. The next start clears both up.
     */
    #[Group('unix')]
    #[TestWith(['fatal'])]
    #[TestWith(['fatal-wrapped'])]
    public function testTheNextStartClearsUpAfterAFatalError(string $mode): void
    {
        $root = $this->tempDir() . '/root';
        mkdir($root);
        $marker = $this->tempDir() . '/stopped';
        $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/../../Fixtures/launcher-script.php', $mode, $marker]));
        exec('LAUNCHER_TEMP_ROOT=' . escapeshellarg($root) . ' ' . $command . ' 2>/dev/null', $output, $code);

        self::assertSame(2, $code, 'the other shutdown function ran');
        self::assertCount(2, $output);
        [$dir, $port] = [$output[0], (int) $output[1]];
        self::assertDirectoryExists($dir, 'the fatal error left the output directory');
        $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 1.0);
        self::assertNotFalse($socket, 'the fatal error left the server running');
        fclose($socket);

        $next = FakeServer::launcher(tempRoot: $root)->start(FakeServer::binary(), new Options(log: static function (): void {
        }));
        try {
            self::assertDirectoryDoesNotExist($dir);
            self::assertFalse(@stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 1.0), 'nothing is listening on its port');
        } finally {
            $next->stop();
        }
    }

    /** A start leaves alone a server whose owner is still running, as with parallel workers. */
    public function testAStartLeavesAnotherLiveServerAlone(): void
    {
        $root = $this->tempDir() . '/root';
        mkdir($root);
        $silent = new Options(log: static function (): void {
        });
        $first = FakeServer::launcher(['FAKE_MODE' => 'legacy'], tempRoot: $root)->start(FakeServer::binary(false), $silent);
        try {
            $dir = (fn (): string => $this->process->outputDir)->call($first);
            $second = FakeServer::launcher(tempRoot: $root)->start(FakeServer::binary(), $silent);
            $second->stop();

            self::assertDirectoryExists($dir);
            self::assertTrue($first->isRunning());
        } finally {
            $first->stop();
        }
    }

    /** The shutdown function stops a server nobody stopped, and clears up its output. */
    public function testAServerLeftRunningIsStoppedWhenPhpExits(): void
    {
        $marker = $this->tempDir() . '/stopped';
        exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, __DIR__ . '/../../Fixtures/launcher-script.php', 'exit-without-stop', $marker])), $output, $code);

        self::assertSame(0, $code);
        self::assertDirectoryDoesNotExist(trim($output[0] ?? ''));
        self::assertNotSame('', trim($output[0] ?? ''));
        self::assertFileExists($marker);
    }

    #[Group('unix')]
    #[RequiresPhpExtension('pcntl')]
    public function testAForkedChildExitingDoesNotStopTheServer(): void
    {
        $marker = $this->tempDir() . '/stopped';
        exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, __DIR__ . '/../../Fixtures/launcher-script.php', 'fork', $marker])), $output, $code);

        self::assertSame([0, ['{"child":[true,null]}', 'still running']], [$code, $output]);
    }

    #[Group('unix')]
    public function testTheOutputDirectoryIsPrivate(): void
    {
        $server = $this->start();

        self::assertSame(0700, fileperms($this->outputDir($server)) & 0777);
    }

    #[Group('unix')]
    public function testFailsClearlyWhenTheOutputDirectoryCannotBeMade(): void
    {
        $root = $this->tempDir() . '/readonly';
        mkdir($root, 0500);

        $this->expectException(StartException::class);
        $this->expectExceptionMessage("for the server's output");

        FakeServer::launcher(tempRoot: $root)->start(FakeServer::binary(), $this->options());
    }

    public function testStartsABinaryOlderThanTheLauncherFlags(): void
    {
        $record = $this->tempDir() . '/record.json';
        $server = $this->start(['FAKE_MODE' => 'legacy', 'FAKE_RECORD' => $record], grace: 4.0, flags: false);

        self::assertSame(['serve', '--port', '0'], $this->recorded($record)['args']);
        self::assertMatchesRegularExpression('#^http://127\.0\.0\.1:\d+$#', $server->endpoint);
        self::assertNotSame(54321, $server->port);
        self::assertStringContainsString('started without the launcher flags it predates', $this->logged[0]);

        // Stopped by SIGTERM, or killed on Windows, without waiting on stdin.
        $started = microtime(true);
        $server->stop();
        self::assertLessThan(3.0, microtime(true) - $started, 'within the 4 second grace period');
        self::assertFalse($server->isRunning());
    }

    public function testWaitsForTheWholeListeningLine(): void
    {
        $server = $this->start(['FAKE_MODE' => 'legacy-split'], grace: 1.0, flags: false);

        $socket = @stream_socket_client("tcp://127.0.0.1:{$server->port}", $errno, $errstr, 1.0);
        self::assertNotFalse($socket, "port {$server->port} is the one it listens on");
        fclose($socket);
    }

    public function testAnOlderBinaryReportingAnUnusablePortFails(): void
    {
        $this->assertStartFails(['FAKE_MODE' => 'legacy-badport'], "/reported a port that can't be used: 99999/", flags: false);
    }

    public function testAnOlderBinaryMustAnswerGetBeforeItCounts(): void
    {
        $this->assertStartFails(['FAKE_MODE' => 'legacy-mute'], '#didn\'t answer GET http://127\.0\.0\.1:\d+/ within 1 seconds#', flags: false, timeout: 1.0);
    }

    public function testAnOlderBinaryThatNeverSaysItIsListeningTimesOut(): void
    {
        $this->assertStartFails(['FAKE_MODE' => 'silent'], "/didn't say it was listening within 0.5 seconds/", flags: false, timeout: 0.5);
    }
}
