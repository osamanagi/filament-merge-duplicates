<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Registers and resets connections to the real engines used by engine-level
 * tests (locking, concurrency and migration portability).
 *
 * A checkout can only have one vendor tree, but it must be able to talk to
 * MySQL and PostgreSQL at the same time, on genuinely separate connections.
 * Engines that are unreachable are reported so tests skip instead of passing
 * silently.
 */
final class EngineConnections
{
    public const ENGINES = ['mysql', 'postgres'];

    /**
     * Registers `{engine}`, `{engine}_writer` and `{engine}_probe` against the
     * same database.
     */
    public static function configure(string $engine): void
    {
        self::reset($engine);

        $config = self::config($engine);

        foreach (self::names($engine) as $name) {
            config()->set("database.connections.{$name}", $config);
        }

        // The probe connection must fail fast instead of hanging a suite.
        if ($engine === 'mysql') {
            DB::connection("{$engine}_probe")->statement('SET SESSION innodb_lock_wait_timeout = 1');

            return;
        }

        DB::connection("{$engine}_probe")->statement('SET statement_timeout = 1000');
        DB::connection("{$engine}_probe")->statement('SET lock_timeout = 1000');
    }

    /**
     * Rolls back anything still open and drops the connection.
     *
     * Leaving a transaction open keeps table metadata locks on MySQL and an
     * aborted snapshot on PostgreSQL, which makes the next DROP TABLE wait
     * forever.
     */
    public static function reset(string $engine): void
    {
        foreach (self::names($engine) as $name) {
            try {
                $connection = DB::connection($name);

                while ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            } catch (Throwable) {
                // The connection may never have been resolved; nothing to release.
            }

            DB::purge($name);
        }
    }

    public static function reachable(string $engine): bool
    {
        self::configure($engine);

        try {
            DB::connection($engine)->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    public static function names(string $engine): array
    {
        return [$engine, "{$engine}_writer", "{$engine}_probe"];
    }

    /**
     * @return array<string, mixed>
     */
    public static function config(string $engine): array
    {
        if ($engine === 'mysql') {
            return [
                'driver' => 'mysql',
                'host' => env('SPIKE_MYSQL_HOST', '127.0.0.1'),
                'port' => env('SPIKE_MYSQL_PORT', '3306'),
                'database' => env('SPIKE_MYSQL_DATABASE', 'merge_duplicates'),
                'username' => env('SPIKE_MYSQL_USERNAME', 'merge'),
                'password' => env('SPIKE_MYSQL_PASSWORD', 'secret'),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'engine' => 'InnoDB',
            ];
        }

        return [
            'driver' => 'pgsql',
            'host' => env('SPIKE_POSTGRES_HOST', '127.0.0.1'),
            'port' => env('SPIKE_POSTGRES_PORT', '5432'),
            'database' => env('SPIKE_POSTGRES_DATABASE', 'merge_duplicates'),
            'username' => env('SPIKE_POSTGRES_USERNAME', 'merge'),
            'password' => env('SPIKE_POSTGRES_PASSWORD', 'secret'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];
    }
}
