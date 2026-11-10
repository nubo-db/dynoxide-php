<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Support;

/**
 * Used by the tests in tests/Fixtures/projects: records what one test saw,
 * as a JSON line in the file FIXTURE_REPORT names, for the test that ran the
 * project to check.
 */
final class FixtureReport
{
    /** @return array{label: string, pid: int, token: string, endpoint: string, status: int, tables: list<string>} */
    public static function record(string $label, ?string $table = null): array
    {
        $endpoint = (string) getenv('AWS_ENDPOINT_URL_DYNAMODB');
        $status = 0;
        $tables = [];
        try {
            if ($table !== null) {
                Dynamo::call($endpoint, 'CreateTable', [
                    'TableName' => $table,
                    'AttributeDefinitions' => [['AttributeName' => 'id', 'AttributeType' => 'S']],
                    'KeySchema' => [['AttributeName' => 'id', 'KeyType' => 'HASH']],
                    'BillingMode' => 'PAY_PER_REQUEST',
                ], 2.0);
            }
            [$status, $body] = Dynamo::call($endpoint, 'ListTables', [], 2.0);
            $tables = $body['TableNames'] ?? [];
        } catch (\RuntimeException) {
            // Nothing listening: status stays 0.
        }
        $report = [
            'label' => $label,
            'pid' => getmypid(),
            'token' => (string) getenv('TEST_TOKEN'),
            'endpoint' => $endpoint,
            'status' => $status,
            'tables' => $tables,
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
}
