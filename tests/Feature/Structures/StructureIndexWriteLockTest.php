<?php

use App\Models\Structure;
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

test('a session waits with its structure while another one is storing its own', function (Closure $write) {
    $existing = Structure::query()->create(['canonical_smiles' => 'CCO']);

    // The transaction of the test holds the lock from its first write on.
    $write($existing);

    $other = otherDatabaseSession();

    expect(fn () => $other->exec("INSERT INTO structures (canonical_smiles) VALUES ('c1ccccc1')"))
        ->toThrow(PDOException::class, 'lock timeout');
})->with([
    'an insert' => [fn (Structure $existing) => Structure::query()->create(['canonical_smiles' => 'CCN'])],
    'an update' => [fn (Structure $existing) => $existing->update(['canonical_smiles' => 'CCC'])],
])->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The structure index needs PostgreSQL with Bingo.');

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
