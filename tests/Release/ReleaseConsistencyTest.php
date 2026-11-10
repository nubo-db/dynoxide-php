<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Release;

use Dynoxide\Binary\VersionCheck;
use Dynoxide\Release;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Checks run before a release is tagged, by the release workflow, which sets
 * DYNOXIDE_RELEASE_TAG to the tag about to be pushed. Packagist publishes a
 * tag the moment it's pushed, so these have to pass first.
 */
#[Group('release-gate')]
final class ReleaseConsistencyTest extends TestCase
{
    private const README = __DIR__ . '/../../README.md';

    public function testTheTagIsTheEmbeddedRelease(): void
    {
        $tag = getenv('DYNOXIDE_RELEASE_TAG');
        if ($tag === false || $tag === '') {
            self::markTestSkipped('Set DYNOXIDE_RELEASE_TAG to the tag about to be pushed.');
        }

        self::assertSame(Release::TAG, $tag, "The tag must be the Dynoxide release src/Release.php embeds; run scripts/update-release.php {$tag}.");
    }

    public function testTheEmbeddedTagAndVersionAgree(): void
    {
        self::assertSame('v' . Release::VERSION, Release::TAG, 'src/Release.php is half regenerated; run scripts/update-release.php again.');
    }

    public function testTheEmbeddedReleaseHasTheLauncherFlags(): void
    {
        self::assertTrue(
            version_compare(self::numeric(Release::VERSION), VersionCheck::LAUNCHER_FLAGS_SINCE, '>='),
            'Release::VERSION is ' . Release::VERSION . ', older than ' . VersionCheck::LAUNCHER_FLAGS_SINCE
            . ', so the servers this package starts by default outlive a killed run.',
        );
    }

    public function testTheReadmeStatesTheEmbeddedVersion(): void
    {
        $readme = (string) file_get_contents(self::README);
        $found = preg_match('/version (\d+\.\d+\.\d+) of this package\s+runs Dynoxide (\d+\.\d+\.\d+)/', $readme, $m);

        self::assertSame(1, $found, 'README.md no longer says which Dynoxide version this package runs.');
        self::assertSame(self::numeric(Release::VERSION), $m[1], 'README.md names a different package version.');
        self::assertSame(self::numeric(Release::VERSION), $m[2], 'README.md names a different Dynoxide version.');
    }

    /** X.Y.Z, since a release candidate's README describes the release it leads to. */
    private static function numeric(string $version): string
    {
        return preg_replace('/[-+].*$/', '', $version) ?? $version;
    }
}
