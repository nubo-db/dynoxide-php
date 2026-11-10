<?php

declare(strict_types=1);

use Dynoxide\Tests\Support\FixtureReport;
use PHPUnit\Framework\TestCase;

final class BTest extends TestCase
{
    public function testReachesTheServer(): void
    {
        self::assertSame(200, FixtureReport::record('b', 'table_b_' . getmypid())['status']);
    }
}
