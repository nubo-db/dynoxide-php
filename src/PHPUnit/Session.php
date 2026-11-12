<?php

declare(strict_types=1);

namespace Dynoxide\PHPUnit;

use Dynoxide\AwsEnvironment;
use Dynoxide\Exception\StartException;
use Dynoxide\Server;

/**
 * The server for one PHPUnit process. ParaTest workers announce the start of
 * execution once per test file, so the start is attempted on the first
 * announcement only, whether it works or not: retrying would wait out the
 * timeout again for every file.
 *
 * @internal
 */
final class Session
{
    private const NOWHERE = 'http://127.0.0.1:1';

    private bool $attempted = false;

    private ?Server $server = null;

    private ?StartException $failure = null;

    /** @param \Closure(): Server $start */
    public function __construct(private readonly \Closure $start)
    {
    }

    /**
     * Starts the server and exports its environment. If it can't start, the
     * tests still run, so the environment is pointed at port 1 on loopback.
     * Nothing listens there by default, and every server asks for port 0,
     * which the system never answers with port 1, so any DynamoDB call stays
     * away from AWS and from another worker's server. The failure is thrown
     * for PHPUnit to report.
     */
    public function start(): void
    {
        if ($this->attempted) {
            return;
        }
        $this->attempted = true;

        try {
            $this->server = ($this->start)();
        } catch (\Throwable $e) {
            AwsEnvironment::export(self::NOWHERE);
            throw $this->failure = new StartException(
                "Dynoxide didn't start, so this run's DynamoDB calls go to " . self::NOWHERE . ', where nothing is listening. '
                . $e->getMessage(),
                0,
                $e,
            );
        }
        $this->server->exportEnv();
    }

    /**
     * The running server, or null before the start and after the stop.
     *
     * @throws StartException the failure, if the start failed
     */
    public function server(): ?Server
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->server;
    }

    public function stop(): void
    {
        $this->server?->stop();
        $this->server = null;
    }
}
