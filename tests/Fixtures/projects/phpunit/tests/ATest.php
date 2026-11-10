<?php

declare(strict_types=1);

use Dynoxide\Tests\Support\FixtureReport;
use PHPUnit\Framework\TestCase;

final class ATest extends TestCase
{
    public function testReachesTheServer(): void
    {
        self::assertSame(200, FixtureReport::record('a', 'table_a_' . getmypid())['status']);
        if (getenv('FIXTURE_FAIL') === '1') {
            self::fail('failing on purpose');
        }
    }
}
