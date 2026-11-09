<?php

declare(strict_types=1);

// One of several processes racing to install the same version, for the
// concurrency test. Prints the installed path and the binary's hash.
//
// Usage: php install-worker.php <json-args>

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dynoxide\Cache\CacheDirectory;
use Dynoxide\Cache\Installer;
use Dynoxide\Download\Downloader;
use Dynoxide\Platform;

$a = json_decode($argv[1], true);
$installer = new Installer(
    new CacheDirectory($a['root']),
    new Downloader([], 30.0, 10.0),
    Platform::fromTarget($a['target']),
    $a['version'],
    $a['archive'],
    $a['binary'],
    $a['base'],
);

try {
    $path = $installer->install();
    echo $path, "\n", hash_file('sha256', $path), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
