<?php

declare(strict_types=1);

namespace Dynoxide\Download;

use Dynoxide\Exception\CacheException;
use Dynoxide\Exception\DownloadException;

/**
 * Fetches release files over plain sockets, so no curl extension is needed
 * and every step, from connecting to the last byte, counts against one
 * deadline. PHP's http:// stream wrapper only times out each read, so a server
 * that sends its headers a byte at a time could hold it for ever.
 *
 * Redirects are followed one hop at a time. Each hop gets the URL rules, the
 * proxy and no_proxy afresh, and may not go from https to http.
 */
final class Downloader
{
    private const USER_AGENT = 'dynoxide-php';

    /** Whether an extension is loaded; replaceable so tests can take one away. */
    private \Closure $extensionLoaded;

    /**
     * @param array<string, string> $env proxy settings are read from here
     * @param string|null $caFile a CA bundle to trust instead of the system's (for tests)
     */
    public function __construct(
        private readonly array $env,
        private readonly float $deadlineSeconds = 300.0,
        private readonly float $readTimeoutSeconds = 30.0,
        private readonly int $maxRedirects = 5,
        private readonly ?string $caFile = null,
    ) {
        $this->extensionLoaded = extension_loaded(...);
    }

    /**
     * Downloads to a file that mustn't exist yet. The file is removed again if
     * the download fails.
     */
    public function toFile(string $url, string $path): void
    {
        error_clear_last();
        $out = @fopen($path, 'xb');
        if ($out === false) {
            throw new CacheException("Couldn't create {$path} to download into: " . self::lastError());
        }

        try {
            $this->fetch($url, static function (string $chunk) use ($out, $path): void {
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new CacheException("Couldn't write to {$path}; is the disk full?");
                }
            });
            if (!fclose($out)) {
                throw new CacheException("Couldn't write to {$path}; is the disk full?");
            }
        } catch (\Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($path);
            throw $e;
        }
    }

    public function toString(string $url, int $maxBytes = 1 << 20): string
    {
        $body = '';
        $this->fetch($url, static function (string $chunk) use (&$body, $maxBytes, $url): void {
            $body .= $chunk;
            if (strlen($body) > $maxBytes) {
                throw new DownloadException(Url::redact($url) . " is larger than the {$maxBytes} bytes expected.");
            }
        });

        return $body;
    }

    /**
     * @param \Closure(string): void $sink
     */
    private function fetch(string $url, \Closure $sink): void
    {
        $deadline = microtime(true) + $this->deadlineSeconds;
        $origin = Url::check($url);
        $current = $url;

        for ($hop = 0; ; $hop++) {
            $parts = Url::check($current, configured: $hop === 0);
            if ($hop > 0 && $origin['scheme'] === 'https' && $parts['scheme'] !== 'https') {
                throw new DownloadException(
                    'Refusing a redirect from https to ' . Url::redact($current) . ' while downloading ' . Url::redact($url) . '.',
                );
            }
            if ($parts['scheme'] === 'https' && !($this->extensionLoaded)('openssl')) {
                throw new DownloadException(
                    'Downloading Dynoxide over https needs the openssl extension. Enable it in php.ini.',
                );
            }

            // Credentials in the URL go only to the host they were written for.
            $sameOrigin = $parts['scheme'] === $origin['scheme'] && $parts['host'] === $origin['host']
                && $parts['port'] === $origin['port'];
            $credentials = $sameOrigin && $origin['user'] !== null ? $origin['user'] . ':' . ($origin['pass'] ?? '') : null;

            $response = new Response($this->connect($current, $parts, $deadline), $deadline, $this->readTimeoutSeconds, $current);
            try {
                $response->write(self::request($current, $parts, $credentials));
                [$status, $headers] = $response->head();

                if ($status >= 300 && $status < 400 && isset($headers['location'])) {
                    if ($hop >= $this->maxRedirects) {
                        throw new DownloadException('Too many redirects while downloading ' . Url::redact($url) . '.');
                    }
                    $current = Url::resolve($current, $headers['location']);
                    continue;
                }
                if ($status !== 200) {
                    throw new DownloadException(
                        'Downloading ' . Url::redact($url) . " failed with HTTP {$status}"
                        . ($current === $url ? '' : ' from ' . Url::redact($current)) . '.',
                    );
                }
                $response->body($headers, $sink);
            } finally {
                $response->close();
            }

            return;
        }
    }

    /**
     * Opens the connection for one hop: straight to the host, or a tunnel
     * through the proxy, with TLS on top for https.
     *
     * @param array{scheme: string, host: string, port: int, user: ?string, pass: ?string} $parts
     * @return resource
     */
    private function connect(string $url, array $parts, float $deadline)
    {
        $proxy = Proxy::forUrl($this->env, $parts['scheme'], $parts['host'], $parts['port']);
        $address = $proxy?->address ?? "tcp://{$parts['host']}:{$parts['port']}";
        $via = $proxy === null ? '' : ' via the proxy ' . $proxy->shown();

        $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => trim($parts['host'], '[]'), 'SNI_enabled' => true];
        if ($this->caFile !== null) {
            $ssl['cafile'] = $this->caFile;
        }
        $context = stream_context_create(['ssl' => $ssl]);

        error_clear_last();
        $timeout = Response::remaining($deadline, $this->readTimeoutSeconds, $url);
        $socket = @stream_socket_client($address, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new DownloadException(
                'Couldn\'t connect to download ' . Url::redact($url) . $via . ': '
                . ($errstr !== '' ? $errstr : self::lastError()) . '.',
            );
        }

        try {
            if ($proxy !== null) {
                $authority = "{$parts['host']}:{$parts['port']}";
                $tunnel = new Response($socket, $deadline, $this->readTimeoutSeconds, $url);
                $tunnel->write(
                    "CONNECT {$authority} HTTP/1.1\r\nHost: {$authority}\r\nUser-Agent: " . self::USER_AGENT . "\r\n"
                    . ($proxy->authorization === null ? '' : "Proxy-Authorization: {$proxy->authorization}\r\n")
                    . "\r\n",
                );
                [$status] = $tunnel->head();
                if ($status !== 200) {
                    throw new DownloadException(
                        "The proxy {$proxy->shown()} wouldn't open a tunnel to {$parts['host']} (HTTP {$status})"
                        . ($status === 407 ? '; check the user name and password in https_proxy' : '') . '.',
                    );
                }
            }

            if ($parts['scheme'] === 'https') {
                $this->handshake($socket, $deadline, $url, $parts['host'], $via);
            }
        } catch (\Throwable $e) {
            fclose($socket);
            throw $e;
        }

        return $socket;
    }

    /**
     * Sets up TLS without blocking, so the handshake waits no longer than the
     * read timeout or the deadline. A blocking handshake would wait as long as
     * the timeout given when connecting, however much time has gone since.
     *
     * @param resource $socket
     */
    private function handshake($socket, float $deadline, string $url, string $host, string $via): void
    {
        $idleDeadline = min($deadline, microtime(true) + $this->readTimeoutSeconds);
        $methods = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        stream_set_blocking($socket, false);
        while (true) {
            error_clear_last();
            $done = @stream_socket_enable_crypto($socket, true, $methods);
            if ($done === true) {
                break;
            }
            if ($done === false) {
                throw new DownloadException(
                    "Couldn't set up TLS with {$host} to download " . Url::redact($url) . $via . ': '
                    . self::lastError() . '.',
                );
            }
            $left = Response::remaining($idleDeadline, PHP_FLOAT_MAX, $url);
            $read = [$socket];
            $write = $except = null;
            @stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1.0) * 1e6));
        }
        stream_set_blocking($socket, true);
    }

    /**
     * @param array{scheme: string, host: string, port: int, user: ?string, pass: ?string} $parts
     */
    private static function request(string $url, array $parts, ?string $credentials): string
    {
        $target = parse_url(Url::withoutCredentials($url)) ?: [];
        $path = ($target['path'] ?? '') === '' ? '/' : $target['path'];
        $path .= isset($target['query']) ? '?' . $target['query'] : '';
        $default = $parts['scheme'] === 'https' ? 443 : 80;
        $host = $parts['host'] . ($parts['port'] === $default ? '' : ':' . $parts['port']);

        return "GET {$path} HTTP/1.1\r\n"
            . "Host: {$host}\r\n"
            . 'User-Agent: ' . self::USER_AGENT . "\r\n"
            . "Accept-Encoding: identity\r\n"
            . "Connection: close\r\n"
            . ($credentials === null ? '' : 'Authorization: Basic ' . base64_encode($credentials) . "\r\n")
            . "\r\n";
    }

    private static function lastError(): string
    {
        $error = error_get_last()['message'] ?? 'unknown error';

        return (string) preg_replace('/^\w+\([^)]*\): /', '', $error);
    }
}
