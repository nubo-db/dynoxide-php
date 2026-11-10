<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Support;

/**
 * Just enough of a DynamoDB client for the launcher tests, so they don't need
 * the AWS SDK. Dynoxide checks the authorisation header's shape, not its
 * signature.
 */
final class Dynamo
{
    /**
     * @param array<string, mixed> $body
     * @return array{int, array<string, mixed>} the status and the decoded response
     */
    public static function call(string $endpoint, string $operation, array $body = [], float $timeout = 5.0): array
    {
        $date = gmdate('Ymd\THis\Z');
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/x-amz-json-1.0',
                "X-Amz-Target: DynamoDB_20120810.{$operation}",
                "X-Amz-Date: {$date}",
                'Authorization: AWS4-HMAC-SHA256 Credential=dynoxide/' . substr($date, 0, 8)
                    . '/us-east-1/dynamodb/aws4_request, SignedHeaders=host;x-amz-date, Signature=' . str_repeat('0', 64),
            ]),
            'content' => json_encode($body === [] ? new \stdClass() : $body),
            'ignore_errors' => true,
            'timeout' => $timeout,
        ]]);
        $response = @file_get_contents($endpoint, false, $context);
        if ($response === false) {
            throw new \RuntimeException("{$operation} to {$endpoint} failed: " . (error_get_last()['message'] ?? 'no response'));
        }
        // $http_response_header is deprecated from PHP 8.5; its replacement arrived in 8.4.
        $headers = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : ($http_response_header ?? []);
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return [$status, json_decode($response, true) ?? []];
    }
}
