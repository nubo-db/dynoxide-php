<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testTheSourceDirectoryExists(): void
    {
        self::assertDirectoryExists(dirname(__DIR__, 2) . '/src');
    }
}
