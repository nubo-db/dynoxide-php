<?php

declare(strict_types=1);

namespace Dynoxide\Download;

use Dynoxide\Exception\DownloadException;

/**
 * The proxy, if any, for one https request, from the usual environment
 * variables.
 *
 * Lower-case names win over upper-case ones, as in Composer and curl. Each hop
 * of a redirect is looked up afresh, so a redirect to a host in no_proxy goes
 * direct. http_proxy is never used: plain http only ever goes to this machine,
 * which a proxy can't reach on our behalf.
 */
final class Proxy
{
    private function __construct(
        public readonly string $address,
        public readonly ?string $authorization,
        private readonly string $shown,
    ) {
    }

    /**
     * @param array<string, string> $env
     */
    public static function forUrl(array $env, string $scheme, string $host, int $port): ?self
    {
        $proxy = $scheme === 'https' ? self::first($env, 'https_proxy', 'HTTPS_PROXY') : null;
        if ($proxy === null) {
            return null;
        }

        $noProxy = self::first($env, 'no_proxy', 'NO_PROXY');
        if ($noProxy !== null && self::bypasses($noProxy, $host, $port)) {
            return null;
        }

        return self::parse($proxy);
    }

    public static function parse(string $proxy): self
    {
        $url = str_contains($proxy, '://') ? $proxy : 'http://' . $proxy;
        $parts = Url::parse($url, 'a proxy URL');
        if (!isset($parts['host'])) {
            throw new DownloadException('The proxy setting isn\'t a URL: ' . Url::redact($url));
        }
        if (strtolower($parts['scheme'] ?? '') !== 'http') {
            throw new DownloadException(
                'Only http:// proxies are supported, not ' . Url::redact($url)
                . '. The proxy carries https downloads either way.',
            );
        }

        $authorization = null;
        if (isset($parts['user'])) {
            $credentials = rawurldecode((string) $parts['user']) . ':' . rawurldecode((string) ($parts['pass'] ?? ''));
            $authorization = 'Basic ' . base64_encode($credentials);
        }
        $port = $parts['port'] ?? 80;

        return new self("tcp://{$parts['host']}:{$port}", $authorization, Url::redact($url));
    }

    /** The proxy URL without credentials, for messages. */
    public function shown(): string
    {
        return $this->shown;
    }

    /**
     * Whether a no_proxy list covers the host: "*", an exact host, a domain
     * suffix (with or without a leading dot), an IPv4 address or CIDR range,
     * each optionally with ":port".
     */
    public static function bypasses(string $noProxy, string $host, int $port): bool
    {
        $host = strtolower(trim($host, '[]'));
        foreach (preg_split('/[\s,]+/', strtolower($noProxy), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            if ($entry === '*') {
                return true;
            }

            $entryPort = null;
            if (preg_match('/^(.+):(\d+)$/', $entry, $m) === 1 && !str_contains($m[1], ':')) {
                [$entry, $entryPort] = [$m[1], (int) $m[2]];
            }
            if ($entryPort !== null && $entryPort !== $port) {
                continue;
            }
            $entry = trim($entry, '[]');

            if (str_contains($entry, '/')) {
                if (self::inCidr($host, $entry)) {
                    return true;
                }
                continue;
            }

            $entry = ltrim($entry, '.');
            if ($entry !== '' && ($host === $entry || str_ends_with($host, '.' . $entry))) {
                return true;
            }
        }

        return false;
    }

    private static function inCidr(string $host, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr, 2);
        $hostLong = ip2long($host);
        $networkLong = ip2long($network);
        if ($hostLong === false || $networkLong === false || !ctype_digit($bits) || (int) $bits > 32) {
            return false;
        }
        $mask = (int) $bits === 0 ? 0 : (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;

        return ($hostLong & $mask) === ($networkLong & $mask);
    }

    /**
     * @param array<string, string> $env
     */
    private static function first(array $env, string ...$names): ?string
    {
        foreach ($names as $name) {
            if (isset($env[$name]) && trim($env[$name]) !== '') {
                return trim($env[$name]);
            }
        }

        return null;
    }
}
