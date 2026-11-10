<?php

declare(strict_types=1);

use Dynoxide\Tests\Support\FixtureReport;

test('reaches the server', function (): void {
    expect(FixtureReport::record('a', 'table_a_' . getmypid())['status'])->toBe(200);
});
