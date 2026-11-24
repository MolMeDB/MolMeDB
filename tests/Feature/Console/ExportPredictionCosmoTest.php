<?php

require_once __DIR__.'/../Predictions/remote_prediction_test_helpers.php';

use App\Console\Commands\ExportPredictionCosmo;
use App\Enums\PermissionEnums;
use App\Models\Filesystem;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\RemoteShell;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionFile;
use Modules\PredictionWorkers\Models\PredictionMembrane;
use Modules\PredictionWorkers\Models\PredictionResult;
use Modules\PredictionWorkers\Models\PredictionStructure;
use Spatie\Permission\PermissionRegistrar;

/**
 * Stands in for the SSH connection to the backup server: the "server" is a local
 * directory and commands (the build script included) run in a local shell.
 */
class CosmoExportLocalShell extends RemoteShell
{
    /** @var array<int, string> */
    public array $commands = [];

    /** @var array<string, string> uploaded file name => contents */
    public array $uploads = [];

    public function __construct(
        public bool $sameServer = true,
        public ?int $buildExitCode = null,
    ) {}

    public function isSameServer(Filesystem $filesystem): bool
    {
        return $this->sameServer;
    }

    public function put(string $remotePath, string $contents): void
    {
        File::ensureDirectoryExists(dirname($remotePath));
        File::put($remotePath, $contents);
    }

    public function putFile(string $remotePath, string $localPath): void
    {
        File::ensureDirectoryExists(dirname($remotePath));
        File::copy($localPath, $remotePath);
        $this->uploads[basename($remotePath)] = File::get($localPath);
    }

    public function get(string $remotePath): ?string
    {
        return File::exists($remotePath) ? File::get($remotePath) : null;
    }

    public function getFile(string $remotePath, string $localPath): void
    {
        if (! File::copy($remotePath, $localPath)) {
            throw new RuntimeException("Unable to download [{$remotePath}].");
        }
    }

    public function list(string $remoteDirectory): array
    {
        return File::isDirectory($remoteDirectory) ? array_values(array_diff(scandir($remoteDirectory), ['.', '..'])) : [];
    }

    public function run(string $command, int $timeoutSeconds = 0): array
    {
        $this->commands[] = $command;

        if ($this->buildExitCode !== null && str_contains($command, 'build-cosmo-export.sh')) {
            return ['output' => "zip: disk full\n", 'exit' => $this->buildExitCode];
        }

        $result = Process::run($command.' 2>&1');

        return ['output' => $result->output(), 'exit' => $result->exitCode()];
    }
}

class ExportPredictionCosmoOverLocalShell extends ExportPredictionCosmo
{
    public function __construct(private readonly RemoteShell $localShell)
    {
        parent::__construct();
    }

    protected function connectToServer(Filesystem $filesystem): RemoteShell
    {
        return $this->localShell;
    }
}

