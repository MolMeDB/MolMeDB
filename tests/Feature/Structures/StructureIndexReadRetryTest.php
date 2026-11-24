<?php

use App\Support\BingoPostgresConnection;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/*
 * A search through the Bingo index that meets a write of another session
 * fails with "array: invalid index N (size=N)" although the index is sound,
 * so the connection repeats such a read. The tests use a connection of their
 * own (outside the transaction every test runs in) with a function that
 * fails the way Bingo does for its first calls.
 */

/**
 * A connection outside the test transaction whose pg_temp.search() fails with
 * the given message until it has been called $failures times.
 */
function connectionWithFailingSearch(int $failures, string $message = 'error: array: invalid index 7504 (size=7504)'): Connection
{
    config()->set('database.connections.index_reader', Arr::except(DB::connection()->getConfig(), 'name'));
    DB::purge('index_reader');

    $connection = DB::connection('index_reader');
    $connection->unprepared('CREATE TEMP SEQUENCE search_calls');
    $connection->unprepared(<<<SQL
        CREATE FUNCTION pg_temp.search() RETURNS integer AS \$\$
        BEGIN
            IF nextval('search_calls') <= {$failures} THEN
                RAISE EXCEPTION '{$message}' USING ERRCODE = 'XX000';
            END IF;

            RETURN 1;
        END;
        \$\$ LANGUAGE plpgsql
        SQL);

    return $connection;
}

function searchCalls(Connection $connection): int
{
    return (int) $connection->scalar('SELECT last_value FROM search_calls');
}

beforeEach(function () {
    Sleep::fake();
});

afterEach(function () {
    DB::purge('index_reader');
});

test('PostgreSQL connections repeat reads that meet a write to the structure index', function () {
    expect(DB::connection())->toBeInstanceOf(BingoPostgresConnection::class);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The structure index needs PostgreSQL with Bingo.');

test('a read that meets a write to the structure index is repeated until it succeeds', function () {
    $connection = connectionWithFailingSearch(failures: 2);

    expect($connection->scalar('SELECT pg_temp.search()'))->toBe(1)
        ->and(searchCalls($connection))->toBe(3);

    Sleep::assertSleptTimes(2);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The structure index needs PostgreSQL with Bingo.');

test('a read that keeps failing is given up after a few repetitions', function () {
    $connection = connectionWithFailingSearch(failures: 100);

    expect(fn () => $connection->scalar('SELECT pg_temp.search()'))
        ->toThrow(QueryException::class, 'array: invalid index 7504 (size=7504)');

    expect(searchCalls($connection))->toBe(1 + count(BingoPostgresConnection::RETRY_DELAYS));
    Sleep::assertSequence(array_map(
        fn (int $milliseconds): Sleep => Sleep::for($milliseconds)->milliseconds(),
        BingoPostgresConnection::RETRY_DELAYS,
    ));
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The structure index needs PostgreSQL with Bingo.');

test('another database error is not repeated', function () {
    $connection = connectionWithFailingSearch(failures: 100, message: 'error: bingo buffer: internal error: corrupted block 32365 data len is -8');

    expect(fn () => $connection->scalar('SELECT pg_temp.search()'))->toThrow(QueryException::class, 'corrupted block');

    expect(searchCalls($connection))->toBe(1);
    Sleep::assertNeverSlept();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The structure index needs PostgreSQL with Bingo.');

test('an error that only quotes the message of the index in its bindings is not repeated', function () {
    $connection = connectionWithFailingSearch(failures: 100, message: 'error: molecule: could not be read');

    expect(fn () => $connection->select('SELECT pg_temp.search() WHERE ?::text IS NOT NULL', ['error: array: invalid index 1 (size=1)']))
        ->toThrow(QueryException::class, 'could not be read');

    expect(searchCalls($connection))->toBe(1);
    Sleep::assertNeverSlept();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The structure index needs PostgreSQL with Bingo.');

test('a query builder and a cursor are repeated like any other read', function () {
    $connection = connectionWithFailingSearch(failures: 1);

    expect($connection->query()->selectRaw('pg_temp.search() AS found')->get()->pluck('found')->all())->toBe([1])
        ->and(searchCalls($connection))->toBe(2);

    $connection->unprepared('ALTER SEQUENCE search_calls RESTART');

    expect(iterator_to_array($connection->cursor('SELECT pg_temp.search() AS found')))->toHaveCount(1)
        ->and(searchCalls($connection))->toBe(2);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The structure index needs PostgreSQL with Bingo.');

test('a read inside a transaction is not repeated', function () {
    $connection = connectionWithFailingSearch(failures: 1);
    $connection->beginTransaction();

    try {
        expect(fn () => $connection->scalar('SELECT pg_temp.search()'))->toThrow(QueryException::class, 'array: invalid index');
    } finally {
        $connection->rollBack();
    }

    expect(searchCalls($connection))->toBe(1);
    Sleep::assertNeverSlept();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The structure index needs PostgreSQL with Bingo.');

test('a write is not repeated', function () {
    $connection = connectionWithFailingSearch(failures: 1);
    $connection->unprepared('CREATE TEMP TABLE found (value integer)');

    expect(fn () => $connection->insert('INSERT INTO found (value) VALUES (pg_temp.search())'))
        ->toThrow(QueryException::class, 'array: invalid index');

    expect(searchCalls($connection))->toBe(1)
        ->and($connection->scalar('SELECT count(*) FROM found'))->toBe(0);
    Sleep::assertNeverSlept();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The structure index needs PostgreSQL with Bingo.');
