<?php

declare(strict_types=1);

use Dynoxide\Tests\Support\FixtureReport;
use PHPUnit\Framework\TestCase;

final class CTest extends TestCase
{
    public function testReachesTheServer(): void
    {
        self::assertSame(200, FixtureReport::record('c', 'table_c_' . getmypid())['status']);
    }
}
