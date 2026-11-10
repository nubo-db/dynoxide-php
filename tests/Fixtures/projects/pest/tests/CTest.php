<?php

declare(strict_types=1);

use Dynoxide\Tests\Support\FixtureReport;

test('reaches the server', function (): void {
    expect(FixtureReport::record('c', 'table_c_' . getmypid())['status'])->toBe(200);
});
