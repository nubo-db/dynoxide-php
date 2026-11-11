<?php

// Starts a fake server in a process of its own, for what a test can't do from
// inside PHPUnit. Usage: launcher-script.php <mode> <marker file>
//   exit-without-stop  print the output directory, then exit without stopping
//   shutdown-exit      the same, with a 1.6.x-style server, after an earlier
//                      shutdown function calls exit() as PHPUnit's does after
//                      a test calls exit(); prints the directory and the port
//   fork               fork; the child calls stop() and exits normally; then
//                      report whether the server is still running
//   fatal              like shutdown-exit, then dies of a fatal error (out of
//                      memory), so neither shutdown functions nor destructors
//                      stop the server; prints the directory and the port first
//   fatal-wrapped      the same, with the server behind a wrapper that runs it
//                      as a child
// LAUNCHER_TEMP_ROOT, when set, is where the output directory goes.

declare(strict_types=1);

use Dynoxide\Options;
use Dynoxide\Tests\Support\FakeServer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $mode, $marker] = $argv;
$fatal = $mode === 'fatal' || $mode === 'fatal-wrapped';
if ($mode === 'shutdown-exit' || $fatal) {
    register_shutdown_function(static function (): void {
        exit(2);
    });
}
$legacy = $mode === 'shutdown-exit' || $fatal;
$launcher = FakeServer::launcher(
    ['FAKE_MODE' => $legacy ? 'legacy' : 'ready', 'FAKE_MARKER' => $marker],
    tempRoot: getenv('LAUNCHER_TEMP_ROOT') ?: null,
);
$binary = $mode === 'fatal-wrapped'
    ? FakeServer::binary(false, dirname(__DIR__) . '/Fixtures/forking-wrapper.php')
    : FakeServer::binary(!$legacy);
$server = $launcher->start($binary, new Options(log: static function (): void {
}));
$dir = (function (): string {
    return $this->process->outputDir;
})->call($server);

if ($mode === 'exit-without-stop') {
    echo $dir, "\n";
    exit(0);
}

if ($mode === 'shutdown-exit') {
    echo $dir, "\n", $server->port, "\n";
    exit(0);
}

if ($fatal) {
    echo $dir, "\n", $server->port, "\n";
    ini_set('memory_limit', '32M');
    $hog = [];
    while (true) {
        $hog[] = str_repeat('x', 1 << 20);
    }
}

if ($mode === 'fork') {
    $child = pcntl_fork();
    if ($child === 0) {
        // stop() does nothing here, and the exit runs the shutdown function
        // and destructors as any normal exit would.
        echo json_encode(['child' => [$server->isRunning(), $server->stop()]]), "\n";
        exit(0);
    }
    pcntl_waitpid($child, $status);
    usleep(300_000);
    // Without the guard the child can't signal the server, but it would
    // close its stdin copy and delete the server's output directory.
    echo $server->isRunning() && !is_file($marker) && is_dir($dir) ? "still running\n" : "stopped\n";
    $server->stop();
    exit(0);
}

fwrite(STDERR, "unknown mode {$mode}\n");
exit(64);
