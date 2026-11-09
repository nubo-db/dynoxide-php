<?php

declare(strict_types=1);

namespace Dynoxide\Download;

use Dynoxide\Exception\DownloadException;

/**
 * The rules for which URLs the package will download from, and how they're
 * shown in messages.
 */
final class Url
{
    /**
     * A URL is acceptable if it's https, or plain http to this machine with no
     * credentials in it.
     *
     * @return array{scheme: string, host: string, port: int, user: ?string, pass: ?string}
     */
    public static function check(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new DownloadException('Not a URL the package can download from: ' . self::redact($url));
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $hasUserinfo = isset($parts['user']) || isset($parts['pass']);

        if ($scheme === 'http') {
            if ($hasUserinfo || !self::isLoopback($host)) {
                throw new DownloadException(
                    'Refusing to download from ' . self::redact($url) . ': plain http is only allowed '
                    . 'for this machine (127.0.0.1, ::1 or localhost), without credentials. Use https.',
                );
            }
        } elseif ($scheme !== 'https') {
            throw new DownloadException('Refusing to download from ' . self::redact($url) . ': only https is supported.');
        }

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $parts['port'] ?? ($scheme === 'https' ? 443 : 80),
            'user' => isset($parts['user']) ? rawurldecode($parts['user']) : null,
            'pass' => isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
        ];
    }

    public static function isLoopback(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost' || $host === '::1') {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && str_starts_with($host, '127.');
    }

    /** The URL with any credentials removed, for messages. */
    public static function redact(string $url): string
    {
        return (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/?\#]*@#i', '$1', $url);
    }

    /** Resolves a Location header against the URL that sent it. */
    public static function resolve(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }

        // parse_url keeps the brackets on an IPv6 host, so this reassembles as written.
        $parts = parse_url($base) ?: [];
        $scheme = $parts['scheme'] ?? 'https';
        $authority = ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return "{$scheme}://{$authority}{$location}";
        }

        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return "{$scheme}://{$authority}{$dir}{$location}";
    }
}
