<?php

// A wrapper in front of fake-server.php: runs it as a child with the same
// arguments, stdin, stdout and stderr, and waits for it, as a version
// manager's shim might.

declare(strict_types=1);

$child = proc_open([PHP_BINARY, __DIR__ . '/fake-server.php', ...array_slice($argv, 1)], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
exit($child === false ? 70 : proc_close($child));
