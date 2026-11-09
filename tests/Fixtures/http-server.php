<?php

declare(strict_types=1);

// A small HTTP or HTTPS server for download tests. It serves one connection at
// a time, which is all the downloader needs.
//
// Usage: php http-server.php <routes.json> <log.jsonl> [<tls-cert-and-key.pem>]
//
// Prints "PORT <n>" once listening. routes.json is read for every request, so a
// test can change it while the server runs. Each route is keyed by path:
//   {"status": 200, "file": "/path", "body": "text", "headers": {"Location": "..."},
//    "stall": true, "stallAfter": 100, "truncate": 100, "rename": ["/from", "/to"],
//    "drip": "head" | "body", "chunked": true, "continue": true, "keepOpen": true,
//    "raw": "bytes"}
// "drip" sends a header line or a body byte every 0.2 seconds, so no single read
// ever waits long. "chunked" sends the body in chunks of 1000 bytes; with
// "truncate" it stops part-way through. "continue" sends a 100 response first.
// "keepOpen" holds the connection open after the response. "raw" sends exactly
// the bytes given, as the whole response.
// Each request is appended to the log as one JSON line: method, target, headers.

[$_, $routesFile, $logFile] = $argv;
$cert = $argv[3] ?? null;

$context = stream_context_create($cert === null ? [] : ['ssl' => [
    'local_cert' => $cert,
    'verify_peer' => false,
]]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}
$port = (int) substr((string) strrchr(stream_socket_get_name($server, false), ':'), 1);
fwrite(STDOUT, "PORT {$port}\n");
fflush(STDOUT);

while (true) {
    $conn = @stream_socket_accept($server, 3600);
    if ($conn === false) {
        continue;
    }
    if ($cert !== null) {
        stream_set_blocking($conn, true);
        if (@stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
            fclose($conn);
            continue;
        }
    }
    serve($conn, $routesFile, $logFile);
}

/** @param resource $conn */
function serve($conn, string $routesFile, string $logFile): void
{
    stream_set_timeout($conn, 5);
    $head = '';
    while (!str_contains($head, "\r\n\r\n")) {
        $line = fgets($conn);
        if ($line === false) {
            fclose($conn);

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

    $routes = json_decode((string) file_get_contents($routesFile), true) ?: [];
    $path = parse_url($target, PHP_URL_PATH) ?: $target;
    $route = $routes[$path] ?? ['status' => 404, 'body' => 'not found'];

    // Lets a test make something happen mid-download, such as another
    // process finishing its install.
    if (isset($route['rename']) && file_exists($route['rename'][0])) {
        rename($route['rename'][0], $route['rename'][1]);
    }

    if (isset($route['raw'])) {
        fwrite($conn, (string) $route['raw']);
        fclose($conn);

        return;
    }

    if (!empty($route['stall'])) {
        sleep(30);
        fclose($conn);

        return;
    }

    $body = isset($route['file']) ? (string) file_get_contents($route['file']) : (string) ($route['body'] ?? '');
    $status = (int) ($route['status'] ?? 200);
    if (!empty($route['continue'])) {
        fwrite($conn, "HTTP/1.1 100 Continue\r\n\r\n");
    }

    if (($route['drip'] ?? null) === 'head') {
        fwrite($conn, "HTTP/1.1 200 X\r\n");
        for ($i = 0; $i < 150 && @fwrite($conn, "X-Drip-{$i}: x\r\n") !== false; $i++) {
            usleep(200_000);
        }
        fclose($conn);

        return;
    }

    if (!empty($route['chunked'])) {
        $out = "HTTP/1.1 {$status} X\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n";
        foreach (str_split($body, 1000) as $piece) {
            $out .= dechex(strlen($piece)) . "; ext=1\r\n{$piece}\r\n";
        }
        $out .= "0\r\n\r\n";
        fwrite($conn, isset($route['truncate']) ? substr($out, 0, (int) $route['truncate']) : $out);
        fclose($conn);

        return;
    }

    $out = "HTTP/1.1 {$status} X\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n";
    foreach ($route['headers'] ?? [] as $name => $value) {
        $out .= "{$name}: {$value}\r\n";
    }
    $out .= "\r\n";

    if (($route['drip'] ?? null) === 'body') {
        fwrite($conn, $out);
        for ($i = 0; $i < strlen($body) && @fwrite($conn, $body[$i]) !== false; $i++) {
            usleep(200_000);
        }
        fclose($conn);

        return;
    }
    if (isset($route['truncate'])) {
        $body = substr($body, 0, (int) $route['truncate']);
    }
    if (isset($route['stallAfter'])) {
        // Headers and the start of the body, then nothing.
        fwrite($conn, $out . substr($body, 0, (int) $route['stallAfter']));
        sleep(30);
        fclose($conn);

        return;
    }
    fwrite($conn, $out . $body);
    if (!empty($route['keepOpen'])) {
        sleep(30);
    }
    fclose($conn);
}
