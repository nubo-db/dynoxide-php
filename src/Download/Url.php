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
     * A URL someone configured is also refused if it has an "@" outside the
     * user:password part (see parse()). A redirect target isn't: its "@"s are
     * the server's business, and none of our credentials are in it.
     *
     * @return array{scheme: string, host: string, port: int, user: ?string, pass: ?string}
     */
    public static function check(string $url, bool $configured = true): array
    {
        $what = 'a URL the package can download from';
        $parts = $configured ? self::parse($url, $what) : parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new DownloadException("Not {$what}: " . self::obscure($url));
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

    /**
     * Whether a host is this machine: localhost, ::1 or an address in
     * 127.0.0.0/8, with or without the brackets an IPv6 address has in a URL.
     *
     * @api Framework integrations use this to judge an address a server is
     * bound to, so it stays as it is within a major version.
     */
    public static function isLoopback(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost' || $host === '::1') {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && str_starts_with($host, '127.');
    }

    /**
     * Parses a URL someone configured, refusing one that has an "@" outside
     * the user:password part. That happens when a password has an unencoded
     * "/", "?", "#" or "@", and reading such a URL either way would put part
     * of the password somewhere it gets shown or sent.
     *
     * @return array<string, int|string>
     */
    public static function parse(string $url, string $what): array
    {
        $parts = parse_url($url);
        $stray = $parts === false
            ? str_contains($url, '@')
            : str_contains(($parts['path'] ?? '') . ($parts['query'] ?? '') . ($parts['fragment'] ?? ''), '@');
        if ($stray) {
            throw new DownloadException(
                "Can't read " . self::obscure($url) . " as {$what}: it has an \"@\" outside the user:password part. "
                . 'Percent-encode any / ? # or @ in the password (as %2F %3F %23 %40).',
            );
        }
        if ($parts === false) {
            throw new DownloadException("Not {$what}: " . self::obscure($url));
        }

        return $parts;
    }

    /**
     * The URL for messages, with any user:password replaced by "***". For a
     * URL that check() accepted, which can't have a stray "@".
     */
    public static function redact(string $url): string
    {
        return (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/?\#]*@#i', '$1***@', $url);
    }

    /**
     * A URL that couldn't be read, for messages: everything before the last
     * "@" goes, scheme or not, since there's no telling where a password
     * with an unencoded "/", "?" or "#" ends.
     */
    private static function obscure(string $url): string
    {
        $at = strrpos($url, '@');
        if ($at === false) {
            return $url;
        }
        $scheme = strpos($url, '://');

        return ($scheme !== false && $scheme < $at ? substr($url, 0, $scheme + 3) : '') . '***@' . substr($url, $at + 1);
    }

    /** The URL to request: credentials go in a header, never in the URL. */
    public static function withoutCredentials(string $url): string
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
