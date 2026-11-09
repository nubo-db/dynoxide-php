<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit\Download;

use Dynoxide\Download\Proxy;
use Dynoxide\Exception\DownloadException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProxyTest extends TestCase
{
    public function testNoProxyWithoutSettings(): void
    {
        self::assertNull(Proxy::forUrl([], 'https', 'github.com', 443));
    }

    public function testUsesHttpsProxyForHttps(): void
    {
        $proxy = Proxy::forUrl(['https_proxy' => 'http://proxy.corp:3128', 'http_proxy' => 'http://other:1'], 'https', 'github.com', 443);

        self::assertNotNull($proxy);
        self::assertSame('tcp://proxy.corp:3128', $proxy->address);
        self::assertNull($proxy->authorization);
    }

    public function testNeverProxiesPlainHttp(): void
    {
        // Plain http only ever goes to this machine, which a proxy can't reach for us.
        $env = ['https_proxy' => 'http://proxy.corp:3128', 'http_proxy' => 'http://other:1', 'HTTP_PROXY' => 'http://other:2'];

        self::assertNull(Proxy::forUrl($env, 'http', '127.0.0.1', 8080));
    }

    /** @return iterable<string, array{string, string}> */
    public static function ambiguousPasswords(): iterable
    {
        yield 'slash' => ['http://user:pa/ss@proxy:3128', 'pa/ss'];
        yield 'slash that parses as a port' => ['http://user:12/34@proxy:3128', '12/34'];
        yield 'question mark' => ['http://user:p?w@proxy:3128', 'p?w'];
        yield 'hash' => ['http://user:se#cret@proxy:3128', 'se#cret'];
    }

    #[DataProvider('ambiguousPasswords')]
    public function testRefusesAPasswordThatNeedsEncodingWithoutShowingIt(string $url, string $password): void
    {
        try {
            Proxy::parse($url);
            self::fail('Expected a refusal');
        } catch (DownloadException $e) {
            self::assertStringContainsString('Percent-encode', $e->getMessage());
            self::assertStringNotContainsString($password, $e->getMessage());
            self::assertStringNotContainsString('//user', $e->getMessage());
        }
    }

    public function testAnAtSignInThePasswordIsFine(): void
    {
        // PHP splits at the last "@", so this one can't be misread.
        $proxy = Proxy::parse('http://user:a@b@proxy:3128');

        self::assertSame('tcp://proxy:3128', $proxy->address);
        self::assertSame('http://***@proxy:3128', $proxy->shown());
    }

    public function testAnEncodedPasswordIsFine(): void
    {
        $proxy = Proxy::parse('http://user:pa%2Fss%40x@proxy:3128');

        self::assertSame('tcp://proxy:3128', $proxy->address);
        self::assertSame('Basic ' . base64_encode('user:pa/ss@x'), $proxy->authorization);
        self::assertStringNotContainsString('pa', $proxy->shown());
    }

    public function testLowerCaseWinsOverUpperCase(): void
    {
        $env = ['https_proxy' => 'http://lower:1', 'HTTPS_PROXY' => 'http://upper:2'];

        self::assertSame('tcp://lower:1', Proxy::forUrl($env, 'https', 'github.com', 443)?->address);
    }

    public function testUpperCaseIsUsedWhenLowerCaseIsEmpty(): void
    {
        $env = ['https_proxy' => '', 'HTTPS_PROXY' => 'http://upper:2'];

        self::assertSame('tcp://upper:2', Proxy::forUrl($env, 'https', 'github.com', 443)?->address);
    }

    public function testLowerCaseNoProxyWins(): void
    {
        $env = ['https_proxy' => 'http://p:1', 'no_proxy' => 'example.org', 'NO_PROXY' => 'github.com'];

        self::assertNotNull(Proxy::forUrl($env, 'https', 'github.com', 443));
        self::assertNull(Proxy::forUrl($env, 'https', 'example.org', 443));
    }

    public function testDefaultsToPort80AndAcceptsAMissingScheme(): void
    {
        self::assertSame('tcp://proxy.corp:80', Proxy::parse('proxy.corp')->address);
    }

    public function testCredentialsBecomeABasicHeader(): void
    {
        $proxy = Proxy::parse('http://al%40ice:p%3Ass@proxy.corp:3128');

        self::assertSame('Basic ' . base64_encode('al@ice:p:ss'), $proxy->authorization);
        self::assertSame('tcp://proxy.corp:3128', $proxy->address);
        self::assertStringNotContainsString('p%3Ass', $proxy->shown());
    }

    public function testRefusesProxySchemesPhpCannotUse(): void
    {
        try {
            Proxy::parse('socks5://user:hunter2@proxy.corp:1080');
            self::fail('Expected a refusal');
        } catch (DownloadException $e) {
            self::assertStringNotContainsString('hunter2', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string, string, int, bool}> */
    public static function noProxy(): iterable
    {
        yield 'star' => ['*', 'github.com', 443, true];
        yield 'exact host' => ['github.com', 'github.com', 443, true];
        yield 'suffix' => ['githubusercontent.com', 'release-assets.githubusercontent.com', 443, true];
        yield 'dotted suffix' => ['.githubusercontent.com', 'release-assets.githubusercontent.com', 443, true];
        yield 'not a suffix match on a partial label' => ['hub.com', 'github.com', 443, false];
        yield 'list with spaces and commas' => ['foo.test, github.com ,bar.test', 'github.com', 443, true];
        yield 'matching port' => ['github.com:443', 'github.com', 443, true];
        yield 'other port' => ['github.com:8443', 'github.com', 443, false];
        yield 'cidr' => ['10.0.0.0/8', '10.1.2.3', 443, true];
        yield 'outside cidr' => ['10.0.0.0/8', '11.1.2.3', 443, false];
        yield 'ip' => ['127.0.0.1', '127.0.0.1', 8080, true];
        yield 'ipv6 loopback' => ['::1', '[::1]', 8080, true];
        yield 'case' => ['GitHub.COM', 'github.com', 443, true];
        yield 'empty' => ['', 'github.com', 443, false];
    }

    #[DataProvider('noProxy')]
    public function testNoProxyMatching(string $list, string $host, int $port, bool $bypass): void
    {
        self::assertSame($bypass, Proxy::bypasses($list, $host, $port));
    }
}
