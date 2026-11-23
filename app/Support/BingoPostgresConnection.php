<?php

namespace App\Support;

use Closure;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Sleep;

/**
 * A search through the Bingo index of structures (similarity, substructure,
 * exact match) that runs at the moment another session writes to the index
 * fails with "error: array: invalid index N (size=N)": the reader sees the
 * index half way through the write. The index is not damaged and the same
 * query succeeds right after, so such a read is repeated a few times before
 * the error is let through.
 *
 * Only a read outside a transaction is repeated: inside one, PostgreSQL has
 * already aborted the transaction.
 */
class BingoPostgresConnection extends PostgresConnection
{
    /**
     * Milliseconds waited before each repetition of a read that hit a write
     * to the index.
     *
     * @var array<int, int>
     */
    public const RETRY_DELAYS = [50, 150, 400, 1000];

    /**
     * @param  string  $query
     * @param  array<int|string, mixed>  $bindings
     *
     * @throws QueryException
     */
    protected function handleQueryException(QueryException $e, $query, $bindings, Closure $callback): mixed
    {
        if ($this->transactions >= 1 || ! self::isReadHitByIndexWrite($e, (string) $query)) {
            return parent::handleQueryException($e, $query, $bindings, $callback);
        }

        foreach (self::RETRY_DELAYS as $attempt => $milliseconds) {
            Sleep::for($milliseconds)->milliseconds();

            try {
                return $this->runQueryCallback($query, $bindings, $callback);
            } catch (QueryException $repeated) {
                if (! self::isReadHitByIndexWrite($repeated, (string) $query)) {
                    return parent::handleQueryException($repeated, $query, $bindings, $callback);
                }

                $e = $repeated;
            }
        }

        throw $e;
    }

    /**
     * Judged by the message of the database driver alone: the message of the
     * query exception also holds the SQL with its bindings, which come from
     * the visitor.
     */
    public static function isReadHitByIndexWrite(QueryException $e, string $query): bool
    {
        return preg_match('/^\s*(select|with)\b/i', $query) === 1
            && (string) $e->getCode() === 'XX000'
            && preg_match('/error: array: invalid index -?\d+ \(size=\d+\)/', (string) $e->getPrevious()?->getMessage()) === 1;
    }
}
