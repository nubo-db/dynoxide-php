<?php

declare(strict_types=1);

namespace Dynoxide;

use Dynoxide\Process\ServerProcess;

/**
 * A running Dynoxide server.
 *
 * The server keeps running if this object goes out of scope, and stops when
 * stop() is called or PHP exits. If PHP is killed outright or dies of a fatal
 * error, a Dynoxide 1.7.0 or later server stops by itself once it notices; an
 * older one keeps running.
 */
final class Server
{
    public const REGION = AwsEnvironment::REGION;
    public const ACCESS_KEY_ID = AwsEnvironment::ACCESS_KEY_ID;
    public const SECRET_ACCESS_KEY = AwsEnvironment::SECRET_ACCESS_KEY;

    public readonly string $region;
    public readonly string $accessKeyId;
    public readonly string $secretAccessKey;

    /** @internal Servers come from Dynoxide::start(). */
    public function __construct(
        public readonly string $endpoint,
        public readonly string $host,
        public readonly int $port,
        private readonly ServerProcess $process,
    ) {
        $this->region = self::REGION;
        $this->accessKeyId = self::ACCESS_KEY_ID;
        $this->secretAccessKey = self::SECRET_ACCESS_KEY;
    }

    /**
     * Settings for the AWS SDK's DynamoDbClient: new DynamoDbClient($server->clientConfig()).
     *
     * @return array{endpoint: string, region: string, version: string, credentials: array{key: string, secret: string}}
     */
    public function clientConfig(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'region' => $this->region,
            'version' => 'latest',
            'credentials' => ['key' => $this->accessKeyId, 'secret' => $this->secretAccessKey],
        ];
    }

    /**
     * The variables exportEnv() sets. AWS_ENDPOINT_URL is never among them:
     * it would send every AWS service to Dynoxide.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        return AwsEnvironment::for($this->endpoint);
    }

    /**
     * Points AWS SDKs in this process, and processes it starts, at this
     * server, through putenv(), $_ENV and $_SERVER. The credentials apply to
     * every AWS client that reads the environment, which is why this isn't
     * done on start.
     *
     * It also removes AWS_SESSION_TOKEN, so a real session token from CI
     * isn't sent along with the dummy credentials.
     */
    public function exportEnv(): void
    {
        AwsEnvironment::export($this->endpoint);
    }

    public function isRunning(): bool
    {
        return $this->process->exited() === null;
    }

    /**
     * Stops the server: closes its stdin and waits, then on Linux and macOS
     * sends SIGTERM and waits, then kills it. Safe to call more than once.
     *
     * @return int|null its exit status, or null if it was killed by a signal or couldn't be stopped.
     *         In a process forked after the start this does nothing and returns null.
     */
    public function stop(): ?int
    {
        $exited = $this->process->stop();

        return $exited === null || $exited['signaled'] ? null : $exited['exitcode'];
    }
}
