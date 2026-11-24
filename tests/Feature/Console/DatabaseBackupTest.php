<?php

use App\Console\Commands\Database\BackupDbIdsm;
use App\Models\Config;
use App\Models\Filesystem;
use App\Models\SshCredential;
use Carbon\Carbon;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;

/*
 * The database backup commands (db:dump, db:dump-predictions, db:dump-idsm):
 * pg_dump is started with the password in its environment, its output is
 * gzipped and uploaded to the backup filesystem under a dated path, an older
 * backup of the same day is replaced and every failure ends the run with an
 * error and no upload. The IDSM table selection and skip logic are covered
 * by PublicIdsmDumpTest.
 */

beforeEach(function () {
    Process::preventStrayProcesses();
});

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/molmedb-db-backup-test-'.getmypid().'-*') ?: [] as $root) {
        File::deleteDirectory($root);
    }
});

/**
 * Points the backup filesystem of the given type to an empty local directory
 * of this test process and returns its disk name.
 */
function useLocalDatabaseBackupDisk(int $type): string
{
    $diskName = Filesystem::query()->where('type', $type)->firstOrFail()->systemName;
    $root = sys_get_temp_dir().'/molmedb-db-backup-test-'.getmypid().'-'.$type;

    File::deleteDirectory($root);
    config()->set("filesystems.disks.{$diskName}", ['driver' => 'local', 'root' => $root]);
    Storage::forgetDisk($diskName);

    return $diskName;
}

/**
 * Today's backup file of the given prefix, as the commands name it.
 */
function todaysDatabaseBackupPath(string $prefix): string
{
    return date('Y/m/d/').$prefix.'-'.date('Y-m-d').'.sql.gz';
}

function readDatabaseBackup(string $diskName, string $path): string
{
    return (string) gzdecode((string) Storage::disk($diskName)->get($path));
}

/**
 * Makes every upload to the disk fail as a remote filesystem would (put returns false).
 */
function failDatabaseBackupUploads(string $diskName): void
{
    $disk = Mockery::mock(Storage::disk($diskName));
    $disk->shouldReceive('put')->andReturnFalse();
    Storage::set($diskName, $disk);
}

/**
 * Lets the predictions connection look like a PostgreSQL server; the commands only read its configuration.
 */
function configurePredictionsBackupConnection(): void
{
    config()->set('database.connections.predictions', [
        ...config('database.connections.predictions'),
        'driver' => 'pgsql',
        'host' => 'predictions-db.internal',
        'port' => 6543,
        'database' => 'predictions_prod',
        'username' => 'predictions_user',
        'password' => 'predictions-secret',
    ]);
}

/**
 * The connection options pg_dump gets for the main database.
 *
 * @return array<int, string>
 */
function mainDatabasePgDumpOptions(): array
{
    $connection = config('database.connections.pgsql');

    return ['pg_dump', '-U', (string) $connection['username'], '-p', (string) $connection['port'], '-h', (string) $connection['host']];
}

