<?php

declare(strict_types=1);

use Dynoxide\Tests\Support\FixtureReport;

test('reaches the server', function (): void {
    expect(FixtureReport::record('b', 'table_b_' . getmypid())['status'])->toBe(200);
});
