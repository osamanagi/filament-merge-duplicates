<?php

use Illuminate\Database\QueryException;
use Nagi\FilamentMergeDuplicates\Merging\RetryPolicy;

/*
|--------------------------------------------------------------------------
| M09 - retry classification
|--------------------------------------------------------------------------
|
| The retry loop itself is proven against a real server engine in
| tests/Execution. What is proven here is that the classification understands
| the error shapes both supported drivers actually produce, including the ones
| that must never be retried.
|*/

/**
 * Builds the failure the way the framework builds it: the driver's error detail
 * travels on the PDO exception that caused it.
 *
 * @param  array{0: mixed, 1: mixed, 2?: mixed}  $errorInfo
 */
function queryFailure(array $errorInfo, string $driver = 'mysql'): QueryException
{
    $previous = new PDOException('driver failure', 0);
    $previous->errorInfo = $errorInfo;

    return new QueryException(
        $driver,
        'update "fixture_contacts" set "reference" = ? where "id" = ?',
        ['TWO', 1],
        $previous,
    );
}

it('treats driver concurrency failures as retryable', function (array $errorInfo, string $driver) {
    expect((new RetryPolicy)->isRetryable(queryFailure($errorInfo, $driver)))->toBeTrue();
})->with([
    'mysql lock wait timeout' => [['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'], 'mysql'],
    'mysql deadlock' => [['40001', 1213, 'Deadlock found when trying to get lock'], 'mysql'],
    'postgres serialization failure' => [['40001', 7, 'could not serialize access due to concurrent update'], 'pgsql'],
    'postgres deadlock detected' => [['40P01', 7, 'deadlock detected'], 'pgsql'],
    'postgres lock timeout' => [['55P03', 7, 'canceling statement due to lock timeout'], 'pgsql'],
]);

it('never retries a failure that would fail again', function (array $errorInfo, string $driver) {
    expect((new RetryPolicy)->isRetryable(queryFailure($errorInfo, $driver)))->toBeFalse();
})->with([
    'duplicate unique key' => [['23000', 1062, 'Duplicate entry'], 'mysql'],
    'unique violation postgres' => [['23505', 7, 'duplicate key value violates unique constraint'], 'pgsql'],
    'unknown table' => [['42S02', 1146, "Table 'x' doesn't exist"], 'mysql'],
    'not null violation' => [['23000', 1048, 'Column cannot be null'], 'mysql'],
    'no driver information' => [['00000', 0, 'unknown'], 'mysql'],
]);

it('bounds the number of attempts it will make', function () {
    expect((new RetryPolicy(3))->maxAttempts())->toBe(3)
        ->and((new RetryPolicy)->maxAttempts())->toBe(3)
        ->and((new RetryPolicy(0))->maxAttempts())->toBe(1);
});
