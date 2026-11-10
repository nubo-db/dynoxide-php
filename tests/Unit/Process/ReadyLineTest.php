<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit\Process;

use Dynoxide\Process\ReadyLine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadyLineTest extends TestCase
{
    public function testReadsTheFieldsByName(): void
    {
        $ready = ReadyLine::parse(
            '{"pid":4242,"port":54321,"future":{"a":1},"host":"127.0.0.1","schema":1,"event":"ready","version":"1.7.0","endpoint":"http://127.0.0.1:54321"}',
        );

        self::assertSame(['http://127.0.0.1:54321', '127.0.0.1', 54321], [$ready->endpoint, $ready->host, $ready->port]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function badLines(): iterable
    {
        $ok = ['event' => 'ready', 'endpoint' => 'http://127.0.0.1:1', 'host' => '127.0.0.1', 'port' => 1];
        $with = static fn (array $changes): string => (string) json_encode(array_merge($ok, $changes));
        $without = static function (string $field) use ($ok): string {
            unset($ok[$field]);

            return (string) json_encode($ok);
        };

        yield 'not JSON' => ['Dynoxide listening on http://127.0.0.1:1', "isn't JSON"];
        yield 'truncated' => ['{"event":"ready","port":', "isn't JSON"];
        yield 'a list' => ['[1,2]', "isn't a JSON object"];
        yield 'a string' => ['"ready"', "isn't a JSON object"];
        yield 'another event' => [$with(['event' => 'starting']), "isn't a ready event"];
        yield 'no event' => [$without('event'), "isn't a ready event"];
        yield 'port 0' => [$with(['port' => 0]), "port that can't be used"];
        yield 'port as a string' => [$with(['port' => '54321']), 'no usable port'];
        yield 'port too big' => [$with(['port' => 70000]), "port that can't be used"];
        yield 'no port' => [$without('port'), 'no usable port'];
        yield 'no endpoint' => [$without('endpoint'), 'no endpoint'];
        yield 'empty endpoint' => [$with(['endpoint' => '']), 'no endpoint'];
        yield 'no host' => [$without('host'), 'no host'];
    }

    #[DataProvider('badLines')]
    public function testRefusesALineItCannotUse(string $line, string $message): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        ReadyLine::parse($line);
    }

    public function testAPortFromAnyLineMustBeUsable(): void
    {
        self::assertSame(65535, ReadyLine::of('http://127.0.0.1:65535', '127.0.0.1', 65535)->port);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("reported a port that can't be used: 99999");

        ReadyLine::of('http://127.0.0.1:99999', '127.0.0.1', 99999);
    }
}
