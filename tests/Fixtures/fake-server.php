<?php

// A stand-in for `dynoxide serve`, run through PHP so it works on every
// platform. FAKE_MODE picks the behaviour. FAKE_RECORD, if set, is a file that
// gets the arguments and the names of any variables that are
// DYNOXIDE_ENCRYPTION_KEY in some case. FAKE_MARKER, if set, is written when
// stdin reaches end-of-file.

declare(strict_types=1);

$mode = getenv('FAKE_MODE') ?: 'ready';
$record = getenv('FAKE_RECORD');
if ($record !== false && $record !== '') {
    file_put_contents($record, json_encode([
        'args' => array_slice($argv, 1),
        'encryptionKeys' => array_values(array_filter(
            array_keys(getenv()),
            static fn ($name): bool => strcasecmp((string) $name, 'DYNOXIDE_ENCRYPTION_KEY') === 0,
        )),
    ]));
}

$ready = static function (int $port = 54321, string $host = '127.0.0.1'): void {
    // Field order differs from the engine's, and there's a field the package doesn't know.
    fwrite(STDOUT, json_encode([
        'port' => $port, 'something_new' => true, 'event' => 'ready', 'schema' => 1, 'version' => '1.7.0',
        'host' => $host, 'endpoint' => "http://{$host}:{$port}", 'pid' => getmypid(),
    ]) . "\n");
};

$untilEndOfInput = static function (): never {
    while (fgets(STDIN) !== false) {
    }
    $marker = getenv('FAKE_MARKER');
    if ($marker !== false && $marker !== '') {
        file_put_contents($marker, 'stdin closed');
    }
    exit(0);
};

switch ($mode) {
    case 'ready':
        fwrite(STDERR, "Dynoxide listening on http://127.0.0.1:54321\n");
        $ready();
        $untilEndOfInput();

    case 'silent':
        fwrite(STDERR, "still warming up\n");
        $untilEndOfInput();

    case 'exit':
        fwrite(STDERR, "boom: the database is locked\n");
        exit((int) (getenv('FAKE_EXIT_CODE') ?: 3));

    case 'garbage-noisy':
        fwrite(STDERR, "error: unexpected argument\n");
        fwrite(STDOUT, "Dynoxide is starting\n");
        $untilEndOfInput();

    // An older binary reporting a port that can't be right.
    case 'legacy-badport':
        fwrite(STDERR, "Dynoxide listening on http://127.0.0.1:99999\n");
        $untilEndOfInput();

    case 'garbage':
        fwrite(STDOUT, "Dynoxide is starting\n");
        $untilEndOfInput();

    case 'port0':
        $ready(0);
        $untilEndOfInput();

    case 'wide':
        $ready(54321, '0.0.0.0');
        $untilEndOfInput();

    // Ignores stdin, so only a signal or a kill stops it.
    case 'deaf':
        $ready();
        while (true) {
            sleep(1);
        }

    // A binary from before --ready-json: says where it's listening on stderr
    // and answers GET /.
    // legacy-split writes its listening line in two parts, breaking it inside
    // the port number.
    case 'legacy':
    case 'legacy-mute':
    case 'legacy-split':
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($server, false), strrpos((string) stream_socket_get_name($server, false), ':') + 1);
        if ($mode === 'legacy-split') {
            fwrite(STDERR, 'Dynoxide listening on http://127.0.0.1:' . substr((string) $port, 0, 1));
            usleep(500_000);
            fwrite(STDERR, substr((string) $port, 1) . "\n");
        } else {
            fwrite(STDERR, "Dynoxide listening on http://127.0.0.1:{$port}\n");
        }
        while (true) {
            $client = @stream_socket_accept($server, 60);
            if ($client === false) {
                continue;
            }
            fgets($client);
            fwrite($client, $mode !== 'legacy-mute' ? "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n" : "HTTP/1.1 503 Busy\r\nContent-Length: 0\r\n\r\n");
            fclose($client);
        }

    default:
        fwrite(STDERR, "unknown FAKE_MODE {$mode}\n");
        exit(64);
}
