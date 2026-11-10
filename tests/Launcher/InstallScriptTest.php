<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Launcher;

use Dynoxide\Cache\Installer;
use Dynoxide\Release;
use Dynoxide\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Installs the package into a fresh Composer project, as a user would, and
 * runs vendor/bin/dynoxide install there (vendor\bin\dynoxide.bat on
 * Windows). It downloads the embedded release into an empty cache, so it
 * runs only with DYNOXIDE_TEST_DOWNLOAD=1.
 *
 * Composer is `composer` on PATH, or the command in DYNOXIDE_TEST_COMPOSER.
 */
#[Group('embedded')]
final class InstallScriptTest extends TestCase
{
    use TempDir;

    private const ROOT = __DIR__ . '/../..';

    public function testVendorBinInstallsTheEmbeddedRelease(): void
    {
        if (getenv('DYNOXIDE_TEST_DOWNLOAD') !== '1') {
            self::markTestSkipped('Set DYNOXIDE_TEST_DOWNLOAD=1 to install the embedded release through vendor/bin.');
        }
        $project = $this->project();
        $env = ['DYNOXIDE_CACHE_DIR' => $this->tempDir() . DIRECTORY_SEPARATOR . 'cache'] + getenv();

        [$status, $out, $err] = $this->runScript($project, ['install'], $env);

        self::assertSame(0, $status, $err);
        self::assertStringContainsString('Installed Dynoxide ' . Release::VERSION . '.', $err);
        $installed = Installer::forEmbeddedRelease($env)->installed();
        self::assertNotNull($installed, 'Nothing verified is in the cache');
        self::assertSame($installed . "\n", $out);

        [$status, $out, $err] = $this->runScript($project, ['install'], $env);

        self::assertSame(0, $status, $err);
        self::assertStringContainsString('Dynoxide ' . Release::VERSION . ' is already installed.', $err);
        self::assertSame($installed . "\n", $out);
    }

    /**
     * A Composer project requiring the package from a copy of it, so the
     * link Composer makes points into this test's directory, never the
     * package being tested.
     */
    private function project(): string
    {
        $package = $this->tempDir() . DIRECTORY_SEPARATOR . 'package';
        $project = $this->tempDir() . DIRECTORY_SEPARATOR . 'project';
        mkdir($project);
        self::copyTree(self::ROOT . '/composer.json', $package . '/composer.json');
        self::copyTree(self::ROOT . '/bin', $package . '/bin');
        self::copyTree(self::ROOT . '/src', $package . '/src');

        file_put_contents($project . '/composer.json', json_encode([
            'name' => 'fixture/app',
            'require' => ['nubo-db/dynoxide' => Release::VERSION],
            'repositories' => [
                ['type' => 'path', 'url' => $package, 'options' => ['versions' => ['nubo-db/dynoxide' => Release::VERSION]]],
                ['packagist.org' => false],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $composer = getenv('DYNOXIDE_TEST_COMPOSER') ?: 'composer';
        $env = getenv();
        unset($env['COMPOSER']);
        $command = $composer . ' install --no-interaction --no-progress --working-dir=' . escapeshellarg($project) . ' 2>&1';
        $process = proc_open($command, [1 => ['pipe', 'w']], $pipes, $project, $env);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        self::assertSame(0, proc_close($process), "{$command} failed:\n{$output}");

        return $project;
    }

    /**
     * Runs the vendor binary the way a user would on this platform.
     *
     * @param list<string> $args
     * @param array<string, string> $env
     * @return array{int, string, string}
     */
    private function runScript(string $project, array $args, array $env): array
    {
        $bin = $project . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'dynoxide';
        if (PHP_OS_FAMILY === 'Windows') {
            self::assertFileExists($bin . '.bat');
            // The .bat runs the php on PATH, so put the PHP under test first.
            $path = '';
            foreach (array_keys($env) as $name) {
                if (strtoupper((string) $name) === 'PATH') {
                    $path = $path === '' ? $env[$name] : $path;
                    unset($env[$name]);
                }
            }
            $env['PATH'] = dirname(PHP_BINARY) . ';' . $path;
            $command = array_merge(['cmd', '/c', $bin . '.bat'], $args);
        } else {
            self::assertFileExists($bin);
            // PHP_BINARY rather than the #! line, so the PHP under test runs it.
            // Composer writes this proxy itself, executable.
            $command = array_merge([PHP_BINARY, $bin], $args);
        }
        $stdout = $this->tempDir() . DIRECTORY_SEPARATOR . 'stdout';
        $stderr = $this->tempDir() . DIRECTORY_SEPARATOR . 'stderr';
        $process = proc_open($command, [1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']], $pipes, $project, $env);
        self::assertIsResource($process);
        $status = proc_close($process);

        return [$status, (string) file_get_contents($stdout), (string) file_get_contents($stderr)];
    }

    private static function copyTree(string $from, string $to): void
    {
        if (is_file($from)) {
            @mkdir(dirname($to), 0700, true);
            copy($from, $to);
            chmod($to, fileperms($from) & 0777);

            return;
        }
        @mkdir($to, 0700, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::copyTree($from . '/' . $entry, $to . '/' . $entry);
            }
        }
    }
}
