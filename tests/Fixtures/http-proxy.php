<?php

declare(strict_types=1);

// A forward proxy for download tests: CONNECT tunnels, which is all the
// package sends a proxy. One connection at a time.
//
// Usage: php http-proxy.php <log.jsonl> [<user:pass> [<seconds>]]
//
// Prints "PORT <n>" once listening. With credentials (an empty string for
// none), any request without a matching Proxy-Authorization gets a 407. With
// seconds, the reply to CONNECT waits that long. Each request the proxy
// receives is logged as one JSON line: method, target, headers.

$logFile = $argv[1];
$credentials = ($argv[2] ?? '') === '' ? null : $argv[2];
$delay = (float) ($argv[3] ?? 0);

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}
$port = (int) substr((string) strrchr(stream_socket_get_name($server, false), ':'), 1);
fwrite(STDOUT, "PORT {$port}\n");
fflush(STDOUT);

while (true) {
    $client = @stream_socket_accept($server, 3600);
    if ($client !== false) {
        handle($client, $logFile, $credentials, $delay);
    }
}

/** @param resource $client */
function handle($client, string $logFile, ?string $credentials, float $delay): void
{
    stream_set_timeout($client, 5);
    $head = '';
    while (!str_contains($head, "\r\n\r\n")) {
        $line = fgets($client);
        if ($line === false) {
            fclose($client);

            return;
        }
        $head .= $line;
    }
    $lines = explode("\r\n", trim($head));
    [$method, $target] = explode(' ', array_shift($lines)) + [1 => ''];
    $headers = [];
    foreach ($lines as $line) {
        [$name, $value] = explode(':', $line, 2) + [1 => ''];
        $headers[strtolower(trim($name))] = trim($value);
    }
    file_put_contents($logFile, json_encode(['method' => $method, 'target' => $target, 'headers' => $headers]) . "\n", FILE_APPEND | LOCK_EX);

    if ($credentials !== null && ($headers['proxy-authorization'] ?? '') !== 'Basic ' . base64_encode($credentials)) {
        fwrite($client, "HTTP/1.1 407 Proxy Authentication Required\r\nProxy-Authenticate: Basic realm=\"test\"\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        fclose($client);

        return;
    }
    if ($method !== 'CONNECT') {
        fwrite($client, "HTTP/1.1 405 Method Not Allowed\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        fclose($client);

        return;
    }

    $upstream = @stream_socket_client('tcp://' . $target, $errno, $errstr, 5);
    if ($upstream === false) {
        fwrite($client, "HTTP/1.1 502 Bad Gateway\r\nContent-Length: 0\r\n\r\n");
        fclose($client);

        return;
    }
    usleep((int) ($delay * 1e6));
    fwrite($client, "HTTP/1.1 200 Connection established\r\n\r\n");
    relay($client, $upstream);
}

/**
 * @param resource $a
 * @param resource $b
 */
function relay($a, $b): void
{
    stream_set_blocking($a, false);
    stream_set_blocking($b, false);
    while (true) {
        $read = [$a, $b];
        $write = $except = null;
        if (@stream_select($read, $write, $except, 10) < 1) {
            break;
        }
        foreach ($read as $from) {
            $data = fread($from, 65536);
            if ($data === '' || $data === false) {
                if (feof($from)) {
                    fclose($a);
                    fclose($b);

                    return;
                }
                continue;
            }
            $to = $from === $a ? $b : $a;
            stream_set_blocking($to, true);
            fwrite($to, $data);
            stream_set_blocking($to, false);
        }
    }
    fclose($a);
    fclose($b);
}