beforeEach(function () {
    $this->serverRoot = sys_get_temp_dir().'/cosmo-export-test-server-'.getmypid().'-'.Str::random(6);
    File::ensureDirectoryExists($this->serverRoot);

    // The backup server is the local "server" directory; results and the export are scoped on it.
    Filesystem::query()->where('type', Filesystem::TYPE_BACKUPS)->update(['root_path' => $this->serverRoot]);
    $this->resultsFilesystem = Filesystem::query()->where('type', Filesystem::TYPE_PREDICTIONS_STORAGE)->firstOrFail();
    $this->exportFilesystem = Filesystem::query()->where('type', Filesystem::TYPE_PREDICTIONS_COSMO_EXPORT)->firstOrFail();
    $this->resultsRoot = $this->serverRoot.'/'.trim($this->resultsFilesystem->root_path, '/');
    $this->exportRoot = $this->serverRoot.'/'.trim($this->exportFilesystem->root_path, '/');
    $this->resultsDisk = $this->resultsFilesystem->systemName;

    config()->set("filesystems.disks.{$this->resultsDisk}", ['driver' => 'local', 'root' => $this->resultsRoot]);
    config()->set("filesystems.disks.{$this->exportFilesystem->systemName}", ['driver' => 'local', 'root' => $this->exportRoot]);
    Storage::forgetDisk([$this->resultsDisk, $this->exportFilesystem->systemName]);

    // split_part() is PostgreSQL; the predictions connection is SQLite in tests.
    DB::connection('predictions')->getPdo()->createFunction(
        'split_part',
        fn (?string $value, string $delimiter, int $position): string => explode($delimiter, (string) $value)[$position - 1] ?? '',
        3,
    );

    $this->shell = new CosmoExportLocalShell;
    Artisan::registerCommand(new ExportPredictionCosmoOverLocalShell($this->shell));

    Permission::query()->firstOrCreate(
        ['name' => PermissionEnums::SYSTEM_MONITOR->value, 'guard_name' => 'web'],
        ['description' => PermissionEnums::SYSTEM_MONITOR->description()],
    );
    $role = Role::query()->create(['name' => 'COSMO export monitors', 'guard_name' => 'web']);
    $role->givePermissionTo(PermissionEnums::SYSTEM_MONITOR->value);
    $this->monitor = User::factory()->create();
    $this->monitor->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () {
    File::deleteDirectory($this->serverRoot);
});

/**
 * A finished prediction with its cosmo.xml and conformer files stored in the results storage.
 *
 * @param  array{folder?: string, conformers?: array<string, string>, data?: array<int, mixed>|null, xml?: string|null, storage?: string, structure?: PredictionStructure, attributes?: array<string, mixed>}  $options
 */
function exportablePrediction(array $options = []): Prediction
{
    $disk = $options['storage'] ?? Filesystem::query()->where('type', Filesystem::TYPE_PREDICTIONS_STORAGE)->firstOrFail()->systemName;

    $structure = $options['structure'] ?? PredictionStructure::query()->create([
        'canonical_smiles' => 'CCO'.Str::random(4),
        'remote_id' => createApiStructure([
            'canonical_smiles' => 'CCO',
            'parent_id' => createApiStructure(['canonical_smiles' => 'CC[O-]'])->id,
        ])->id,
    ]);
    $membrane = PredictionMembrane::query()->create([
        'remote_id' => fake()->unique()->numberBetween(1, 1_000_000),
        'name' => 'DOPC bilayer',
        'abbreviation' => 'DOPC'.Str::random(3),
    ]);

    $folder = $structure->id.'/'.($options['folder'] ?? 'dopc_25_perm');
    $xml = array_key_exists('xml', $options) ? $options['xml'] : cosmoResultXml(-4.5);

    if ($xml !== null) {
        Storage::disk($disk)->put("{$folder}/cosmo.xml", $xml);
    }

    $resultFile = PredictionFile::query()->create([
        'type' => PredictionFile::TYPE_RESULT_COSMO_XML,
        'mime' => 'application/xml',
        'storage' => $disk,
        'path' => "{$folder}/cosmo.xml",
        'hash' => md5((string) $xml),
    ]);

    foreach ($options['conformers'] ?? ['conformer_1.ccf' => 'ccf 1', 'conformer_2.ccf' => 'ccf 2'] as $name => $contents) {
        Storage::disk($disk)->put("{$folder}/{$name}", $contents);
        PredictionFile::query()->create([
            'type' => PredictionFile::TYPE_COSMO_CONFORMERS,
            'mime' => 'text/plain',
            'storage' => $disk,
            'path' => "{$folder}/{$name}",
            'hash' => md5($contents),
        ]);
    }

    $result = PredictionResult::query()->create([
        'file_id' => $resultFile->id,
        'data' => $options['data'] ?? null,
    ]);

    return Prediction::query()->create([
        'structure_id' => $structure->id,
        'result_id' => $result->id,
        'state' => Prediction::STATE_FINISHED,
        'step' => Prediction::STEP_RESULT_DB_STORE,
        'temperature' => 25.0,
        'membrane_id' => $membrane->id,
        'method_type' => 'cosmoperm',
        'priority' => Prediction::PRIORITY_MEDIUM,
        ...($options['attributes'] ?? []),
    ]);
}

function cosmoExportArchiveName(Prediction $prediction, string $folder = 'dopc_25_perm'): string
{
    return intdiv((int) $prediction->structure_id, 1000).'/'.$prediction->structure_id.'_'.$folder.'.zip';
}

/**
 * @return array<string, string> entry name => contents
 */
function cosmoExportZipEntries(string $path): array
{
    $archive = new ZipArchive;
    expect($archive->open($path))->toBeTrue();
    $entries = [];

    for ($index = 0; $index < $archive->numFiles; $index++) {
        $name = $archive->getNameIndex($index);

        if (! str_ends_with($name, '/')) {
            $entries[$name] = (string) $archive->getFromIndex($index);
        }
    }

    $archive->close();

    return $entries;
}

/**
 * @return array<int, array<string, string>>
 */
function cosmoExportManifestRows(string $csv): array
{
    $lines = array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), array_filter(explode("\n", $csv)));
    $header = array_shift($lines);

    return array_map(fn (array $row): array => array_combine($header, $row), $lines);
}

