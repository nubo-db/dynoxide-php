<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit\Download;

use Dynoxide\Download\Downloader;
use Dynoxide\Exception\DownloadException;
use Dynoxide\Tests\Support\Served;
use Dynoxide\Tests\Support\TempDir;
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
        } catch (DownloadException) {
            self::assertSame('old', file_get_contents($target));
        }
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

    public function testAStalledServerFailsWithinTheDeadline(): void
    {
        $server = $this->http(['/slow' => ['stall' => true]]);
        $started = microtime(true);

        try {
            $this->downloader([], 2.0, 1.0)->toString($server->url('/slow'));
            self::fail('Expected a timeout');
        } catch (DownloadException $e) {
            self::assertStringContainsString('/slow', $e->getMessage());
        }
        self::assertLessThan(5.0, microtime(true) - $started);
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
        }
    }

    public function testGoesThroughTheHttpProxy(): void
    {
        $proxy = $this->proxy();
        $server = $this->http(['/a' => ['body' => 'proxied']]);

        $env = ['http_proxy' => $proxy->url()];
        self::assertSame('proxied', $this->downloader($env)->toString($server->url('/a')));

        self::assertSame($server->url('/a'), $proxy->log()[0]['target']);
    }

    public function testNoProxyGoesDirect(): void
    {
        $proxy = $this->proxy();
        $server = $this->http(['/a' => ['body' => 'direct']]);

        $env = ['http_proxy' => $proxy->url(), 'no_proxy' => '127.0.0.1'];
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
        }
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
