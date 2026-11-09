<?php

declare(strict_types=1);

namespace Dynoxide\Download;

use Dynoxide\Exception\DownloadException;

/**
 * Fetches release files over PHP's own HTTP streams, so no curl extension is
 * needed.
 *
 * Redirects are followed here, one hop at a time, rather than by PHP: PHP
 * reuses the first hop's proxy settings for every hop, and GitHub sends each
 * download to a different host. Each hop gets the URL rules, the proxy and
 * no_proxy afresh, and may not go from https to http.
 */
final class Downloader
{
    private const USER_AGENT = 'dynoxide-php';

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
            throw new DownloadException("Couldn't create {$path} to download into: " . self::lastError());
        }

        try {
            $this->fetch($url, static function (string $chunk) use ($out, $path): void {
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new DownloadException("Couldn't write to {$path}; is the disk full?");
                }
            });
            if (!fclose($out)) {
                throw new DownloadException("Couldn't write to {$path}; is the disk full?");
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
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new DownloadException(
                'Downloading Dynoxide needs allow_url_fopen, which is off in php.ini. Turn it on, '
                . 'or download the binary yourself and point DYNOXIDE_BINARY at it.',
            );
        }

        $deadline = microtime(true) + $this->deadlineSeconds;
        $origin = Url::check($url);
        $current = $url;

        for ($hop = 0; ; $hop++) {
            $parts = Url::check($current);
            if ($hop > 0 && $origin['scheme'] === 'https' && $parts['scheme'] !== 'https') {
                throw new DownloadException(
                    'Refusing a redirect from https to ' . Url::redact($current) . ' while downloading ' . Url::redact($url) . '.',
                );
            }
            if ($parts['scheme'] === 'https' && !extension_loaded('openssl')) {
                throw new DownloadException(
                    'Downloading Dynoxide over https needs the openssl extension. Enable it in php.ini, '
                    . 'or download the binary yourself and point DYNOXIDE_BINARY at it.',
                );
            }

            // Credentials in the URL go only to the host they were written for.
            $sameOrigin = $parts['scheme'] === $origin['scheme'] && $parts['host'] === $origin['host']
                && $parts['port'] === $origin['port'];
            $user = $sameOrigin ? $origin['user'] : null;
            $pass = $sameOrigin ? $origin['pass'] : null;

            [$stream, $status, $headers] = $this->open($current, $parts, $user, $pass, $deadline);

            if ($status >= 300 && $status < 400 && isset($headers['location'])) {
                fclose($stream);
                if ($hop >= $this->maxRedirects) {
                    throw new DownloadException('Too many redirects while downloading ' . Url::redact($url) . '.');
                }
                $current = Url::resolve($current, $headers['location']);
                continue;
            }

            try {
                if ($status !== 200) {
                    throw new DownloadException(
                        "Downloading " . Url::redact($url) . " failed with HTTP {$status}"
                        . ($current === $url ? '' : ' from ' . Url::redact($current)) . '.',
                    );
                }
                $this->read($stream, $sink, $deadline, $url, $headers['content-length'] ?? null);
            } finally {
                fclose($stream);
            }

            return;
        }
    }

    /**
     * @param array{scheme: string, host: string, port: int, user: ?string, pass: ?string} $parts
     * @return array{resource, int, array<string, string>}
     */
    private function open(string $url, array $parts, ?string $user, ?string $pass, float $deadline): array
    {
        $headers = ['User-Agent: ' . self::USER_AGENT, 'Connection: close'];
        if ($user !== null) {
            $headers[] = 'Authorization: Basic ' . base64_encode($user . ':' . ($pass ?? ''));
        }

        $http = [
            'method' => 'GET',
            'follow_location' => 0,
            'ignore_errors' => true,
            'timeout' => $this->timeout($deadline, $url),
        ];

        $proxy = Proxy::forUrl($this->env, $parts['scheme'], $parts['host'], $parts['port']);
        if ($proxy !== null) {
            $http['proxy'] = $proxy->address;
            // A plain http request goes to the proxy whole; https is tunnelled
            // with CONNECT, and PHP sends Proxy-Authorization only on the CONNECT.
            $http['request_fulluri'] = $parts['scheme'] === 'http';
            if ($proxy->authorization !== null) {
                $headers[] = 'Proxy-Authorization: ' . $proxy->authorization;
            }
        }
        $http['header'] = $headers;

        $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
        if ($this->caFile !== null) {
            $ssl['cafile'] = $this->caFile;
        }

        $context = stream_context_create(['http' => $http, 'ssl' => $ssl]);
        // PHP would send credentials in the URL itself, to whichever host it
        // names; the Authorization header above is the only way they go out.
        error_clear_last();
        $stream = @fopen(Url::redact($url), 'rb', false, $context);
        if ($stream === false) {
            $via = $proxy === null ? '' : ' via the proxy ' . $proxy->shown();
            throw new DownloadException(
                'Couldn\'t download ' . Url::redact($url) . $via . ': ' . self::lastError() . '.',
            );
        }

        $meta = stream_get_meta_data($stream);
        [$status, $responseHeaders] = self::parseHeaders(is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : []);
        if ($status === null) {
            fclose($stream);
            throw new DownloadException('No HTTP response from ' . Url::redact($url) . '.');
        }

        return [$stream, $status, $responseHeaders];
    }

    /**
     * @param resource $stream
     * @param \Closure(string): void $sink
     */
    private function read($stream, \Closure $sink, float $deadline, string $url, ?string $contentLength): void
    {
        $received = 0;
        while (!feof($stream)) {
            $timeout = $this->timeout($deadline, $url);
            stream_set_timeout($stream, (int) $timeout, (int) (fmod($timeout, 1.0) * 1e6));
            error_clear_last();
            $chunk = @fread($stream, 65536);
            if (stream_get_meta_data($stream)['timed_out']) {
                throw new DownloadException('Timed out downloading ' . Url::redact($url) . '.');
            }
            if ($chunk === false) {
                throw new DownloadException('Reading ' . Url::redact($url) . ' failed: ' . self::lastError() . '.');
            }
            if ($chunk !== '') {
                $received += strlen($chunk);
                $sink($chunk);
            }
        }

        if ($contentLength !== null && ctype_digit($contentLength) && (int) $contentLength !== $received) {
            throw new DownloadException(
                'The download of ' . Url::redact($url) . " stopped after {$received} of {$contentLength} bytes.",
            );
        }
    }

    private function timeout(float $deadline, string $url): float
    {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            throw new DownloadException('Timed out downloading ' . Url::redact($url) . '.');
        }

        return min($this->readTimeoutSeconds, $left);
    }

    /**
     * Reads the last response in the header list: when there's a proxy tunnel,
     * the list can start with the proxy's own reply.
     *
     * @param list<string> $lines
     * @return array{?int, array<string, string>}
     */
    private static function parseHeaders(array $lines): array
    {
        $status = null;
        $headers = [];
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
                $headers = [];
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
            }
        }

        return [$status, $headers];
    }

    private static function lastError(): string
    {
        $error = error_get_last()['message'] ?? 'unknown error';

        return (string) preg_replace('/^\w+\([^)]*\): /', '', $error);
    }
}
