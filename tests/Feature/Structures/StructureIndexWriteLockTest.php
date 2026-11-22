<?php

use App\Models\Structure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The Bingo index of structures breaks when two sessions write to it at the
 * same time (MolMeDB/MolMeDB#17), so a trigger lets one transaction at a time
 * insert or update structures. The tests open a second connection to the
 * database to be the other session.
 */

function otherDatabaseSession(): PDO
{
    $config = DB::connection()->getConfig();

    $session = new PDO(
        "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $session->exec("SET lock_timeout = '300ms'");

    return $session;
}

test('a session waits with its structure while another one is storing its own', function () {
    // The transaction of the test holds the lock from its first write on.
    Structure::query()->create(['canonical_smiles' => 'CCO']);

    $other = otherDatabaseSession();
    $other->beginTransaction();

    try {
        expect(fn () => $other->exec("INSERT INTO structures (canonical_smiles) VALUES ('c1ccccc1')"))
            ->toThrow(PDOException::class, 'lock timeout');
    } finally {
        $other->rollBack();
    }
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The structure index needs PostgreSQL with Bingo.');

test('an update of structures takes the lock like an insert does', function () {
    $other = otherDatabaseSession();
    $other->beginTransaction();
    $other->exec('UPDATE structures SET canonical_smiles = canonical_smiles WHERE id < 0');

    try {
        DB::statement("SET LOCAL lock_timeout = '300ms'");

        // In a savepoint, so the failed statement does not end the transaction of the test.
        expect(fn () => DB::transaction(fn () => Structure::query()->create(['canonical_smiles' => 'CCO'])))
            ->toThrow(QueryException::class, 'lock timeout');
    } finally {
        $other->rollBack();
    }

    expect(Structure::query()->create(['canonical_smiles' => 'CCO'])->exists)->toBeTrue();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The structure index needs PostgreSQL with Bingo.');

test('a session without a timeout of its own gives up after half a minute and keeps its setting', function () {
    Structure::query()->create(['canonical_smiles' => 'CCO']);

    expect(DB::scalar("SELECT current_setting('lock_timeout')"))->toBe('0')
        ->and(DB::scalar("SELECT prosrc FROM pg_proc WHERE proname = 'structures_bingo_write_lock'"))->toContain("'30s'");
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The structure index needs PostgreSQL with Bingo.');

test('reading structures and writing other tables do not wait for the lock', function () {
    Structure::query()->create(['canonical_smiles' => 'CCO']);

    $other = otherDatabaseSession();

    expect($other->query('SELECT count(*) FROM structures')->fetchColumn())->toBe(0)
        ->and($other->exec('DELETE FROM structures WHERE id < 0'))->toBe(0);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The structure index needs PostgreSQL with Bingo.');

test('a failed write releases the lock with its transaction', function () {
    $other = otherDatabaseSession();

    $other->beginTransaction();
    $other->exec("INSERT INTO structures (canonical_smiles) VALUES ('c1ccccc1')");
    $other->rollBack();

    expect(Structure::query()->create(['canonical_smiles' => 'CCO'])->exists)->toBeTrue();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The structure index needs PostgreSQL with Bingo.');
