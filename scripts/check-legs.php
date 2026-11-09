<?php

declare(strict_types=1);

// Compares the CI legs that reported in against the expected list, so a leg
// that was dropped from the matrix or skipped fails the run instead of
// disappearing quietly.
//
// Usage: php scripts/check-legs.php <expected-list> <directory-of-leg-markers>

if ($argc !== 3) {
    fwrite(STDERR, "usage: check-legs.php <expected-list> <markers-dir>\n");
    exit(2);
}

$expected = array_values(array_filter(
    array_map('trim', file($argv[1], FILE_IGNORE_NEW_LINES) ?: []),
    static fn (string $line): bool => $line !== '' && $line[0] !== '#',
));

$ran = [];
foreach (glob(rtrim($argv[2], '/\\') . '/*/leg.txt') ?: [] as $marker) {
    $ran[] = trim((string) file_get_contents($marker));
}
sort($expected);
sort($ran);

echo 'Expected ' . count($expected) . ' legs, ' . count($ran) . " reported:\n";
foreach ($ran as $leg) {
    echo "  {$leg}\n";
}

if ($expected === []) {
    fwrite(STDERR, "The expected list is empty, so nothing would be checked.\n");
    exit(1);
}

$missing = array_diff($expected, $ran);
$unexpected = array_diff($ran, $expected);

foreach ($missing as $leg) {
    fwrite(STDERR, "Missing leg: {$leg}\n");
}
foreach ($unexpected as $leg) {
    fwrite(STDERR, "Leg not in the expected list: {$leg}\n");
}

exit($missing === [] && $unexpected === [] ? 0 : 1);