function cosmoExportNotifications(User $user, string $key): array
{
    return UserNotification::query()
        ->where('user_id', $user->id)
        ->where('notification_template_id', NotificationTemplate::query()->where('key', $key)->value('id'))
        ->orderBy('id')
        ->get()
        ->all();
}

test('finished predictions with COSMO files are exported as one archive per configuration', function () {
    $prediction = exportablePrediction();
    $structure = $prediction->predictionStructure->structure;
    $remote = exportablePrediction(['data' => [['solutes' => [['logK' => 1.25, 'logPerm' => -6.0]]]], 'attributes' => ['remote_calculation_id' => 'calc-1']]);
    exportablePrediction(['conformers' => []]);
    exportablePrediction(['attributes' => ['state' => Prediction::STATE_RUNNING, 'result_id' => null]]);

    $this->artisan('predictions:export-cosmo')
        ->expectsTable(['', 'count'], [['total', 2], ['added', 2], ['updated', 0], ['unchanged', 0], ['removed', 0], ['skipped', 1]])
        ->expectsOutputToContain("Export: {$this->exportRoot}/cosmo_export.zip")
        ->assertSuccessful();

    $date = now()->format('Y-m-d');
    $dump = cosmoExportZipEntries("{$this->exportRoot}/cosmo_export.zip");
    $archiveName = cosmoExportArchiveName($prediction);

    expect(array_keys($dump))->toEqualCanonicalizing([
        "archives/{$archiveName}",
        'archives/'.cosmoExportArchiveName($remote),
        "manifest_{$date}.csv",
    ])
        ->and(File::get("{$this->exportRoot}/manifests/manifest_{$date}.csv"))->toBe($dump["manifest_{$date}.csv"]);

    $configuration = cosmoExportZipEntries("{$this->exportRoot}/archives/{$archiveName}");
    $molecule = json_decode($configuration['molecule.json'], true);

    expect(array_keys($configuration))->toEqualCanonicalizing(['molecule.json', 'cosmo.xml', 'conformer_1.ccf', 'conformer_2.ccf'])
        ->and($configuration['cosmo.xml'])->toBe(cosmoResultXml(-4.5))
        ->and($configuration['conformer_2.ccf'])->toBe('ccf 2')
        ->and($molecule)->toMatchArray([
            'mm_identifier' => $structure->identifier,
            'prediction_structure_id' => $prediction->structure_id,
            'smiles' => $prediction->predictionStructure->canonical_smiles,
            'parent_smiles' => 'CC[O-]',
            'method' => 'cosmoperm',
            'membrane' => $prediction->predictionMembrane->abbreviation,
            'membrane_name' => 'DOPC bilayer',
            'temperature' => 25.0,
            'source' => 'legacy',
            'conformer_files' => ['conformer_1.ccf', 'conformer_2.ccf'],
            'logK' => 2.75,
            'logPerm' => -4.5,
        ]);

    $rows = collect(cosmoExportManifestRows($dump["manifest_{$date}.csv"]))->keyBy('prediction_id');

    expect($rows)->toHaveCount(2)
        ->and($rows[$prediction->id])->toMatchArray([
            'archive' => "archives/{$archiveName}",
            'mm_identifier' => $structure->identifier,
            'temperature' => '25.0',
            'logK' => '2.75',
            'logPerm' => '-4.5',
            'source' => 'legacy',
            'ccf_count' => '2',
        ])
        ->and($rows[$prediction->id]['fingerprint'])->toMatch('/^[0-9a-f]{32}$/')
        ->and($rows[$remote->id])->toMatchArray(['source' => 'remote', 'logK' => '1.25', 'logPerm' => '-6']);

    // Nothing is left behind, neither on the server nor locally.
    expect(File::isDirectory("{$this->exportRoot}/.work") ? File::directories("{$this->exportRoot}/.work") : [])->toBe([])
        ->and(glob(sys_get_temp_dir().'/cosmo-export-'.getmypid().'-*'))->toBe([])
        ->and(collect($this->shell->commands)->last())->toStartWith('rm -rf -- ');

    $notifications = cosmoExportNotifications($this->monitor, NotificationTemplate::KEY_SYSTEM_ADMIN_COSMO_EXPORT_FINISHED);

    expect($notifications)->toHaveCount(1)
        ->and($notifications[0]->data)->toMatchArray([
            'date' => $date,
            'export_path' => "{$this->exportRoot}/cosmo_export.zip",
            'total' => 2,
            'added' => 2,
            'updated' => 0,
            'removed' => 0,
            'unchanged' => 0,
            'skipped' => 1,
        ]);
})->skip(fn (): bool => ! commandExistsForCosmoExport(), 'zip and tar are needed to run the build script.');

