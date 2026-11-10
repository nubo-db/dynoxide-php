<?php

declare(strict_types=1);

namespace Dynoxide;

use Dynoxide\Exception\DynoxideException;

/**
 * How to start a server. Every option is optional.
 */
final class Options
{
    /**
     * @param string|null $binary a Dynoxide binary to use instead of the one this package downloads.
     *        It isn't checksum-verified; its version is checked by running it with --version.
     * @param bool $binaryFromPath use the dynoxide found on PATH instead
     * @param string|null $dbPath a database file that survives a restart. Give each server its own.
     * @param string|null $schema a schema file whose tables exist before the server is ready
     * @param float $startTimeout seconds to wait for the server to be ready
     * @param (\Closure(string): void)|null $log receives the line logged once per start, saying
     *        which binary ran and where it's listening, and any warnings. Defaults to stderr.
     */
    public function __construct(
        public readonly ?string $binary = null,
        public readonly bool $binaryFromPath = false,
        public readonly ?string $dbPath = null,
        public readonly ?string $schema = null,
        public readonly float $startTimeout = 10.0,
        public readonly ?\Closure $log = null,
    ) {
        if (!is_finite($startTimeout) || $startTimeout <= 0) {
            throw new DynoxideException('startTimeout must be more than 0 seconds, not ' . var_export($startTimeout, true) . '.');
        }
    }

    /**
     * Options from an array with the constructor's parameter names as keys,
     * such as ['dbPath' => '/tmp/test.db'].
     *
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        $known = ['binary', 'binaryFromPath', 'dbPath', 'schema', 'startTimeout', 'log'];
        $unknown = array_diff(array_keys($options), $known);
        if ($unknown !== []) {
            throw new DynoxideException(
                'Unknown Dynoxide option ' . implode(', ', $unknown) . '. The options are ' . implode(', ', $known) . '.',
            );
        }
        try {
            return new self(...$options);
        } catch (\TypeError $e) {
            throw new DynoxideException('A Dynoxide option has the wrong type: ' . $e->getMessage(), 0, $e);
        }
    }

    /** Where the start line and warnings go. */
    public function logger(): \Closure
    {
        return $this->log ?? static function (string $line): void {
            fwrite(defined('STDERR') ? STDERR : fopen('php://stderr', 'w'), $line . PHP_EOL);
        };
    }
}
