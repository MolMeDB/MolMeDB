<?php

namespace App\Console\Commands;

use App\Enums\PermissionEnums;
use App\Models\Filesystem;
use App\Models\NotificationTemplate;
use App\Services\NotificationService;
use App\Services\RemoteShell;
use Illuminate\Console\Command;
use Illuminate\Support\Number;
use JsonSerializable;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionFile;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Weekly export of finished predictions with their COSMO files for external use.
 *
 * Every prediction configuration is one archive (molecule.json, cosmo.xml, *.ccf) kept in
 * the export storage as archives/{structure_id div 1000}/{structure_id}_{configuration}.zip.
 * Archives are rebuilt only when their fingerprint changes. All archives and the manifest
 * are then packed into cosmo_export.zip. The archives are built on the storage server itself.
 */
class ExportPredictionCosmo extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'predictions:export-cosmo
        {--rebuild : Rebuild every archive, ignoring the previous manifest}
        {--dry-run : Only report what would be built or removed}
        {--limit= : Export at most this many predictions (testing only, nothing is removed)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Builds the weekly export of finished predictions with COSMO files (one archive per configuration) on the backup server.';

    private const MANIFEST_COLUMNS = [
        'archive', 'prediction_id', 'prediction_structure_id', 'mm_identifier', 'smiles', 'parent_smiles',
        'method', 'membrane', 'membrane_name', 'temperature', 'logK', 'logPerm', 'created_at', 'source',
        'ccf_count', 'fingerprint',
    ];

    private const SAFE_NAME = '/^[A-Za-z0-9_.,-]+$/';

    private string $step = 'preparing the export';

    public function handle(NotificationService $notificationService): int
    {
        $startedAt = microtime(true);

        try {
            $summary = $this->export();
        } catch (Throwable $throwable) {
            $this->error("COSMO export failed while {$this->step}: {$throwable->getMessage()}");
            report($throwable);

            if (! $this->option('dry-run')) {
                $notificationService->sendToPermission(
                    PermissionEnums::SYSTEM_MONITOR->value,
                    NotificationTemplate::KEY_SYSTEM_ADMIN_COSMO_EXPORT_FAILED,
                    ['step' => $this->step, 'error' => $throwable->getMessage()],
                );
            }

            return Command::FAILURE;
        }

        $this->table(['', 'count'], collect($summary['counts'])->map(fn (int $count, string $label): array => [$label, $count])->values()->all());

        if ($this->option('dry-run')) {
            return Command::SUCCESS;
        }

        $this->info("Export: {$summary['export_path']} (".Number::fileSize($summary['size'], 1).')');

        $notificationService->sendToPermission(
            PermissionEnums::SYSTEM_MONITOR->value,
            NotificationTemplate::KEY_SYSTEM_ADMIN_COSMO_EXPORT_FINISHED,
            [
                'date' => $summary['date'],
                'export_path' => $summary['export_path'],
                'total' => $summary['counts']['total'],
                'added' => $summary['counts']['added'],
                'updated' => $summary['counts']['updated'],
                'removed' => $summary['counts']['removed'],
                'unchanged' => $summary['counts']['unchanged'],
                'skipped' => $summary['counts']['skipped'],
                'size' => Number::fileSize($summary['size'], 1),
                'duration' => gmdate('H:i:s', (int) (microtime(true) - $startedAt)),
            ],
        );

        return Command::SUCCESS;
    }

    /**
     * @return array{date: string, export_path: string, size: int, counts: array<string, int>}
     */
    private function export(): array
    {
        $resultsFilesystem = $this->filesystem(Filesystem::TYPE_PREDICTIONS_STORAGE);
        $exportFilesystem = $this->filesystem(Filesystem::TYPE_PREDICTIONS_COSMO_EXPORT);

        $this->step = 'connecting to the backup server';
        $shell = RemoteShell::forFilesystem($exportFilesystem);

        if (! $shell->isSameServer($resultsFilesystem)) {
            throw new RuntimeException('The COSMO export must be on the same server as the prediction results storage.');
        }

        $resultsRoot = RemoteShell::absoluteRoot($resultsFilesystem);
        $exportRoot = RemoteShell::absoluteRoot($exportFilesystem);
        $date = now()->format('Y-m-d');
        $manifestName = "manifest_{$date}.csv";

        $this->step = 'reading the previous manifest';
        $previous = $this->option('rebuild') ? [] : $this->previousManifest($shell, $exportRoot);

        $this->step = 'collecting finished predictions';
        $ccfByFolder = $this->cosmoFilesByFolder($resultsFilesystem->systemName);
        $rows = [];
        $work = "{$exportRoot}/.work/".now()->format('Ymd_His');
        $workFiles = $this->option('dry-run') ? null : $this->openWorkFiles();
        $counts = ['total' => 0, 'added' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'skipped' => 0];

        $predictions = Prediction::query()
            ->where('state', Prediction::STATE_FINISHED)
            ->whereNotNull('result_id')
            ->with(['predictionStructure.structure.parent', 'predictionMembrane', 'predictionResult.file'])
            ->lazyById(500);

        if ($this->option('limit') !== null) {
            $predictions = $predictions->take((int) $this->option('limit'));
        }

        foreach ($predictions as $prediction) {
            $resultFile = $prediction->predictionResult?->file;
            $folder = $resultFile ? dirname($resultFile->path) : null;
            $ccfFiles = $folder !== null ? ($ccfByFolder[$folder] ?? []) : [];

            if (! $resultFile || $resultFile->storage !== $resultsFilesystem->systemName || $ccfFiles === [] || ! preg_match(self::SAFE_NAME, basename($folder))) {
                $counts['skipped']++;

                continue;
            }

            $structureId = (int) $prediction->structure_id;
            $archive = intdiv($structureId, 1000).'/'.$structureId.'_'.basename($folder).'.zip';

            if (isset($rows[$archive])) {
                $counts['skipped']++;

                continue;
            }

            $molecule = $this->moleculeData($prediction, array_keys($ccfFiles));
            $fingerprint = md5(json_encode([$resultFile->hash ?: $resultFile->path, $ccfFiles, $molecule]));
            $previousRow = $previous[$archive] ?? null;
            $isUnchanged = $previousRow !== null && $previousRow['fingerprint'] === $fingerprint;

            // logK/logPerm come from cosmo.xml, which is part of the fingerprint, so unchanged rows reuse them.
            $values = $isUnchanged
                ? ['logK' => $previousRow['logK'], 'logPerm' => $previousRow['logPerm']]
                : $this->resultValues($prediction);

            if ($values === null) {
                $counts['skipped']++;

                continue;
            }

            $counts[$isUnchanged ? 'unchanged' : ($previousRow === null ? 'added' : 'updated')]++;
            $rows[$archive] = array_merge($molecule, $values, [
                'archive' => "archives/{$archive}",
                'prediction_id' => $prediction->id,
                'ccf_count' => count($ccfFiles),
                'fingerprint' => $fingerprint,
            ]);

            if (! $isUnchanged && $workFiles !== null) {
                $files = ["{$resultsRoot}/{$resultFile->path}" => 'cosmo.xml']
                    + collect($ccfFiles)->keys()->mapWithKeys(fn (string $name): array => ["{$resultsRoot}/{$folder}/{$name}" => $name])->all();
                $this->addJob($workFiles, $work, $archive, array_merge($molecule, $values), $files);
            }
        }

        $removed = $this->option('limit') !== null ? [] : array_values(array_diff(array_keys($previous), array_keys($rows)));
        $counts['removed'] = count($removed);
        $counts['total'] = count($rows);

        if ($this->option('dry-run')) {
            return ['date' => $date, 'export_path' => "{$exportRoot}/cosmo_export.zip", 'size' => 0, 'counts' => $counts];
        }

        $this->step = 'uploading the work files';
        $this->uploadWorkFiles($shell, $work, $workFiles, $removed, $rows, $manifestName);

        $this->step = 'building the archives on the backup server';
        $shell->put("{$work}/build-cosmo-export.sh", (string) file_get_contents(resource_path('scripts/build-cosmo-export.sh')));
        $result = $shell->run(implode(' ', array_map('escapeshellarg', ['bash', "{$work}/build-cosmo-export.sh", $exportRoot, $work, $manifestName])));
        $shell->run('rm -rf -- '.escapeshellarg($work));

        if ($result['exit'] !== 0) {
            throw new RuntimeException('build-cosmo-export.sh exited with '.$result['exit'].': '.trim(substr($result['output'], -2000)));
        }

        preg_match_all('/^(\w+)=(\d+)$/m', $result['output'], $matches);
        $reported = array_combine($matches[1], array_map('intval', $matches[2]));
        $counts['total'] = $reported['archives'] ?? $counts['total'];

        return ['date' => $date, 'export_path' => "{$exportRoot}/cosmo_export.zip", 'size' => $reported['size'] ?? 0, 'counts' => $counts];
    }

    /**
     * Local temporary work files, filled while the predictions are processed so the job list
     * never has to be held in memory.
     *
     * @return array{directory: string, jobs: resource, molecules: ZipArchive, count: int}
     */
    private function openWorkFiles(): array
    {
        $directory = sys_get_temp_dir().'/cosmo-export-'.getmypid().'-'.now()->format('Ymd_His');

        if (! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create the local work directory [{$directory}].");
        }

        $molecules = new ZipArchive;
        $jobs = fopen("{$directory}/jobs.tsv", 'w');

        if ($jobs === false || $molecules->open("{$directory}/molecules.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the local work files.');
        }

        return ['directory' => $directory, 'jobs' => $jobs, 'molecules' => $molecules, 'count' => 0];
    }

    /**
     * @param  array{directory: string, jobs: resource, molecules: ZipArchive, count: int}  $workFiles
     * @param  array<string, mixed>  $molecule
     * @param  array<string, string>  $files  absolute source path => name in the archive
     */
    private function addJob(array &$workFiles, string $work, string $archive, array $molecule, array $files): void
    {
        $index = ++$workFiles['count'];
        $workFiles['molecules']->addFromString("{$index}.json", json_encode($molecule, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        fwrite($workFiles['jobs'], "{$archive}\t{$work}/molecules/{$index}.json\tmolecule.json\n");

        foreach ($files as $source => $name) {
            fwrite($workFiles['jobs'], "{$archive}\t{$source}\t{$name}\n");
        }
    }

    /**
     * @param  array{directory: string, jobs: resource, molecules: ZipArchive, count: int}  $workFiles
     * @param  array<int, string>  $removed
     * @param  array<string, array<string, mixed>>  $rows
     */
    private function uploadWorkFiles(RemoteShell $shell, string $work, array $workFiles, array $removed, array $rows, string $manifestName): void
    {
        $directory = $workFiles['directory'];

        try {
            fclose($workFiles['jobs']);

            // An archive without entries is not written at all - the build script skips an empty molecules.zip.
            $workFiles['molecules']->close();

            if (! file_exists("{$directory}/molecules.zip")) {
                touch("{$directory}/molecules.zip");
            }

            file_put_contents("{$directory}/delete.txt", $removed === [] ? '' : implode("\n", $removed)."\n");
            $this->writeManifest("{$directory}/{$manifestName}", $rows);

            foreach (['molecules.zip', 'jobs.tsv', 'delete.txt', $manifestName] as $name) {
                $shell->putFile("{$work}/{$name}", "{$directory}/{$name}");
            }
        } finally {
            foreach (glob("{$directory}/*") ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($directory);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     */
    private function writeManifest(string $path, array $rows): void
    {
        $handle = fopen($path, 'w') ?: throw new RuntimeException("Unable to write the manifest [{$path}].");
        fputcsv($handle, self::MANIFEST_COLUMNS, ',', '"', '');

        foreach ($rows as $row) {
            $row['temperature'] = number_format((float) $row['temperature'], 1, '.', '');
            fputcsv($handle, array_map(fn (string $column): mixed => $row[$column] ?? null, self::MANIFEST_COLUMNS), ',', '"', '');
        }

        fclose($handle);
    }

    /**
     * Rows of the newest manifest keyed by archive path relative to archives/.
     *
     * @return array<string, array<string, string>>
     */
    private function previousManifest(RemoteShell $shell, string $exportRoot): array
    {
        $manifests = array_filter($shell->list("{$exportRoot}/manifests"), fn (string $name): bool => (bool) preg_match('/^manifest_\d{4}-\d{2}-\d{2}\.csv$/', $name));

        if ($manifests === []) {
            return [];
        }

        rsort($manifests);
        $csv = $shell->get("{$exportRoot}/manifests/{$manifests[0]}") ?? '';
        $lines = array_values(array_filter(explode("\n", $csv), fn (string $line): bool => $line !== ''));
        $header = str_getcsv(array_shift($lines) ?? '', ',', '"', '');
        $rows = [];

        foreach ($lines as $line) {
            $row = array_combine($header, str_getcsv($line, ',', '"', ''));
            $rows[substr($row['archive'], strlen('archives/'))] = $row;
        }

        return $rows;
    }

    /**
     * COSMO conformer files grouped by their result folder, name => hash.
     *
     * @return array<string, array<string, string>>
     */
    private function cosmoFilesByFolder(string $diskName): array
    {
        $files = [];

        PredictionFile::query()
            ->where('type', PredictionFile::TYPE_COSMO_CONFORMERS)
            ->where('storage', $diskName)
            ->orderBy('path')
            ->toBase()
            ->select(['path', 'hash'])
            ->cursor()
            ->each(function (object $file) use (&$files): void {
                $name = basename($file->path);

                if (preg_match(self::SAFE_NAME, $name)) {
                    $files[dirname($file->path)][$name] = (string) $file->hash;
                }
            });

        return $files;
    }

    /**
     * Molecule and configuration description, without the values parsed from cosmo.xml.
     *
     * @param  array<int, string>  $conformerFiles
     * @return array<string, mixed>
     */
    private function moleculeData(Prediction $prediction, array $conformerFiles): array
    {
        $structure = $prediction->predictionStructure?->structure;

        return [
            'mm_identifier' => $structure?->identifier,
            'prediction_structure_id' => (int) $prediction->structure_id,
            'smiles' => $prediction->predictionStructure?->canonical_smiles,
            'parent_smiles' => $structure?->parent?->canonical_smiles,
            'method' => (string) $prediction->method_type,
            'membrane' => $prediction->predictionMembrane?->abbreviation,
            'membrane_name' => $prediction->predictionMembrane?->name,
            'temperature' => (float) $prediction->temperature,
            'created_at' => ($prediction->predictionResult->created_at ?? $prediction->created_at)?->toIso8601String(),
            'source' => filled($prediction->remote_calculation_id) ? 'remote' : 'legacy',
            'conformer_files' => $conformerFiles,
        ];
    }

    /**
     * @return array{logK: ?float, logPerm: ?float}|null null when the result has no parsed values
     */
    private function resultValues(Prediction $prediction): ?array
    {
        $data = $prediction->predictionResult->loadParsedResults();
        $data = $data instanceof JsonSerializable ? $data->jsonSerialize() : $data;
        $solute = is_array($data) ? ($data[0]['solutes'][0] ?? null) : null;

        if (! is_array($solute)) {
            return null;
        }

        return [
            'logK' => is_numeric($solute['logK'] ?? null) ? (float) $solute['logK'] : null,
            'logPerm' => is_numeric($solute['logPerm'] ?? null) ? (float) $solute['logPerm'] : null,
        ];
    }

    private function filesystem(int $type): Filesystem
    {
        $filesystem = Filesystem::query()->where('type', $type)->first();

        if (! $filesystem?->isInitialized()) {
            throw new RuntimeException('Filesystem ['.(Filesystem::types()[$type] ?? $type).'] is not configured.');
        }

        return $filesystem;
    }
}