test('the full backup dumps the database without credential data, appends anonymised credentials and uploads it gzipped', function () {
    $diskName = useLocalDatabaseBackupDisk(Filesystem::TYPE_DB_FULL_BACKUP);
    config()->set('database.connections.pgsql.password', 'main-db-secret');
    SshCredential::query()->create([
        'name' => 'Backup server',
        'username' => 'backup',
        'type' => SshCredential::AUTH_TYPE_PASSWORD,
        'password' => 'ssh-secret',
        'private_key' => 'PRIVATE KEY',
        'passphrase' => 'key-passphrase',
    ]);
    $credentialsSeenByDump = [];

    Process::fake(function (PendingProcess $process) use (&$credentialsSeenByDump) {
        if (! in_array('--data-only', $process->command, true)) {
            return Process::describe()->output("CREATE TABLE public.ssh_credentials (id bigint);\nCOPY public.structures (id) FROM stdin;\n1\n\\.");
        }

        $exportTable = str_replace('--table=public.', '', collect($process->command)->first(fn (string $argument): bool => str_starts_with($argument, '--table=')));
        $credentialsSeenByDump = DB::table($exportTable)->get()->map(fn (object $row): array => (array) $row)->all();

        return Process::describe()->output("COPY public.{$exportTable} (id, name, username, type, password, private_key, passphrase, created_by, created_at, updated_at) FROM stdin;\n1\tBackup server\tbackup\tpassword\t\\N\t\\N\t\\N\t\\N\t2026-01-01\t2026-01-01\n\\.");
    });

    $this->artisan('db:dump')
        ->expectsOutput('Backup created and uploaded.')
        ->assertSuccessful();

    $database = (string) config('database.connections.pgsql.database');
    $exportTable = null;

    Process::assertRanTimes(fn (): bool => true, 2);
    Process::assertRan(function (PendingProcess $process) use ($database, &$exportTable): bool {
        $exclude = collect($process->command)->first(fn (string $argument): bool => str_starts_with($argument, '--exclude-table=public.ssh_credentials_export_'));

        if ($exclude === null) {
            return false;
        }

        $exportTable = substr($exclude, strlen('--exclude-table=public.'));

        return $process->command === [...mainDatabasePgDumpOptions(), '--exclude-table-data=public.ssh_credentials', $exclude, $database]
            && $process->environment === ['PGPASSWORD' => 'main-db-secret']
            && $process->timeout === null;
    });
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [...mainDatabasePgDumpOptions(), '--data-only', '--table=public.'.$exportTable, $database]
        && $process->environment === ['PGPASSWORD' => 'main-db-secret']);
    Process::assertDidntRun(fn (PendingProcess $process): bool => in_array('main-db-secret', $process->command, true));

    expect($credentialsSeenByDump)->toHaveCount(1)
        ->and($credentialsSeenByDump[0])->toMatchArray([
            'name' => 'Backup server',
            'username' => 'backup',
            'password' => null,
            'private_key' => null,
            'passphrase' => null,
            'created_by' => null,
        ]);

    $backup = readDatabaseBackup($diskName, todaysDatabaseBackupPath('backup-base'));

    expect($backup)->toStartWith("CREATE TABLE public.ssh_credentials (id bigint);\nCOPY public.structures (id) FROM stdin;")
        ->and($backup)->toContain("\n\nCOPY public.ssh_credentials (id, name, username, type, password, private_key, passphrase, created_by, created_at, updated_at) FROM stdin;\n1\tBackup server")
        ->and($backup)->not->toContain('ssh_credentials_export_')
        ->and($backup)->not->toContain('ssh-secret')
        ->and(DB::table('information_schema.tables')->where('table_name', 'like', 'ssh_credentials_export_%')->exists())->toBeFalse();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'needs PostgreSQL');

