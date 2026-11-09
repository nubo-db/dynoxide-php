<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit;

use Dynoxide\Platform;
use Dynoxide\Release;
use Dynoxide\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function Dynoxide\Scripts\UpdateRelease\collect;

require_once dirname(__DIR__, 2) . '/scripts/update-release.php';

/**
 * Runs the generator over the real release assets for the embedded version,
 * when DYNOXIDE_RELEASE_ASSETS points at a directory holding them (as
 * `gh release download` writes it). This is the check that the readers cope
 * with the archives the engine actually publishes.
 */
#[Group('release-assets')]
final class RealReleaseTest extends TestCase
{
    use TempDir;

    public function testTheEmbeddedHashesMatchTheRealRelease(): void
    {
        $dir = getenv('DYNOXIDE_RELEASE_ASSETS');
        if ($dir === false || $dir === '') {
            self::markTestSkipped('Set DYNOXIDE_RELEASE_ASSETS to a directory holding the ' . Release::TAG . ' assets.');
        }

        $fetch = static function (string $name, ?string $saveAs = null) use ($dir): string {
            return $saveAs === null ? (string) file_get_contents($dir . '/' . $name) : $dir . '/' . $name;
        };
        $release = collect(Release::TAG, $fetch, $this->tempDir());

        self::assertSame(Release::VERSION, $release['version']);
        foreach (Platform::TARGETS as $target) {
            self::assertSame(Release::archiveSha256($target), $release['targets'][$target]['archive'], $target);
            self::assertSame(Release::binarySha256($target), $release['targets'][$target]['binary'], $target);
        }
    }
}
