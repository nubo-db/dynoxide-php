<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit;

use Dynoxide\Exception\DynoxideException;
use Dynoxide\Platform;
use Dynoxide\Release;
use Dynoxide\Scripts\UpdateRelease\GeneratorException;
use Dynoxide\Tests\Support\TarBuilder;
use Dynoxide\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

use function Dynoxide\Scripts\UpdateRelease\collect;
use function Dynoxide\Scripts\UpdateRelease\parseSums;
use function Dynoxide\Scripts\UpdateRelease\render;

require_once dirname(__DIR__, 2) . '/scripts/update-release.php';

final class ReleaseTest extends TestCase
{
    use TempDir;

    public function testTheEmbeddedReleaseCoversEveryTarget(): void
    {
        self::assertSame(Platform::TARGETS, array_keys(Release::SHA256));
        self::assertSame('v' . Release::VERSION, Release::TAG);
        foreach (Platform::TARGETS as $target) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Release::archiveSha256($target));
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Release::binarySha256($target));
        }
    }

    public function testAnUnknownTargetThrows(): void
    {
        $this->expectException(DynoxideException::class);
        Release::archiveSha256('riscv64-unknown-linux-gnu');
    }

    #[RequiresPhpExtension('zip')]
    public function testRoundTripsThroughTheGenerator(): void
    {
        [$dir, $binaries] = $this->fakeRelease();
        $work = $this->tempDir() . '/work';
        mkdir($work);

        $release = collect('v9.8.7-rc.2', $this->fetcher($dir), $work);

        self::assertSame('9.8.7-rc.2', $release['version']);
        $class = $this->load(render($release));
        self::assertSame('9.8.7-rc.2', $class::VERSION);
        self::assertSame('v9.8.7-rc.2', $class::TAG);
        foreach (Platform::TARGETS as $target) {
            $archive = $dir . '/' . Platform::fromTarget($target)->archiveName();
            self::assertSame(hash_file('sha256', $archive), $class::archiveSha256($target));
            self::assertSame(hash('sha256', $binaries[$target]), $class::binarySha256($target));
        }
    }

    #[RequiresPhpExtension('zip')]
    public function testRefusesAnArchiveThatDoesNotMatchItsLine(): void
    {
        [$dir] = $this->fakeRelease();
        file_put_contents($dir . '/dynoxide-aarch64-apple-darwin.tar.gz', gzencode((new TarBuilder())->add('dynoxide', 'swapped')->tar()));
        $work = $this->tempDir() . '/work';
        mkdir($work);

        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('dynoxide-aarch64-apple-darwin.tar.gz hashes to');
        collect('v1.0.0', $this->fetcher($dir), $work);
    }

    #[RequiresPhpExtension('zip')]
    public function testRefusesAPackagingChange(): void
    {
        [$dir] = $this->fakeRelease(static fn (TarBuilder $tar) => $tar->add('README.md', 'extra'));
        $work = $this->tempDir() . '/work';
        mkdir($work);

        $this->expectException(DynoxideException::class);
        $this->expectExceptionMessage('more than one member');
        collect('v1.0.0', $this->fetcher($dir), $work);
    }

    public function testRefusesATagThatIsNotARelease(): void
    {
        $this->expectException(GeneratorException::class);
        collect('1.0.0', static fn () => '', $this->tempDir());
    }

    public function testRefusesAMissingAsset(): void
    {
        $lines = $this->sumsLines();
        array_pop($lines);

        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('Missing: dynoxide-x86_64-pc-windows-msvc.zip');
        parseSums(implode("\n", $lines));
    }

    public function testRefusesAnExtraAsset(): void
    {
        $lines = $this->sumsLines();
        $lines[] = str_repeat('a', 64) . '  dynoxide-riscv64-unknown-linux-musl.tar.gz';

        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('Not expected: dynoxide-riscv64-unknown-linux-musl.tar.gz');
        parseSums(implode("\n", $lines));
    }

    public function testRefusesADuplicateLine(): void
    {
        $lines = $this->sumsLines();
        $lines[] = $lines[0];

        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('twice');
        parseSums(implode("\n", $lines));
    }

    public function testRefusesAMalformedLine(): void
    {
        $lines = $this->sumsLines();
        $lines[0] = 'not-a-hash  ' . Platform::fromTarget(Platform::TARGETS[0])->archiveName();

        $this->expectException(GeneratorException::class);
        parseSums(implode("\n", $lines));
    }

    public function testAcceptsTheFormatTheReleaseWorkflowWrites(): void
    {
        $listed = parseSums(implode("\n", $this->sumsLines()) . "\n");

        self::assertCount(5, $listed);
    }

    /** @return list<string> */
    private function sumsLines(): array
    {
        return array_map(
            static fn (string $t): string => hash('sha256', $t) . '  ' . Platform::fromTarget($t)->archiveName(),
            Platform::TARGETS,
        );
    }

    /**
     * @param (\Closure(TarBuilder): TarBuilder)|null $tweak
     * @return array{string, array<string, string>}
     */
    private function fakeRelease(?\Closure $tweak = null): array
    {
        $dir = $this->tempDir() . '/release';
        mkdir($dir);
        $binaries = [];
        $sums = '';
        foreach (Platform::TARGETS as $target) {
            $platform = Platform::fromTarget($target);
            $binaries[$target] = random_bytes(2000);
            $path = $dir . '/' . $platform->archiveName();
            if ($platform->isWindows()) {
                $zip = new \ZipArchive();
                $zip->open($path, \ZipArchive::CREATE);
                $zip->addFromString('dynoxide.exe', $binaries[$target]);
                $zip->close();
            } else {
                $tar = (new TarBuilder(str_contains($target, 'linux') ? TarBuilder::GNU : TarBuilder::POSIX))
                    ->add('dynoxide', $binaries[$target]);
                ($tweak === null ? $tar : $tweak($tar))->writeGz($path);
            }
            $sums .= hash_file('sha256', $path) . '  ' . $platform->archiveName() . "\n";
        }
        file_put_contents($dir . '/sha256sums.txt', $sums);

        return [$dir, $binaries];
    }

    private function fetcher(string $dir): \Closure
    {
        return static function (string $name, ?string $saveAs = null) use ($dir): string {
            if ($saveAs === null) {
                return (string) file_get_contents($dir . '/' . $name);
            }
            copy($dir . '/' . $name, $saveAs);

            return $saveAs;
        };
    }

    /** Loads rendered source under a namespace of its own, so it can't clash with the real class. */
    private function load(string $source): string
    {
        $namespace = 'Dynoxide\\Tests\\Generated' . bin2hex(random_bytes(4));
        $file = $this->tempDir() . '/Release.php';
        file_put_contents($file, str_replace('namespace Dynoxide;', "namespace {$namespace};", $source));
        require $file;

        return $namespace . '\\Release';
    }
}
