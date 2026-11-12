<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Support;

use Dynoxide\PHPUnit\Extension;

/**
 * Used by the tests in tests/Fixtures/projects: records what one test saw,
 * as a JSON line in the file FIXTURE_REPORT names, for the test that ran the
 * project to check.
 */
final class FixtureReport
{
    /**
     * Requests get ten seconds, since a first request to a server on a cold
     * Windows runner can be slow; a refused connection still fails at once.
     *
     * @return array{label: string, pid: int, token: string, endpoint: string, status: int, error: string, tables: list<string>, extension: string}
     */
    public static function record(string $label, ?string $table = null): array
    {
        $endpoint = (string) getenv('AWS_ENDPOINT_URL_DYNAMODB');
        $status = 0;
        $error = '';
        $tables = [];
        try {
            if ($table !== null) {
                Dynamo::call($endpoint, 'CreateTable', [
                    'TableName' => $table,
                    'AttributeDefinitions' => [['AttributeName' => 'id', 'AttributeType' => 'S']],
                    'KeySchema' => [['AttributeName' => 'id', 'KeyType' => 'HASH']],
                    'BillingMode' => 'PAY_PER_REQUEST',
                ], 10.0);
            }
            [$status, $body] = Dynamo::call($endpoint, 'ListTables', [], 10.0);
            $tables = $body['TableNames'] ?? [];
        } catch (\RuntimeException $e) {
            // Nothing answered: status stays 0, and the reason goes in the report.
            $error = $e->getMessage();
        }
        $report = [
            'label' => $label,
            'pid' => getmypid(),
            'token' => (string) getenv('TEST_TOKEN'),
            'endpoint' => $endpoint,
            'status' => $status,
            'error' => $error,
            'tables' => $tables,
            'extension' => self::extension(),
        ];
        file_put_contents((string) getenv('FIXTURE_REPORT'), json_encode($report) . "\n", FILE_APPEND | LOCK_EX);
        // For the hard-kill tests: hold the test open so the runner can be
        // killed while it's inside one.
        $hold = (float) getenv('FIXTURE_HOLD');
        if ($hold > 0) {
            usleep((int) ($hold * 1_000_000));
        }

        return $report;
    }

    /** What Extension::server() gives: the server's endpoint, "none", or the class it threw. */
    private static function extension(): string
    {
        try {
            return Extension::server()?->endpoint ?? 'none';
        } catch (\Throwable $e) {
            return 'threw ' . $e::class;
        }
    }
}
