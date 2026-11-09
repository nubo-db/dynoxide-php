<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit\Download;

use Dynoxide\Download\Downloader;
use Dynoxide\Exception\CacheException;
use Dynoxide\Exception\DownloadException;
use Dynoxide\Tests\Support\Served;
use Dynoxide\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class DownloaderTest extends TestCase
{
    use TempDir;

    /** @var list<Served> */
    private array $served = [];

    protected function tearDown(): void
    {
        foreach ($this->served as $s) {
            $s->stop();
        }
    }

    /** @param array<string, array<string, mixed>> $routes */
    private function http(array $routes = []): Served
    {
        return $this->served[] = Served::http($this->tempDir(), $routes);
    }

    /** @param array<string, array<string, mixed>> $routes */
    private function https(array $routes = []): Served
    {
        return $this->served[] = Served::https($this->tempDir(), $routes);
    }

    private function proxy(?string $credentials = null): Served
    {
        return $this->served[] = Served::proxy($this->tempDir(), $credentials);
    }

    /** @param array<string, string> $env */
    private function downloader(array $env = [], float $deadline = 10.0, float $read = 5.0): Downloader
    {
        return new Downloader($env, $deadline, $read, 5, realpath(Served::CA) ?: Served::CA);
    }

    public function testDownloadsToAFile(): void
    {
        $data = random_bytes(200_000);
        $source = $this->tempDir() . '/source.bin';
        file_put_contents($source, $data);
        $server = $this->http(['/a.bin' => ['file' => $source]]);
        $target = $this->tempDir() . '/a.bin';

        $this->downloader()->toFile($server->url('/a.bin'), $target);

        self::assertSame($data, file_get_contents($target));
    }

    public function testDownloadsToAString(): void
    {
        $server = $this->http(['/sums' => ['body' => "abc  file\n"]]);

        self::assertSame("abc  file\n", $this->downloader()->toString($server->url('/sums')));
    }

    public function testRefusesToOverwriteAnExistingFile(): void
    {
        $server = $this->http(['/a' => ['body' => 'new']]);
        $target = $this->tempDir() . '/a';
        file_put_contents($target, 'old');

        try {
            $this->downloader()->toFile($server->url('/a'), $target);
            self::fail('Expected a refusal');
        } catch (CacheException) {
            self::assertSame('old', file_get_contents($target));
        }
    }

    public function testStopsAtContentLengthWhenTheServerKeepsTheConnectionOpen(): void
    {
        $server = $this->http(['/a' => ['body' => 'all of it', 'keepOpen' => true]]);
        $started = microtime(true);

        self::assertSame('all of it', $this->downloader([], 10.0, 5.0)->toString($server->url('/a')));
        self::assertLessThan(3.0, microtime(true) - $started);
    }

    public function testFollowsARedirectWithAnAtSignInIt(): void
    {
        $server = $this->http([
            '/a' => ['status' => 302, 'headers' => ['Location' => '/gh/dynoxide@v1.7.0/x?sig=a@b']],
            '/gh/dynoxide@v1.7.0/x' => ['body' => 'found'],
        ]);

        self::assertSame('found', $this->downloader()->toString($server->url('/a')));
    }

    public function testRefusesHeadersThatNeverEnd(): void
    {
        $server = $this->http(['/a' => ['raw' => "HTTP/1.1 200 OK\r\n" . str_repeat("X-Filler: " . str_repeat('x', 100) . "\r\n", 1000)]]);

        $this->expectException(DownloadException::class);
        $this->expectExceptionMessage('too long');
        $this->downloader()->toString($server->url('/a'));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedChunks(): iterable
    {
        $head = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n";
        yield 'a size that is not hex' => [$head . "zz\r\nhello\r\n0\r\n\r\n"];
        yield 'an empty size' => [$head . "\r\nhello\r\n0\r\n\r\n"];
        yield 'something other than CRLF after the data' => [$head . "5\r\nhelloXX\r\n0\r\n\r\n"];
        yield 'a size too big to be real' => [$head . "1000000000000\r\nhello\r\n0\r\n\r\n"];
    }

    #[DataProvider('malformedChunks')]
    public function testRefusesAMalformedChunkedBody(string $raw): void
    {
        $server = $this->http(['/a' => ['raw' => $raw]]);

        $this->expectException(DownloadException::class);
        $this->expectExceptionMessage('malformed chunk');
        $this->downloader()->toString($server->url('/a'));
    }

    public function testReadsUpperCaseChunkSizesAndIgnoresTrailers(): void
    {
        $body = str_repeat('y', 300);
        $server = $this->http(['/a' => ['raw' => "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n12C\r\n{$body}\r\n0\r\nX-Trailer: t\r\n\r\n"]]);

        self::assertSame($body, $this->downloader()->toString($server->url('/a')));
    }

    #[RequiresPhpExtension('openssl')]
    public function testRefusesATrustedCertificateForAnotherName(): void
    {
        $server = $this->served[] = Served::https($this->tempDir(), ['/a' => ['body' => 'x']], Served::WRONG_NAME_CERT);
        $downloader = new Downloader([], 10.0, 5.0, 5, realpath(Served::BOTH_CA) ?: Served::BOTH_CA);

        $this->expectException(DownloadException::class);
        $this->expectExceptionMessage('Couldn\'t set up TLS');
        $downloader->toString($server->url('/a'));
    }

    #[RequiresPhpExtension('openssl')]
    public function testRefusesATrustedCertificateForAnotherNameThroughTheProxy(): void
    {
        $proxy = $this->proxy();
        $server = $this->served[] = Served::https($this->tempDir(), ['/a' => ['body' => 'x']], Served::WRONG_NAME_CERT);
        $downloader = new Downloader(['https_proxy' => $proxy->url()], 10.0, 5.0, 5, realpath(Served::BOTH_CA) ?: Served::BOTH_CA);

        try {
            $downloader->toString($server->url('/a'));
            self::fail('Expected a refusal');
        } catch (DownloadException $e) {
            self::assertStringContainsString('Couldn\'t set up TLS', $e->getMessage());
        }
        self::assertSame('CONNECT', $proxy->log()[0]['method']);
    }

    /**
     * The proxy takes most of the deadline to answer, then the server never
     * says anything to the TLS client: the handshake must stop at the
     * deadline, not a fresh timeout's worth later.
     */
    #[RequiresPhpExtension('openssl')]
    public function testATlsHandshakeAfterASlowProxyStillStopsAtTheDeadline(): void
    {
        $proxy = $this->served[] = Served::proxy($this->tempDir(), null, 1.0);
        $silent = $this->http();
        $started = microtime(true);

        try {
            $this->downloader(['https_proxy' => $proxy->url()], 2.5, 10.0)->toString('https://127.0.0.1:' . $silent->port() . '/a');
            self::fail('Expected a timeout');
        } catch (DownloadException $e) {
            self::assertStringContainsString('Timed out', $e->getMessage());
        }
        $took = microtime(true) - $started;
        // Past the proxy's second, so the tunnel opened and the wait was in TLS.
        self::assertGreaterThan(2.0, $took);
        self::assertLessThan(4.0, $took);
    }

    public function testA404FailsWithTheUrlAndLeavesNoFile(): void
    {
        $server = $this->http();
        $target = $this->tempDir() . '/a';

        try {
            $this->downloader()->toFile($server->url('/missing.tar.gz'), $target);
            self::fail('Expected a failure');
        } catch (DownloadException $e) {
            self::assertStringContainsString('HTTP 404', $e->getMessage());
            self::assertStringContainsString('/missing.tar.gz', $e->getMessage());
        }
        self::assertFileDoesNotExist($target);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function slowServers(): iterable
    {
        yield 'sends nothing' => [['stall' => true]];
        yield 'sends its headers a line at a time' => [['drip' => 'head']];
        yield 'sends its body a byte at a time' => [['body' => str_repeat('x', 100), 'drip' => 'body']];
    }

    /**
     * The read timeout here is longer than the deadline, so only the deadline
     * can end these.
     *
     * @param array<string, mixed> $route
     */
    #[DataProvider('slowServers')]
    public function testASlowServerFailsAtTheDeadline(array $route): void
    {
        $server = $this->http(['/slow' => $route]);
        $started = microtime(true);

        try {
            $this->downloader([], 1.5, 10.0)->toString($server->url('/slow'));
            self::fail('Expected a timeout');
        } catch (DownloadException $e) {
            self::assertStringContainsString('Timed out', $e->getMessage());
            self::assertStringContainsString('/slow', $e->getMessage());
        }
        self::assertLessThan(4.0, microtime(true) - $started);
    }

    #[RequiresPhpExtension('openssl')]
    public function testASlowHttpsServerFailsAtTheDeadline(): void
    {
        $server = $this->https(['/slow' => ['drip' => 'head']]);
        $started = microtime(true);

        try {
            $this->downloader([], 1.5, 10.0)->toString($server->url('/slow'));
            self::fail('Expected a timeout');
        } catch (DownloadException $e) {
            self::assertStringContainsString('Timed out', $e->getMessage());
        }
        self::assertLessThan(4.0, microtime(true) - $started);
    }

    public function testReadsAChunkedBody(): void
    {
        $data = random_bytes(5500);
        $source = $this->tempDir() . '/source.bin';
        file_put_contents($source, $data);
        $server = $this->http(['/a' => ['file' => $source, 'chunked' => true, 'continue' => true]]);

        self::assertSame($data, $this->downloader()->toString($server->url('/a')));
    }

    public function testAChunkedBodyCutShortFails(): void
    {
        $server = $this->http(['/a' => ['body' => str_repeat('x', 5000), 'chunked' => true, 'truncate' => 2500]]);
        $target = $this->tempDir() . '/a';

        try {
            $this->downloader()->toFile($server->url('/a'), $target);
            self::fail('Expected a failure');
        } catch (DownloadException $e) {
            self::assertStringContainsString('stopped before the end', $e->getMessage());
        }
        self::assertFileDoesNotExist($target);
    }

    public function testAStringLargerThanExpectedFails(): void
    {
        $server = $this->http(['/sums' => ['body' => str_repeat('x', 2000)]]);

        $this->expectException(DownloadException::class);
        $this->expectExceptionMessage('larger than the 1000 bytes expected');
        $this->downloader()->toString($server->url('/sums'), 1000);
    }

    public function testHttpsWithoutOpensslNamesTheExtension(): void
    {
        $downloader = $this->downloader();
        (function (): void {
            $this->extensionLoaded = static fn (string $name): bool => $name !== 'openssl';
        })->call($downloader);

        $this->expectException(DownloadException::class);
        $this->expectExceptionMessage('needs the openssl extension');
        $downloader->toString('https://127.0.0.1:1/a');
    }

    public function testAStallPartWayThroughTheBodyFailsOnTheReadTimeout(): void
    {
        $server = $this->http(['/slow' => ['body' => str_repeat('x', 5000), 'stallAfter' => 100]]);
        $started = microtime(true);

        try {
            $this->downloader([], 20.0, 1.0)->toString($server->url('/slow'));
            self::fail('Expected a timeout');
        } catch (DownloadException $e) {
            self::assertStringContainsString('Timed out', $e->getMessage());
        }
        // The read timeout, not the overall deadline, should end it.
        self::assertLessThan(5.0, microtime(true) - $started);
    }

    public function testATruncatedBodyFails(): void
    {
        $server = $this->http(['/a' => ['body' => str_repeat('x', 1000), 'truncate' => 400]]);
        $target = $this->tempDir() . '/a';

        try {
            $this->downloader()->toFile($server->url('/a'), $target);
            self::fail('Expected a failure');
        } catch (DownloadException $e) {
            self::assertStringContainsString('400 of 1000', $e->getMessage());
        }
        self::assertFileDoesNotExist($target);
    }

    public function testFollowsRedirectsHopByHop(): void
    {
        $assets = $this->http(['/asset' => ['body' => 'the archive']]);
        $front = $this->http([
            '/v1/a.tar.gz' => ['status' => 302, 'headers' => ['Location' => '/hop']],
            '/hop' => ['status' => 302, 'headers' => ['Location' => $assets->url('/asset', 'localhost')]],
        ]);

        self::assertSame('the archive', $this->downloader()->toString($front->url('/v1/a.tar.gz')));
        self::assertCount(2, $front->log());
        self::assertCount(1, $assets->log());
    }

    public function testStopsAfterTooManyRedirects(): void
    {
        $server = $this->http(['/loop' => ['status' => 302, 'headers' => ['Location' => '/loop']]]);

        try {
            $this->downloader()->toString($server->url('/loop'));
            self::fail('Expected a failure');
        } catch (DownloadException $e) {
            self::assertStringContainsString('Too many redirects', $e->getMessage());
        }
        // The first request and five redirects.
        self::assertCount(6, $server->log());
    }

    #[RequiresPhpExtension('openssl')]
    public function testNeverSendsCredentialsARedirectAddsToTheUrl(): void
    {
        $other = $this->https(['/asset' => ['body' => 'payload']]);
        $front = $this->https(['/a' => ['status' => 302, 'headers' => [
            'Location' => 'https://planted:creds@localhost:' . $other->port() . '/asset',
        ]]]);

        self::assertSame('payload', $this->downloader()->toString($front->url('/a')));

        self::assertArrayNotHasKey('authorization', $other->log()[0]['headers']);
    }

    public function testRefusesARedirectToARemoteHttpHost(): void
    {
        $server = $this->http(['/a' => ['status' => 302, 'headers' => ['Location' => 'http://example.com/a']]]);

        $this->expectException(DownloadException::class);
        $this->expectExceptionMessage('http://example.com/a');
        $this->downloader()->toString($server->url('/a'));
    }

    #[RequiresPhpExtension('openssl')]
    public function testDownloadsOverHttps(): void
    {
        $server = $this->https(['/a' => ['body' => 'over tls']]);

        self::assertSame('over tls', $this->downloader()->toString($server->url('/a')));
    }

    #[RequiresPhpExtension('openssl')]
    public function testRefusesAnUntrustedCertificate(): void
    {
        $server = $this->https(['/a' => ['body' => 'over tls']]);

        $this->expectException(DownloadException::class);
        $this->expectExceptionMessage('Couldn\'t set up TLS');
        (new Downloader([], 10.0, 5.0))->toString($server->url('/a'));
    }

    #[RequiresPhpExtension('openssl')]
    public function testRefusesAnHttpsRedirectToLoopbackHttp(): void
    {
        $plain = $this->http(['/a' => ['body' => 'downgraded']]);
        $secure = $this->https(['/a' => ['status' => 302, 'headers' => ['Location' => $plain->url('/a')]]]);

        try {
            $this->downloader()->toString($secure->url('/a'));
            self::fail('Expected the downgrade to be refused');
        } catch (DownloadException $e) {
            self::assertStringContainsString('from https', $e->getMessage());
        }
        self::assertSame([], $plain->log());
    }

    #[RequiresPhpExtension('openssl')]
    public function testSendsMirrorCredentialsOnlyToTheMirror(): void
    {
        $other = $this->https(['/asset' => ['body' => 'payload']]);
        $mirror = $this->https(['/a' => ['status' => 302, 'headers' => ['Location' => $other->url('/asset', 'localhost')]]]);

        $url = 'https://mirror-user:s3cret@127.0.0.1:' . $mirror->port() . '/a';
        self::assertSame('payload', $this->downloader()->toString($url));

        self::assertSame('Basic ' . base64_encode('mirror-user:s3cret'), $mirror->log()[0]['headers']['authorization'] ?? null);
        self::assertArrayNotHasKey('authorization', $other->log()[0]['headers']);
    }

    public function testAFailureNeverShowsMirrorCredentials(): void
    {
        $server = $this->http();
        $url = 'https://mirror-user:s3cret@127.0.0.1:' . $server->port() . '/a';

        try {
            // https to a plain http server fails during the TLS handshake.
            $this->downloader()->toString($url);
            self::fail('Expected a failure');
        } catch (DownloadException $e) {
            self::assertStringNotContainsString('s3cret', $e->getMessage());
            self::assertStringNotContainsString('mirror-user', $e->getMessage());
            self::assertStringContainsString('https://***@127.0.0.1:' . $server->port() . '/a', $e->getMessage());
        }
    }

    public function testALoopbackHttpMirrorIgnoresHttpProxy(): void
    {
        $proxy = $this->proxy();
        $server = $this->http(['/a' => ['body' => 'direct']]);

        $env = ['http_proxy' => $proxy->url(), 'HTTP_PROXY' => $proxy->url()];
        self::assertSame('direct', $this->downloader($env)->toString($server->url('/a')));

        self::assertSame([], $proxy->log());
    }

    #[RequiresPhpExtension('openssl')]
    public function testGoesThroughTheHttpsProxy(): void
    {
        $proxy = $this->proxy();
        $server = $this->https(['/a' => ['body' => 'tunnelled']]);

        $env = ['https_proxy' => $proxy->url()];
        self::assertSame('tunnelled', $this->downloader($env)->toString($server->url('/a')));

        self::assertSame('CONNECT', $proxy->log()[0]['method']);
        self::assertSame('127.0.0.1:' . $server->port(), $proxy->log()[0]['target']);
    }

    #[RequiresPhpExtension('openssl')]
    public function testNoProxyGoesDirect(): void
    {
        $proxy = $this->proxy();
        $server = $this->https(['/a' => ['body' => 'direct']]);

        $env = ['https_proxy' => $proxy->url(), 'no_proxy' => '127.0.0.1'];
        self::assertSame('direct', $this->downloader($env)->toString($server->url('/a')));

        self::assertSame([], $proxy->log());
    }

    #[RequiresPhpExtension('openssl')]
    public function testAuthenticatesToAnHttpsProxyAndKeepsTheHeaderFromTheDestination(): void
    {
        $proxy = $this->proxy('pat:p@ss');
        $server = $this->https(['/a' => ['body' => 'tunnelled']]);

        $env = ['https_proxy' => 'http://pat:p%40ss@127.0.0.1:' . $proxy->port()];
        self::assertSame('tunnelled', $this->downloader($env)->toString($server->url('/a')));

        $connect = $proxy->log()[0];
        self::assertSame('CONNECT', $connect['method']);
        self::assertSame('Basic ' . base64_encode('pat:p@ss'), $connect['headers']['proxy-authorization'] ?? null);
        self::assertArrayNotHasKey('proxy-authorization', $server->log()[0]['headers']);
    }

    #[RequiresPhpExtension('openssl')]
    public function testAProxyThatRejectsTheLoginFails(): void
    {
        $proxy = $this->proxy('pat:right');
        $server = $this->https(['/a' => ['body' => 'tunnelled']]);

        $env = ['https_proxy' => 'http://pat:wrong@127.0.0.1:' . $proxy->port()];
        try {
            $this->downloader($env)->toString($server->url('/a'));
            self::fail('Expected a failure');
        } catch (DownloadException $e) {
            self::assertStringNotContainsString('wrong', $e->getMessage());
            self::assertStringContainsString('HTTP 407', $e->getMessage());
        }
        self::assertCount(1, $proxy->log());
        self::assertSame('CONNECT', $proxy->log()[0]['method']);
        self::assertSame([], $server->log());
    }

    #[RequiresPhpExtension('openssl')]
    public function testARedirectToANoProxyHostGoesDirect(): void
    {
        $proxy = $this->proxy();
        $inside = $this->https(['/asset' => ['body' => 'inside']]);
        $front = $this->https(['/a' => ['status' => 302, 'headers' => ['Location' => $inside->url('/asset', 'localhost')]]]);

        $env = ['https_proxy' => $proxy->url(), 'no_proxy' => 'localhost'];
        self::assertSame('inside', $this->downloader($env)->toString($front->url('/a')));

        self::assertCount(1, $proxy->log(), 'Only the first hop should go through the proxy');
        self::assertSame('CONNECT', $proxy->log()[0]['method']);
        self::assertCount(1, $inside->log());
    }
}
