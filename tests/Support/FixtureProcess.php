<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Support;

/**
 * Runs one of the fixture servers in tests/Fixtures as a child PHP process.
 */
final class FixtureProcess
{
    /** @var resource */
    private $process;
    public readonly int $port;

    /** @param list<string> $args */
    public function __construct(string $script, array $args, private readonly string $stderrFile)
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__) . '/Fixtures/' . $script], $args);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderrFile, 'a']], $pipes);
        if ($process === false) {
            throw new \RuntimeException("Couldn't start {$script}");
        }
        $this->process = $process;
        $line = fgets($pipes[1]);
        if ($line === false || preg_match('/^PORT (\d+)/', $line, $m) !== 1) {
            proc_terminate($process);
            throw new \RuntimeException("{$script} didn't start: " . (string) file_get_contents($stderrFile));
        }
        $this->port = (int) $m[1];
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 9);
            proc_close($this->process);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
