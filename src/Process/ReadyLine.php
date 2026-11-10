<?php

declare(strict_types=1);

namespace Dynoxide\Process;

/**
 * The first line a server started with --ready-json prints: a JSON object
 * saying where it's listening. Fields are read by name, so their order and
 * any new ones don't matter. A line it can't use is reported with an
 * \UnexpectedValueException whose message finishes the sentence "The server
 * ...", for the launcher to add the binary and its stderr to.
 *
 * @internal
 */
final class ReadyLine
{
    private function __construct(
        public readonly string $endpoint,
        public readonly string $host,
        public readonly int $port,
    ) {
    }

    public static function parse(string $line): self
    {
        $shown = self::shown($line);
        try {
            $ready = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \UnexpectedValueException("printed a ready line that isn't JSON: {$shown}");
        }
        if (!is_array($ready) || array_is_list($ready)) {
            throw new \UnexpectedValueException("printed a ready line that isn't a JSON object: {$shown}");
        }
        if (($ready['event'] ?? null) !== 'ready') {
            throw new \UnexpectedValueException("printed a first line that isn't a ready event: {$shown}");
        }
        $port = $ready['port'] ?? null;
        if (!is_int($port)) {
            throw new \UnexpectedValueException("printed a ready line with no usable port: {$shown}");
        }
        if (!is_string($ready['endpoint'] ?? null) || $ready['endpoint'] === '') {
            throw new \UnexpectedValueException("printed a ready line with no endpoint: {$shown}");
        }
        if (!is_string($ready['host'] ?? null) || $ready['host'] === '') {
            throw new \UnexpectedValueException("printed a ready line with no host: {$shown}");
        }

        return self::of($ready['endpoint'], $ready['host'], $port, $shown);
    }

    /** Where a server is listening, however it said so. */
    public static function of(string $endpoint, string $host, int $port, ?string $shown = null): self
    {
        if ($port < 1 || $port > 65535) {
            throw new \UnexpectedValueException('reported a port that can\'t be used: ' . ($shown ?? (string) $port));
        }

        return new self($endpoint, $host, $port);
    }

    private static function shown(string $line): string
    {
        $line = (string) preg_replace('/[^\x20-\x7e]+/', ' ', trim($line));

        return '"' . (strlen($line) > 300 ? substr($line, 0, 300) . '...' : $line) . '"';
    }
}
