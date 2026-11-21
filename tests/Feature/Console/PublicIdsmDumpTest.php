<?php

use App\Console\Commands\Database\BackupDbIdsm;
use App\Models\Config;
use App\Models\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/*
 * The IDSM dump is downloadable by anyone, so it may hold published data
 * only. Every table of the database must be classified as public or private,
 * so a new table cannot slip into the dump without a decision.
 */

/**
 * @return array<int, string> schema.table of every base table
 */
function databaseTables(): array
{
    return collect(DB::select(<<<'SQL'
        select table_schema || '.' || table_name as name
        from information_schema.tables
        where table_type = 'BASE TABLE' and table_schema not in ('pg_catalog', 'information_schema')
        SQL))
        ->pluck('name')
        ->sort()
        ->values()
        ->all();
}

test('every database table is classified for the public dump', function () {
    $unclassified = array_values(array_diff(databaseTables(), BackupDbIdsm::PUBLIC_TABLES, BackupDbIdsm::PRIVATE_TABLES));

    expect($unclassified)->toBe([])
        ->and(array_intersect(BackupDbIdsm::PUBLIC_TABLES, BackupDbIdsm::PRIVATE_TABLES))->toBe([]);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'needs PostgreSQL');

test('no public dump table holds contact data, credentials or tokens', function () {
    $personal = collect(BackupDbIdsm::PUBLIC_TABLES)
        ->flatMap(function (string $table): array {
            [$schema, $name] = explode('.', $table);

            return collect(DB::select('select column_name from information_schema.columns where table_schema = ? and table_name = ?', [$schema, $name]))
                ->pluck('column_name')
                ->filter(fn (string $column): bool => preg_match('/email|ip_address|user_agent|password|token|secret|auth|endpoint|private_key|passphrase/i', $column) === 1)
                ->map(fn (string $column): string => "{$table}.{$column}")
                ->all();
        })
        ->values()
        ->all();

    expect($personal)->toBe([]);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'needs PostgreSQL');

/**
 * Runs a PostgreSQL client tool against the test database server.
 *
 * @param  array<int, string>  $arguments
 */
function runPostgresTool(string $tool, array $arguments, ?string $input = null): Process
{
    $connection = config('database.connections.pgsql');
    $process = new Process(
        [$tool, '-h', (string) $connection['host'], '-p', (string) $connection['port'], '-U', (string) $connection['username'], ...$arguments],
        null,
        ['PGPASSWORD' => (string) $connection['password']],
        $input,
        120,
    );
    $process->run();

    return $process;
}

test('the public dump restores into an empty database without errors', function () {
    $dump = runPostgresTool('pg_dump', [...BackupDbIdsm::tableArguments(), (string) config('database.connections.pgsql.database')]);
    expect($dump->isSuccessful())->toBeTrue($dump->getErrorOutput());

    $lines = preg_split('/(?<=\n)/', $dump->getOutput(), -1, PREG_SPLIT_NO_EMPTY);
    $restorable = implode('', iterator_to_array(BackupDbIdsm::restorableDump($lines), false));

    $database = 'idsm_restore_check_'.getmypid();
    runPostgresTool('psql', ['-d', 'postgres', '-c', "DROP DATABASE IF EXISTS {$database}"]);
    runPostgresTool('psql', ['-d', 'postgres', '-c', "CREATE DATABASE {$database}"]);

    try {
        $restore = runPostgresTool('psql', ['-d', $database, '-v', 'ON_ERROR_STOP=1', '-q'], $restorable);
        expect($restore->isSuccessful())->toBeTrue($restore->getErrorOutput());

        $tables = runPostgresTool('psql', ['-d', $database, '-At', '-c', "select table_schema || '.' || table_name from information_schema.tables where table_type = 'BASE TABLE' and table_schema = 'public' order by 1"]);
        expect(array_filter(explode("\n", trim($tables->getOutput()))))->toEqualCanonicalizing(BackupDbIdsm::PUBLIC_TABLES);
    } finally {
        runPostgresTool('psql', ['-d', 'postgres', '-c', "DROP DATABASE IF EXISTS {$database}"]);
    }
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql'
    || (new ExecutableFinder)->find('pg_dump') === null
    || (new ExecutableFinder)->find('psql') === null, 'needs PostgreSQL and its client tools');

test('the restorable dump drops the Bingo index and foreign keys to private tables only', function () {
    $dump = <<<'SQL'
        SET client_encoding = 'UTF8';
        SET transaction_timeout = 0;
        CREATE TABLE public.structures (id bigint, identifier public.citext);

        --
        -- Data for Name: structures; Type: TABLE DATA; Schema: public; Owner: postgres
        --

        COPY public.structures (id, identifier) FROM stdin;
        1	MM00001
        \.

        --
        -- Name: structures_identifier_index; Type: INDEX; Schema: public; Owner: postgres
        --

        CREATE INDEX structures_identifier_index ON public.structures USING btree (identifier);

        --
        -- Name: structures_canonical_smiles_bingo_index; Type: INDEX; Schema: public; Owner: postgres
        --

        CREATE INDEX structures_canonical_smiles_bingo_index ON public.structures USING bingo_idx (canonical_smiles bingo.molecule);

        --
        -- Name: datasets datasets_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
        --

        ALTER TABLE ONLY public.datasets
            ADD CONSTRAINT datasets_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;

        --
        -- Name: interactions_passive interactions_passive_structure_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
        --

        ALTER TABLE ONLY public.interactions_passive
            ADD CONSTRAINT interactions_passive_structure_id_foreign FOREIGN KEY (structure_id) REFERENCES public.structures(id);

        --
        -- PostgreSQL database dump complete
        --
        SQL;

    $result = implode('', iterator_to_array(BackupDbIdsm::restorableDump(preg_split('/(?<=\n)/', $dump."\n")), false));

    expect($result)->toStartWith('CREATE EXTENSION IF NOT EXISTS citext WITH SCHEMA public;')
        ->and($result)->toContain("COPY public.structures (id, identifier) FROM stdin;\n1\tMM00001\n\\.")
        ->and($result)->toContain('structures_identifier_index ON public.structures USING btree')
        ->and($result)->toContain('interactions_passive_structure_id_foreign FOREIGN KEY (structure_id) REFERENCES public.structures(id);')
        ->and($result)->toContain('-- PostgreSQL database dump complete')
        ->and($result)->toContain("SET client_encoding = 'UTF8';")
        ->and($result)->not->toContain('transaction_timeout')
        ->and($result)->not->toContain('bingo_idx')
        ->and($result)->not->toContain('REFERENCES public.users');
});

test('the dump is skipped for a week after the last one and made again after', function () {
    Config::set('db_backup_idsm_last', now()->subDays(3)->toDateTimeString());
    $this->artisan(BackupDbIdsm::class)->expectsOutputToContain('skipping')->assertSuccessful();

    // A week later it goes on (and here stops at the backup filesystem).
    Config::set('db_backup_idsm_last', now()->subDays(8)->toDateTimeString());
    Filesystem::query()->where('type', Filesystem::TYPE_DB_PUBLIC_BACKUP)->delete();
    $this->artisan(BackupDbIdsm::class)->expectsOutputToContain('No backup filesystem configured')->assertFailed();
});

test('the latest public dump is described and served', function () {
    $filesystem = Filesystem::query()->where('type', Filesystem::TYPE_DB_PUBLIC_BACKUP)->firstOrFail();
    config()->set("filesystems.disks.{$filesystem->systemName}", ['driver' => 'local']);
    Storage::fake($filesystem->systemName);
    Config::set('db_backup_idsm_last', '2026-11-15 03:00:00');
    Storage::disk($filesystem->systemName)->put(BackupDbIdsm::datePath('2026-11-15').'backup-idsm-2026-11-15.sql.gz', 'gzipped-dump');

    $this->getJson('/api/dump/idsm/info')
        ->assertOk()
        ->assertJsonPath('last_backup', '2026-11-15')
        ->assertJsonPath('is_available', true);

    $response = $this->get('/api/dump/idsm/download')->assertOk();

    expect($response->streamedContent())->toBe('gzipped-dump')
        ->and($response->headers->get('Content-Disposition'))->toContain('backup-idsm-2026-11-15.sql.gz');
});

test('a missing public dump answers not found', function () {
    $filesystem = Filesystem::query()->where('type', Filesystem::TYPE_DB_PUBLIC_BACKUP)->firstOrFail();
    config()->set("filesystems.disks.{$filesystem->systemName}", ['driver' => 'local']);
    Storage::fake($filesystem->systemName);
    Config::set('db_backup_idsm_last', '2026-11-15 03:00:00');

    $this->getJson('/api/dump/idsm/info')->assertNotFound()->assertJsonPath('is_available', false);
    $this->get('/api/dump/idsm/download')->assertNotFound();
});