test('the full backup replaces a backup of the same day', function () {
    $diskName = useLocalDatabaseBackupDisk(Filesystem::TYPE_DB_FULL_BACKUP);
    Storage::disk($diskName)->put(todaysDatabaseBackupPath('backup-base'), gzencode('old dump'));
    Process::fake(['*' => Process::describe()->output('new dump')]);

    $this->artisan('db:dump')
        ->expectsOutput('# Old backup deleted.')
        ->assertSuccessful();

    expect(readDatabaseBackup($diskName, todaysDatabaseBackupPath('backup-base')))->toStartWith('new dump');
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'needs PostgreSQL');

test('a failed pg_dump fails the full backup, uploads nothing and drops the anonymisation table', function () {
    $diskName = useLocalDatabaseBackupDisk(Filesystem::TYPE_DB_FULL_BACKUP);
    Process::fake(['*' => Process::describe()->errorOutput('pg_dump: error: connection refused')->exitCode(1)]);

    $this->artisan('db:dump')
        ->expectsOutput('pg_dump: error: connection refused')
        ->assertFailed();

    Process::assertRanTimes(fn (): bool => true, 1);
    expect(Storage::disk($diskName)->exists(todaysDatabaseBackupPath('backup-base')))->toBeFalse()
        ->and(DB::table('information_schema.tables')->where('table_name', 'like', 'ssh_credentials_export_%')->exists())->toBeFalse();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'needs PostgreSQL');

test('a pg_dump failing without error output reports a generic error', function () {
    useLocalDatabaseBackupDisk(Filesystem::TYPE_DB_FULL_BACKUP);
    Process::fake(['*' => Process::describe()->exitCode(2)]);

    $this->artisan('db:dump')
        ->expectsOutput('pg_dump failed.')
        ->assertFailed();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'needs PostgreSQL');

test('a failed upload fails the full backup', function () {
    $diskName = useLocalDatabaseBackupDisk(Filesystem::TYPE_DB_FULL_BACKUP);
    failDatabaseBackupUploads($diskName);
    Process::fake(['*' => Process::describe()->output('dump')]);

    $this->artisan('db:dump')
        ->expectsOutput('Could not upload backup to target filesystem.')
        ->assertFailed();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'needs PostgreSQL');

test('a backup without a backup filesystem fails before dumping', function (string $command, int $type) {
    configurePredictionsBackupConnection();
    Filesystem::query()->where('type', $type)->delete();
    Process::fake();

    $this->artisan($command)
        ->expectsOutput('No backup filesystem configured! Aborting.')
        ->assertFailed();

    Process::assertNothingRan();
})->with([
    'full' => ['db:dump', Filesystem::TYPE_DB_FULL_BACKUP],
    'predictions' => ['db:dump-predictions', Filesystem::TYPE_DB_PREDICTIONS_BACKUP],
    'idsm' => ['db:dump-idsm', Filesystem::TYPE_DB_PUBLIC_BACKUP],
]);

test('a backup to an unreachable filesystem fails before dumping', function (string $command, int $type) {
    configurePredictionsBackupConnection();
    $filesystem = Filesystem::query()->where('type', $type)->firstOrFail();
    $disks = config('filesystems.disks');
    unset($disks[$filesystem->systemName]);
    config()->set('filesystems.disks', $disks);
    Storage::forgetDisk($filesystem->systemName);
    Process::fake();

    $this->artisan($command)
        ->expectsOutput('Could not access backup filesystem "'.$filesystem->systemName.'"! Aborting.')
        ->assertFailed();

    Process::assertNothingRan();
})->with([
    'full' => ['db:dump', Filesystem::TYPE_DB_FULL_BACKUP],
    'predictions' => ['db:dump-predictions', Filesystem::TYPE_DB_PREDICTIONS_BACKUP],
    'idsm' => ['db:dump-idsm', Filesystem::TYPE_DB_PUBLIC_BACKUP],
]);

test('the predictions backup refuses a connection that is not PostgreSQL', function () {
    useLocalDatabaseBackupDisk(Filesystem::TYPE_DB_PREDICTIONS_BACKUP);
    config()->set('database.connections.predictions.driver', 'sqlite');
    Process::fake();

    $this->artisan('db:dump-predictions')
        ->expectsOutput('Predictions connection driver is "sqlite", but only "pgsql" is supported by this command.')
        ->assertFailed();

    Process::assertNothingRan();
});

test('the predictions backup dumps the predictions database and uploads it gzipped', function () {
    $diskName = useLocalDatabaseBackupDisk(Filesystem::TYPE_DB_PREDICTIONS_BACKUP);
    configurePredictionsBackupConnection();
    Storage::disk($diskName)->put(todaysDatabaseBackupPath('backup-predictions'), gzencode('old dump'));
    Process::fake(['*' => Process::describe()->output(['CREATE TABLE public.predictions (id bigint);', 'COPY public.predictions (id) FROM stdin;'])]);

    $this->artisan('db:dump-predictions')
        ->expectsOutput('# Old backup deleted.')
        ->expectsOutput('Backup created and uploaded.')
        ->assertSuccessful();

    Process::assertRanTimes(fn (): bool => true, 1);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['pg_dump', '-U', 'predictions_user', '-p', '6543', '-h', 'predictions-db.internal', 'predictions_prod']
        && $process->environment === ['PGPASSWORD' => 'predictions-secret']
        && $process->timeout === null);

    expect(readDatabaseBackup($diskName, todaysDatabaseBackupPath('backup-predictions')))
        ->toBe("CREATE TABLE public.predictions (id bigint);\nCOPY public.predictions (id) FROM stdin;\n");
});

test('a failed pg_dump or upload fails the predictions backup', function (bool $failDump) {
    $diskName = useLocalDatabaseBackupDisk(Filesystem::TYPE_DB_PREDICTIONS_BACKUP);
    configurePredictionsBackupConnection();

    if ($failDump) {
        Process::fake(['*' => Process::describe()->errorOutput('pg_dump: error: password authentication failed')->exitCode(1)]);
    } else {
        Process::fake(['*' => Process::describe()->output('dump')]);
        failDatabaseBackupUploads($diskName);
    }

    $this->artisan('db:dump-predictions')
        ->expectsOutput($failDump ? 'pg_dump: error: password authentication failed' : 'Could not upload backup to target filesystem.')
        ->assertFailed();

    expect(Storage::disk($diskName)->exists(todaysDatabaseBackupPath('backup-predictions')))->toBeFalse();
})->with(['pg_dump' => true, 'upload' => false]);

test('the IDSM backup dumps the public tables, makes the dump restorable, uploads it and records the time', function () {
    $diskName = useLocalDatabaseBackupDisk(Filesystem::TYPE_DB_PUBLIC_BACKUP);
    config()->set('database.connections.pgsql.password', 'main-db-secret');
    Config::set('db_backup_idsm_last', now()->subDays(8)->format('Y-m-d H:i:s'));
    Storage::disk($diskName)->put(todaysDatabaseBackupPath('backup-idsm'), gzencode('old dump'));
    Process::fake(['*' => Process::describe()->output([
        'SET client_encoding = \'UTF8\';',
        'SET transaction_timeout = 0;',
        'CREATE TABLE public.structures (id bigint);',
        '--',
        '-- Name: structures_canonical_smiles_bingo_index; Type: INDEX; Schema: public; Owner: postgres',
        '--',
        '',
        'CREATE INDEX structures_canonical_smiles_bingo_index ON public.structures USING bingo_idx (canonical_smiles bingo.molecule);',
        '',
        '--',
        '-- PostgreSQL database dump complete',
        '--',
        '',
        '\\unrestrict dumpKey',
    ])]);

    $this->artisan('db:dump-idsm')
        ->expectsOutput('# Old backup deleted.')
        ->expectsOutput('Backup created and uploaded.')
        ->assertSuccessful();

    Process::assertRanTimes(fn (): bool => true, 1);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [...mainDatabasePgDumpOptions(), ...BackupDbIdsm::tableArguments(), (string) config('database.connections.pgsql.database')]
        && $process->environment === ['PGPASSWORD' => 'main-db-secret']
        && $process->timeout === null);

    $backup = readDatabaseBackup($diskName, todaysDatabaseBackupPath('backup-idsm'));

    expect($backup)->toStartWith("CREATE EXTENSION IF NOT EXISTS citext WITH SCHEMA public;\nSET client_encoding = 'UTF8';\nCREATE TABLE public.structures (id bigint);")
        ->and($backup)->not->toContain('transaction_timeout')
        ->and($backup)->not->toContain('bingo_idx')
        ->and($backup)->toEndWith("--\n-- PostgreSQL database dump complete\n--\n\n\\unrestrict dumpKey\n")
        ->and(abs(Carbon::parse(Config::get('db_backup_idsm_last'))->diffInSeconds(now())))->toBeLessThan(60);
});

test('a failed IDSM backup keeps the time of the last successful one', function (bool $failDump) {
    $diskName = useLocalDatabaseBackupDisk(Filesystem::TYPE_DB_PUBLIC_BACKUP);
    $lastBackup = now()->subDays(8)->format('Y-m-d H:i:s');
    Config::set('db_backup_idsm_last', $lastBackup);

    if ($failDump) {
        Process::fake(['*' => Process::describe()->errorOutput('pg_dump: error: query failed')->exitCode(1)]);
    } else {
        Process::fake(['*' => Process::describe()->output('dump')]);
        failDatabaseBackupUploads($diskName);
    }

    $this->artisan('db:dump-idsm')
        ->expectsOutput($failDump ? 'pg_dump: error: query failed' : 'Could not upload backup to target filesystem.')
        ->assertFailed();

    expect(Config::get('db_backup_idsm_last'))->toBe($lastBackup)
        ->and(Storage::disk($diskName)->exists(todaysDatabaseBackupPath('backup-idsm')))->toBeFalse();
})->with(['pg_dump' => true, 'upload' => false]);

test('the IDSM backup streams a real pg_dump of the public tables into the uploaded file', function () {
    $diskName = useLocalDatabaseBackupDisk(Filesystem::TYPE_DB_PUBLIC_BACKUP);
    Process::preventStrayProcesses(false);

    $this->artisan('db:dump-idsm')->assertSuccessful();

    $backup = readDatabaseBackup($diskName, todaysDatabaseBackupPath('backup-idsm'));

    expect($backup)->toStartWith('CREATE EXTENSION IF NOT EXISTS citext WITH SCHEMA public;')
        ->and($backup)->toContain('CREATE TABLE public.structures')
        ->and($backup)->not->toContain('CREATE TABLE public.users')
        ->and($backup)->not->toContain('CREATE TABLE public.ssh_credentials')
        ->and($backup)->toContain("--\n-- PostgreSQL database dump complete\n--\n");
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql' || (new ExecutableFinder)->find('pg_dump') === null, 'needs PostgreSQL and pg_dump');
