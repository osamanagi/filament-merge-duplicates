<?php

namespace Nagi\FilamentMergeDuplicates\Merging;

use Illuminate\Database\QueryException;

/**
 * Decides which database failures may be retried.
 *
 * Only concurrency control failures are retried: a serialization failure or a
 * deadlock means another transaction won the race, and re-running from a fresh
 * read is safe. A constraint violation or a missing row is never retried,
 * because the same statement would fail again and hiding it behind three
 * attempts would only delay the honest answer.
 */
final class RetryPolicy
{
    /**
     * Driver-specific error numbers understood across MySQL and PostgreSQL.
     *
     * @var list<int>
     */
    private const DEADLOCK_NUMBERS = [1213, 1205, 40001];

    /**
     * SQLSTATE classes that mean "another transaction got in the way".
     *
     * @var list<string>
     */
    private const RETRYABLE_STATES = ['40001', '40P01', '55P03'];

    public function __construct(private readonly int $maxAttempts = 3) {}

    public function maxAttempts(): int
    {
        return max(1, $this->maxAttempts);
    }

    public function isRetryable(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo;

        if (! is_array($errorInfo)) {
            return false;
        }

        $state = isset($errorInfo[0]) && is_string($errorInfo[0]) ? $errorInfo[0] : null;
        $number = $errorInfo[1] ?? null;

        if ($state !== null && in_array($state, self::RETRYABLE_STATES, true)) {
            return true;
        }

        return is_int($number) && in_array($number, self::DEADLOCK_NUMBERS, true);
    }
}