test('a second run rebuilds only changed archives and removes those no longer exported', function () {
    $unchanged = exportablePrediction();
    $changed = exportablePrediction();
    $removed = exportablePrediction();

    $this->artisan('predictions:export-cosmo')->assertSuccessful();
    $firstManifest = collect(cosmoExportManifestRows($this->shell->uploads['manifest_'.now()->format('Y-m-d').'.csv']))->keyBy('prediction_id');

    // A new conformer of one configuration, another one is no longer finished.
    $changedFolder = $changed->structure_id.'/dopc_25_perm';
    Storage::disk($this->resultsDisk)->put("{$changedFolder}/conformer_3.ccf", 'ccf 3');
    PredictionFile::query()->create(['type' => PredictionFile::TYPE_COSMO_CONFORMERS, 'mime' => 'text/plain', 'storage' => $this->resultsDisk, 'path' => "{$changedFolder}/conformer_3.ccf", 'hash' => md5('ccf 3')]);
    $removed->update(['state' => Prediction::STATE_ERROR]);
    // Parsed values of unchanged archives come from the previous manifest, not from the result again.
    $unchanged->predictionResult->update(['data' => [['solutes' => [['logK' => 9.9, 'logPerm' => 9.9]]]]]);

    $this->travel(7)->days();
    $this->shell->uploads = [];

    $this->artisan('predictions:export-cosmo')
        ->expectsTable(['', 'count'], [['total', 2], ['added', 0], ['updated', 1], ['unchanged', 1], ['removed', 1], ['skipped', 0]])
        ->assertSuccessful();

    $date = now()->format('Y-m-d');
    $jobs = array_filter(explode("\n", $this->shell->uploads['jobs.tsv']));
    $secondManifest = collect(cosmoExportManifestRows($this->shell->uploads["manifest_{$date}.csv"]))->keyBy('prediction_id');

    expect(collect($jobs)->map(fn (string $job): string => explode("\t", $job)[0])->unique()->values()->all())->toBe([cosmoExportArchiveName($changed)])
        ->and(trim($this->shell->uploads['delete.txt']))->toBe(cosmoExportArchiveName($removed))
        ->and(File::exists("{$this->exportRoot}/archives/".cosmoExportArchiveName($removed)))->toBeFalse()
        ->and(cosmoExportZipEntries("{$this->exportRoot}/archives/".cosmoExportArchiveName($changed)))->toHaveKey('conformer_3.ccf')
        ->and($secondManifest[$unchanged->id]['fingerprint'])->toBe($firstManifest[$unchanged->id]['fingerprint'])
        ->and($secondManifest[$unchanged->id]['logK'])->toBe('2.75')
        ->and($secondManifest[$changed->id]['fingerprint'])->not->toBe($firstManifest[$changed->id]['fingerprint'])
        ->and(File::files("{$this->exportRoot}/manifests"))->toHaveCount(2)
        ->and(array_keys(cosmoExportZipEntries("{$this->exportRoot}/cosmo_export.zip")))->toContain("manifest_{$date}.csv");
})->skip(fn (): bool => ! commandExistsForCosmoExport(), 'zip and tar are needed to run the build script.');

