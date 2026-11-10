<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit;

use Dynoxide\Exception\DynoxideException;
use Dynoxide\Options;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    public function testBuildsFromAnArrayByName(): void
    {
        $options = Options::fromArray(['dbPath' => '/tmp/a.db', 'startTimeout' => 2.5, 'binaryFromPath' => true]);

        self::assertSame(['/tmp/a.db', 2.5, true, null], [$options->dbPath, $options->startTimeout, $options->binaryFromPath, $options->binary]);
    }

    public function testRefusesAnUnknownOptionNamingTheKnownOnes(): void
    {
        $this->expectException(DynoxideException::class);
        $this->expectExceptionMessage('Unknown Dynoxide option db_path. The options are binary, binaryFromPath, dbPath');

        Options::fromArray(['db_path' => '/tmp/a.db']);
    }

    public function testRefusesAnOptionOfTheWrongType(): void
    {
        $this->expectException(DynoxideException::class);
        $this->expectExceptionMessage('A Dynoxide option has the wrong type');

        Options::fromArray(['startTimeout' => 'soon']);
    }

    public function testRefusesATimeoutThatIsNotPositive(): void
    {
        $this->expectException(DynoxideException::class);
        $this->expectExceptionMessage('startTimeout must be more than 0 seconds');

        new Options(startTimeout: 0.0);
    }

    /** A NAN deadline is never reached, so the start would wait for ever. */
    public function testRefusesATimeoutThatIsNotFinite(): void
    {
        foreach ([NAN, INF] as $timeout) {
            try {
                new Options(startTimeout: $timeout);
                self::fail("accepted {$timeout}");
            } catch (DynoxideException $e) {
                self::assertStringContainsString('startTimeout must be more than 0 seconds', $e->getMessage());
            }
        }
    }

    public function testTheGivenLoggerIsUsed(): void
    {
        $lines = [];
        $options = new Options(log: static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        ($options->logger())('hello');

        self::assertSame(['hello'], $lines);
    }
}
