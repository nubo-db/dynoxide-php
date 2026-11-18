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

    public function testThePortDefaultsToZeroAndTakesAFixedOne(): void
    {
        self::assertSame(0, (new Options())->port);
        self::assertSame(8000, Options::fromArray(['port' => 8000])->port);
        self::assertSame(65535, (new Options(port: 65535))->port);
    }

    public function testRefusesAPortOutOfRange(): void
    {
        foreach ([-1, 65536] as $port) {
            try {
                new Options(port: $port);
                self::fail("port {$port} was accepted");
            } catch (DynoxideException $e) {
                self::assertStringContainsString("port must be from 0 to 65535, not {$port}", $e->getMessage());
            }
        }
    }

    public function testRefusesAPortThatIsNotAnInteger(): void
    {
        foreach (['8000', 8000.0] as $port) {
            try {
                Options::fromArray(['port' => $port]);
                self::fail('port ' . var_export($port, true) . ' was accepted');
            } catch (DynoxideException $e) {
                self::assertStringContainsString('A Dynoxide option has the wrong type', $e->getMessage());
            }
        }
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
