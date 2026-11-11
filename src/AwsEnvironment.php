<?php

declare(strict_types=1);

namespace Dynoxide;

/**
 * The environment variables that point AWS SDKs at a DynamoDB endpoint.
 *
 * @internal
 */
final class AwsEnvironment
{
    public const REGION = 'us-east-1';
    public const ACCESS_KEY_ID = 'dynoxide';
    public const SECRET_ACCESS_KEY = 'dynoxide';

    /**
     * AWS_ENDPOINT_URL is never among them: it would send every AWS service to
     * Dynoxide.
     *
     * @return array<string, string>
     */
    public static function for(string $endpoint): array
    {
        return [
            'AWS_ENDPOINT_URL_DYNAMODB' => $endpoint,
            'AWS_ACCESS_KEY_ID' => self::ACCESS_KEY_ID,
            'AWS_SECRET_ACCESS_KEY' => self::SECRET_ACCESS_KEY,
            'AWS_REGION' => self::REGION,
            'AWS_DEFAULT_REGION' => self::REGION,
            // Laravel's DynamoDB cache and session drivers read this one.
            'DYNAMODB_ENDPOINT' => $endpoint,
            // Set to true, here or in the shared config file, this makes the
            // SDKs ignore AWS_ENDPOINT_URL_DYNAMODB and call AWS. The SDKs read
            // the environment before the config file, so false wins over both.
            'AWS_IGNORE_CONFIGURED_ENDPOINT_URLS' => 'false',
        ];
    }

    /**
     * Sets them through putenv(), $_ENV and $_SERVER, and removes
     * AWS_SESSION_TOKEN so a real session token isn't sent with the dummy
     * credentials.
     */
    public static function export(string $endpoint): void
    {
        foreach (self::for($endpoint) as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
        putenv('AWS_SESSION_TOKEN');
        unset($_ENV['AWS_SESSION_TOKEN'], $_SERVER['AWS_SESSION_TOKEN']);
    }
}
