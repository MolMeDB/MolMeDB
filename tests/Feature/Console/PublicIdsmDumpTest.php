<?php

use App\Console\Commands\Database\BackupDbIdsm;
use App\Models\Config;
use App\Models\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

test('the dump selects exactly the public tables by name', function () {
    $arguments = BackupDbIdsm::tableArguments();

    expect($arguments)->toHaveCount(count(BackupDbIdsm::PUBLIC_TABLES))
        ->and($arguments)->toContain('--table=public.interactions_passive')
        ->and(implode(' ', $arguments))->not->toContain('*')
        ->and(implode(' ', $arguments))->not->toContain('feedback')
        ->and(implode(' ', $arguments))->not->toContain('users');
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
