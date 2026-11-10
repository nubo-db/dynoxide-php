<?php

declare(strict_types=1);

use Dynoxide\Tests\Support\FixtureReport;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class IsolatedTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testReachesTheServerFromAChildProcess(): void
    {
        self::assertSame(200, FixtureReport::record('isolated')['status']);
    }
}
