<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit\Download;

use Dynoxide\Download\Url;
use Dynoxide\Exception\DownloadException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function allowed(): iterable
    {
        yield 'https' => ['https://mirror.example.com/dynoxide'];
        yield 'https with credentials' => ['https://user:secret@mirror.example.com/dynoxide'];
        yield 'http to 127.0.0.1' => ['http://127.0.0.1:8080/releases'];
        yield 'http to elsewhere in 127/8' => ['http://127.4.5.6/releases'];
        yield 'http to ::1' => ['http://[::1]:8080/releases'];
        yield 'http to localhost' => ['http://localhost/releases'];
    }

    #[DataProvider('allowed')]
    public function testAllows(string $url): void
    {
        self::assertSame(parse_url($url, PHP_URL_SCHEME), Url::check($url)['scheme']);
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'http to a remote host' => ['http://example.com/releases'];
        yield 'http with userinfo that looks like loopback' => ['http://127.0.0.1@example.com/releases'];
        yield 'http to loopback with credentials' => ['http://user:pw@127.0.0.1/releases'];
        yield 'http to a name that starts like loopback' => ['http://127.0.0.1.example.com/releases'];
        yield 'http to an address with 127 in the middle' => ['http://10.127.0.1/releases'];
        yield 'http to localhost as a subdomain' => ['http://localhost.example.com/releases'];
        yield 'ftp' => ['ftp://mirror.example.com/releases'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'no scheme' => ['mirror.example.com/releases'];
    }

    #[DataProvider('refused')]
    public function testRefuses(string $url): void
    {
        $this->expectException(DownloadException::class);
        Url::check($url);
    }

    public function testRedactsCredentials(): void
    {
        self::assertSame('https://mirror.example.com/x', Url::redact('https://user:s3cret@mirror.example.com/x'));
        self::assertSame('http://example.com/a@b', Url::redact('http://example.com/a@b'));
    }

    public function testAMessageNeverShowsThePassword(): void
    {
        try {
            Url::check('http://user:s3cret@example.com/x');
            self::fail('Expected a refusal');
        } catch (DownloadException $e) {
            self::assertStringNotContainsString('s3cret', $e->getMessage());
            self::assertStringNotContainsString('user', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function redirects(): iterable
    {
        yield 'absolute' => ['https://a.example/x/y', 'https://b.example/z', 'https://b.example/z'];
        yield 'scheme-relative' => ['https://a.example/x/y', '//b.example/z', 'https://b.example/z'];
        yield 'root-relative' => ['https://a.example:8443/x/y', '/z', 'https://a.example:8443/z'];
        yield 'path-relative' => ['https://a.example/x/y', 'z', 'https://a.example/x/z'];
        yield 'ipv6 host' => ['http://[::1]:8080/x/y', '/z', 'http://[::1]:8080/z'];
    }

    #[DataProvider('redirects')]
    public function testResolvesRedirects(string $base, string $location, string $expected): void
    {
        self::assertSame($expected, Url::resolve($base, $location));
    }
}