test('the rebuild option ignores the previous manifest', function () {
    exportablePrediction();
    exportablePrediction();

    $this->artisan('predictions:export-cosmo')->assertSuccessful();
    $this->travel(1)->days();

    $this->artisan('predictions:export-cosmo', ['--rebuild' => true])
        ->expectsTable(['', 'count'], [['total', 2], ['added', 2], ['updated', 0], ['unchanged', 0], ['removed', 0], ['skipped', 0]])
        ->assertSuccessful();

    expect(substr_count($this->shell->uploads['jobs.tsv'], "\tmolecule.json\n"))->toBe(2);
})->skip(fn (): bool => ! commandExistsForCosmoExport(), 'zip and tar are needed to run the build script.');

test('a limited run exports only part of the predictions and removes nothing', function () {
    $first = exportablePrediction();
    exportablePrediction();

    $this->artisan('predictions:export-cosmo')->assertSuccessful();
    $this->travel(1)->days();

    $this->artisan('predictions:export-cosmo', ['--limit' => 1])
        ->expectsTable(['', 'count'], [['total', 2], ['added', 0], ['updated', 0], ['unchanged', 1], ['removed', 0], ['skipped', 0]])
        ->assertSuccessful();

    $manifest = cosmoExportManifestRows($this->shell->uploads['manifest_'.now()->format('Y-m-d').'.csv']);

    expect($manifest)->toHaveCount(1)
        ->and((int) $manifest[0]['prediction_id'])->toBe($first->id)
        ->and($this->shell->uploads['delete.txt'])->toBe('');
})->skip(fn (): bool => ! commandExistsForCosmoExport(), 'zip and tar are needed to run the build script.');

test('a dry run only reports and leaves the server and the monitors alone', function () {
    exportablePrediction();
    exportablePrediction(['conformers' => []]);

    $this->artisan('predictions:export-cosmo', ['--dry-run' => true])
        ->expectsTable(['', 'count'], [['total', 1], ['added', 1], ['updated', 0], ['unchanged', 0], ['removed', 0], ['skipped', 1]])
        ->doesntExpectOutputToContain('Export:')
        ->assertSuccessful();

    expect($this->shell->commands)->toBe([])
        ->and($this->shell->uploads)->toBe([])
        ->and(File::exists("{$this->exportRoot}/cosmo_export.zip"))->toBeFalse()
        ->and(glob(sys_get_temp_dir().'/cosmo-export-'.getmypid().'-*'))->toBe([])
        ->and(UserNotification::query()->where('user_id', $this->monitor->id)->count())->toBe(0);
});

