<?php

// Starts a real Dynoxide server, prints a JSON line about it, and waits to be
// killed. Usage: hold-server.php <mode> <binary> <directory> [<db path>]
// The line always has the endpoint, and the server's process ID so a test
// that fails can still clean up.
//   package     through Dynoxide::start(), as a test suite would
//   no-flag     spawned by hand without --exit-with-parent, which a killed
//               parent can't stop
//   stdin-file  spawned by hand with --exit-with-parent and stdin on a file,
//               which stops the server as soon as it has started; the line
//               comes once it has exited, with its exit code

declare(strict_types=1);

use Dynoxide\Dynoxide;
use Dynoxide\Options;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $mode, $binary, $dir] = $argv;
$dbPath = $argv[4] ?? null;

if ($mode === 'package') {
    $server = Dynoxide::start(new Options(binary: $binary, dbPath: $dbPath, log: static function (): void {
    }));
    // Server keeps its ServerProcess private, and that keeps the handle.
    $process = (fn () => $this->process)->call($server);
    $pid = (fn (): int => proc_get_status($this->process)['pid'])->call($process);
    echo json_encode(['endpoint' => $server->endpoint, 'pid' => $pid]), "\n";
} else {
    $args = [$binary, 'serve', '--port', '0', '--ready-json'];
    $stdin = ['pipe', 'r'];
    if ($mode === 'stdin-file') {
        $args[] = '--exit-with-parent';
        touch("{$dir}/empty-stdin");
        $stdin = ['file', "{$dir}/empty-stdin", 'r'];
    }
    // stderr to a file, so nothing the server writes can fail once this
    // process has gone.
    $process = proc_open($args, [0 => $stdin, 1 => ['pipe', 'w'], 2 => ['file', "{$dir}/server-stderr.txt", 'a']], $pipes);
    if ($process === false) {
        exit(70);
    }
    $ready = json_decode((string) fgets($pipes[1]), true, 4, JSON_THROW_ON_ERROR);
    $line = ['endpoint' => "http://127.0.0.1:{$ready['port']}", 'pid' => proc_get_status($process)['pid']];
    if ($mode === 'stdin-file') {
        $deadline = microtime(true) + 10;
        while (($status = proc_get_status($process))['running'] && microtime(true) < $deadline) {
            usleep(20_000);
        }
        $line['exited'] = $status['running'] ? null : $status['exitcode'];
    }
    echo json_encode($line), "\n";
}

// Waits for the test to kill this process.
sleep(600);
exit(0);
