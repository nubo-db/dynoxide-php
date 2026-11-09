<?php

declare(strict_types=1);

namespace Dynoxide\Download;

use Dynoxide\Exception\DownloadException;

/**
 * One HTTP/1.1 exchange on an open socket: writing the request and reading
 * the response, every step bounded by the download's deadline and by the
 * read timeout.
 *
 * @internal
 */
final class Response
{
    private const MAX_HEAD_BYTES = 65536;
    private const MAX_LINE_BYTES = 4096;

    private string $buffer = '';

    /**
     * @param resource $socket
     */
    public function __construct(
        private $socket,
        private readonly float $deadline,
        private readonly float $readTimeout,
        private readonly string $url,
    ) {
    }

    /** The seconds left before the deadline, at most $cap, or a timeout if there are none. */
    public static function remaining(float $deadline, float $cap, string $url): float
    {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            throw new DownloadException('Timed out downloading ' . Url::redact($url) . '.');
        }

        return min($cap, $left);
    }

    public function write(string $data): void
    {
        while ($data !== '') {
            $this->arm();
            error_clear_last();
            $written = @fwrite($this->socket, $data);
            $this->checkTimeout();
            if ($written === false || $written === 0) {
                throw new DownloadException(
                    'Couldn\'t send the request for ' . Url::redact($this->url) . ': '
                    . (error_get_last()['message'] ?? 'the connection closed') . '.',
                );
            }
            $data = substr($data, $written);
        }
    }

    /**
     * Reads the status line and headers, skipping any 1xx responses.
     *
     * @return array{int, array<string, string>} the status and the headers, names in lower case
     */
    public function head(): array
    {
        while (true) {
            while (($end = strpos($this->buffer, "\r\n\r\n")) === false) {
                if (strlen($this->buffer) > self::MAX_HEAD_BYTES) {
                    throw new DownloadException('The response headers for ' . Url::redact($this->url) . ' are too long.');
                }
                if (!$this->pull()) {
                    throw new DownloadException('The connection closed before a response for ' . Url::redact($this->url) . '.');
                }
            }
            $lines = explode("\r\n", substr($this->buffer, 0, $end));
            $this->buffer = substr($this->buffer, $end + 4);

            if (preg_match('#^HTTP/1\.[01] (\d{3})#', (string) array_shift($lines), $m) !== 1) {
                throw new DownloadException('No HTTP response from ' . Url::redact($this->url) . '.');
            }
            $status = (int) $m[1];
            if ($status >= 100 && $status < 200) {
                continue;
            }

            $headers = [];
            foreach ($lines as $line) {
                $colon = strpos($line, ':');
                if ($colon !== false) {
                    $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
                }
            }

            return [$status, $headers];
        }
    }

    /**
     * Streams the body to $sink: chunked, or up to the connection closing,
     * checked against Content-Length when there is one.
     *
     * @param array<string, string> $headers
     * @param \Closure(string): void $sink
     */
    public function body(array $headers, \Closure $sink): void
    {
        if (strtolower($headers['transfer-encoding'] ?? '') === 'chunked') {
            $this->chunked($sink);

            return;
        }

        $length = $headers['content-length'] ?? null;
        $expected = $length !== null && ctype_digit($length) ? (int) $length : null;

        // With a length, stop once it has all arrived: a server may keep the
        // connection open despite "Connection: close".
        $received = 0;
        while ($expected === null || $received < $expected) {
            if ($this->buffer !== '') {
                $chunk = $expected === null ? $this->buffer : substr($this->buffer, 0, $expected - $received);
                $this->buffer = '';
                $received += strlen($chunk);
                $sink($chunk);

                continue;
            }
            if (!$this->pull()) {
                break;
            }
        }

        if ($expected !== null && $received !== $expected) {
            throw new DownloadException(
                'The download of ' . Url::redact($this->url) . " stopped after {$received} of {$expected} bytes.",
            );
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    /** @param \Closure(string): void $sink */
    private function chunked(\Closure $sink): void
    {
        while (true) {
            $size = trim(explode(';', $this->line(), 2)[0]);
            if ($size === '' || strlen($size) > 12 || !ctype_xdigit($size)) {
                throw new DownloadException('The response for ' . Url::redact($this->url) . ' has a malformed chunk.');
            }
            $left = (int) hexdec($size);
            if ($left === 0) {
                return;
            }
            while ($left > 0) {
                if ($this->buffer === '' && !$this->pull()) {
                    throw $this->cutShort();
                }
                $piece = substr($this->buffer, 0, $left);
                $this->buffer = (string) substr($this->buffer, strlen($piece));
                $left -= strlen($piece);
                $sink($piece);
            }
            if ($this->line() !== '') {
                throw new DownloadException('The response for ' . Url::redact($this->url) . ' has a malformed chunk.');
            }
        }
    }

    private function line(): string
    {
        while (($end = strpos($this->buffer, "\r\n")) === false) {
            if (strlen($this->buffer) > self::MAX_LINE_BYTES) {
                throw new DownloadException('The response for ' . Url::redact($this->url) . ' has a malformed chunk.');
            }
            if (!$this->pull()) {
                throw $this->cutShort();
            }
        }
        $line = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 2);

        return $line;
    }

    /** Reads what's there into the buffer; false once the connection has closed. */
    private function pull(): bool
    {
        $this->arm();
        error_clear_last();
        $data = @fread($this->socket, 65536);
        $this->checkTimeout();
        if ($data === false) {
            throw new DownloadException(
                'Reading ' . Url::redact($this->url) . ' failed: ' . (error_get_last()['message'] ?? 'unknown error') . '.',
            );
        }
        if ($data === '') {
            return !feof($this->socket);
        }
        $this->buffer .= $data;

        return true;
    }

    private function arm(): void
    {
        $timeout = self::remaining($this->deadline, $this->readTimeout, $this->url);
        stream_set_timeout($this->socket, (int) $timeout, (int) (fmod($timeout, 1.0) * 1e6));
    }

    private function checkTimeout(): void
    {
        if (stream_get_meta_data($this->socket)['timed_out']) {
            throw new DownloadException('Timed out downloading ' . Url::redact($this->url) . '.');
        }
    }

    private function cutShort(): DownloadException
    {
        return new DownloadException('The download of ' . Url::redact($this->url) . ' stopped before the end.');
    }
}
