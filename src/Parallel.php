<?php

declare(strict_types=1);

namespace Dynoxide;

use Dynoxide\Exception\DynoxideException;

/**
 * Rules for tests run in parallel by ParaTest, Pest --parallel included,
 * where every worker starts its own server.
 */
final class Parallel
{
    /**
     * The database file for this process: the one given, or in a ParaTest
     * worker that file with the worker's number appended, since two servers
     * can't share one file. ParaTest sets PARATEST in every worker;
     * TEST_TOKEN alone is a common name for unrelated secrets, so it counts
     * only alongside PARATEST.
     *
     *     Parallel::workerDatabase('var/test.db'); // 'var/test.db-3' in worker 3
     *
     * @throws DynoxideException in a ParaTest worker without a numeric
     *         TEST_TOKEN, as under --no-test-tokens, where every worker would
     *         share the one file
     */
    public static function workerDatabase(string $dbPath): string
    {
        $paratest = getenv('PARATEST');
        if ($paratest === false || $paratest === '') {
            return $dbPath;
        }
        $token = getenv('TEST_TOKEN');
        if ($token === false || preg_match('/^\d+$/', $token) !== 1) {
            throw new DynoxideException(
                'ParaTest is running without worker numbers (TEST_TOKEN), as with --no-test-tokens, so its workers '
                . "can't each have their own database file and would share {$dbPath}. Run without --no-test-tokens, "
                . 'or without a database file.',
            );
        }

        return "{$dbPath}-{$token}";
    }
}
