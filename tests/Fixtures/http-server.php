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
//    "stall": true, "stallAfter": 100, "truncate": 100, "rename": ["/from", "/to"]}
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

    if (!empty($route['stall'])) {
        sleep(30);
        fclose($conn);

        return;
    }

    $body = isset($route['file']) ? (string) file_get_contents($route['file']) : (string) ($route['body'] ?? '');
    $status = (int) ($route['status'] ?? 200);
    $out = "HTTP/1.1 {$status} X\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n";
    foreach ($route['headers'] ?? [] as $name => $value) {
        $out .= "{$name}: {$value}\r\n";
    }
    $out .= "\r\n";
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
    fclose($conn);
}