test('predictions that cannot be exported safely are skipped', function () {
    $structure = PredictionStructure::query()->create(['canonical_smiles' => 'CCN', 'remote_id' => createApiStructure()->id]);
    exportablePrediction(['structure' => $structure]);
    // Same structure and result folder: one archive, the second configuration is a duplicate.
    exportablePrediction(['structure' => $structure, 'conformers' => []]);
    exportablePrediction(['folder' => 'dopc 25 perm']);
    config()->set('filesystems.disks.cosmo-export-other', ['driver' => 'local', 'root' => "{$this->serverRoot}/other"]);
    exportablePrediction(['storage' => 'cosmo-export-other']);
    exportablePrediction(['xml' => '<broken']);
    exportablePrediction(['conformers' => ['bad name.ccf' => 'ccf']]);

    $this->artisan('predictions:export-cosmo', ['--dry-run' => true])
        ->expectsTable(['', 'count'], [['total', 1], ['added', 1], ['updated', 0], ['unchanged', 0], ['removed', 0], ['skipped', 5]])
        ->assertSuccessful();
});

test('conformer files with unsafe names are left out of the archive', function () {
    $prediction = exportablePrediction(['conformers' => ['conformer_1.ccf' => 'ccf 1', 'bad;name.ccf' => 'ccf']]);

    $this->artisan('predictions:export-cosmo')->assertSuccessful();

    expect(array_keys(cosmoExportZipEntries("{$this->exportRoot}/archives/".cosmoExportArchiveName($prediction))))
        ->toEqualCanonicalizing(['molecule.json', 'cosmo.xml', 'conformer_1.ccf']);
})->skip(fn (): bool => ! commandExistsForCosmoExport(), 'zip and tar are needed to run the build script.');

test('a failed build is reported to the monitors and the work directory removed', function () {
    exportablePrediction();
    $this->shell->buildExitCode = 1;

    $this->artisan('predictions:export-cosmo')
        ->expectsOutputToContain('COSMO export failed while building the archives on the backup server: build-cosmo-export.sh exited with 1: zip: disk full')
        ->assertFailed();

    $notifications = cosmoExportNotifications($this->monitor, NotificationTemplate::KEY_SYSTEM_ADMIN_COSMO_EXPORT_FAILED);

    expect(collect($this->shell->commands)->last())->toStartWith('rm -rf -- ')
        ->and(File::directories("{$this->exportRoot}/.work"))->toBe([])
        ->and(glob(sys_get_temp_dir().'/cosmo-export-'.getmypid().'-*'))->toBe([])
        ->and($notifications)->toHaveCount(1)
        ->and($notifications[0]->data)->toMatchArray([
            'step' => 'building the archives on the backup server',
            'error' => 'build-cosmo-export.sh exited with 1: zip: disk full',
        ])
        ->and(cosmoExportNotifications($this->monitor, NotificationTemplate::KEY_SYSTEM_ADMIN_COSMO_EXPORT_FINISHED))->toBe([]);
});

test('the export refuses a backup server other than the results storage server', function () {
    $this->shell->sameServer = false;

    $this->artisan('predictions:export-cosmo')
        ->expectsOutputToContain('COSMO export failed while connecting to the backup server: The COSMO export must be on the same server as the prediction results storage.')
        ->assertFailed();

    expect($this->shell->commands)->toBe([])
        ->and(cosmoExportNotifications($this->monitor, NotificationTemplate::KEY_SYSTEM_ADMIN_COSMO_EXPORT_FAILED)[0]->data['step'])->toBe('connecting to the backup server');
});

test('the export fails without a configured export storage and a dry run notifies nobody', function () {
    config()->set('filesystems.disks', Arr::except(config('filesystems.disks'), [$this->exportFilesystem->systemName]));

    $this->artisan('predictions:export-cosmo', ['--dry-run' => true])
        ->expectsOutputToContain('COSMO export failed while preparing the export: Filesystem [Prediction COSMO export (weekly dump)] is not configured.')
        ->assertFailed();

    expect(UserNotification::query()->where('user_id', $this->monitor->id)->count())->toBe(0);

    $this->artisan('predictions:export-cosmo')->assertFailed();

    expect(cosmoExportNotifications($this->monitor, NotificationTemplate::KEY_SYSTEM_ADMIN_COSMO_EXPORT_FAILED))->toHaveCount(1);
});

function commandExistsForCosmoExport(): bool
{
    return trim((string) shell_exec('command -v zip')) !== '' && trim((string) shell_exec('command -v tar')) !== '';
}
