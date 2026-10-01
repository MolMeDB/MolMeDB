<?php

namespace App\Console\Commands;

use App\Enums\PermissionEnums;
use App\Models\Filesystem;
use App\Models\NotificationTemplate;
use App\Services\NotificationService;
use App\Services\RemoteShell;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use JsonSerializable;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionFile;
use RuntimeException;
use Throwable;

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
        $isDryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $this->step = 'connecting to the backup server';
        $shell = RemoteShell::forFilesystem($exportFilesystem);

        if (! $shell->isSameServer($resultsFilesystem)) {
            throw new RuntimeException('The COSMO export must be on the same server as the prediction results storage.');
        }

        $resultsRoot = RemoteShell::absoluteRoot($resultsFilesystem);
        $exportRoot = RemoteShell::absoluteRoot($exportFilesystem);
        $date = now()->format('Y-m-d');
        $manifestName = "manifest_{$date}.csv";
        $work = "{$exportRoot}/.work/".now()->format('Ymd_His');
        $workFiles = $this->openWorkFiles($manifestName);

        try {
            $this->step = 'reading the previous manifest';
            $previous = $this->option('rebuild') ? [] : $this->previousManifest($shell, $exportRoot, $workFiles['directory']);

            $this->step = 'collecting finished predictions';
            $counts = ['total' => 0, 'added' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'skipped' => 0];
            $exported = [];
            $processed = 0;

            $query = Prediction::query()
                ->where('state', Prediction::STATE_FINISHED)
                ->whereNotNull('result_id');

            $bar = $this->output->createProgressBar(min($query->count(), $limit ?? PHP_INT_MAX));
            $bar->start();

            $query->with(['predictionStructure.structure.parent', 'predictionMembrane', 'predictionResult.file'])
                ->chunkById(500, function ($predictions) use ($resultsFilesystem, $resultsRoot, $work, $isDryRun, $limit, $previous, $bar, &$workFiles, &$counts, &$exported, &$processed): bool {
                    $ccfByFolder = $this->cosmoFilesForStructures($resultsFilesystem->systemName, $predictions->pluck('structure_id')->unique()->all());

                    foreach ($predictions as $prediction) {
                        if ($limit !== null && $processed >= $limit) {
                            return false;
                        }

                        $processed++;
                        $bar->advance();
                        $resultFile = $prediction->predictionResult?->file;
                        $folder = $resultFile ? dirname($resultFile->path) : null;
                        $ccfFiles = $folder !== null ? ($ccfByFolder[$folder] ?? []) : [];

                        if (! $resultFile || $resultFile->storage !== $resultsFilesystem->systemName || $ccfFiles === [] || ! preg_match(self::SAFE_NAME, basename($folder))) {
                            $counts['skipped']++;

                            continue;
                        }

                        $structureId = (int) $prediction->structure_id;
                        $archive = intdiv($structureId, 1000).'/'.$structureId.'_'.basename($folder).'.zip';

                        if (isset($exported[$archive])) {
                            $counts['skipped']++;

                            continue;
                        }

                        $molecule = $this->moleculeData($prediction, array_keys($ccfFiles));
                        $fingerprint = md5(json_encode([$resultFile->hash ?: $resultFile->path, $ccfFiles, $molecule]));
                        $previousRow = isset($previous[$archive]) ? explode("\t", $previous[$archive]) : null;
                        $isUnchanged = $previousRow !== null && $previousRow[0] === $fingerprint;

                        // logK/logPerm come from cosmo.xml, which is part of the fingerprint, so unchanged rows reuse them.
                        $values = $isUnchanged
                            ? ['logK' => $previousRow[1], 'logPerm' => $previousRow[2]]
                            : $this->resultValues($prediction);

                        if ($values === null) {
                            $counts['skipped']++;

                            continue;
                        }

                        $exported[$archive] = true;
                        $counts[$isUnchanged ? 'unchanged' : ($previousRow === null ? 'added' : 'updated')]++;
                        $this->writeManifestRow($workFiles['manifest'], array_merge($molecule, $values, [
                            'archive' => "archives/{$archive}",
                            'prediction_id' => $prediction->id,
                            'ccf_count' => count($ccfFiles),
                            'fingerprint' => $fingerprint,
                        ]));

                        if (! $isUnchanged && ! $isDryRun) {
                            $files = ["{$resultsRoot}/{$resultFile->path}" => 'cosmo.xml']
                                + collect($ccfFiles)->keys()->mapWithKeys(fn (string $name): array => ["{$resultsRoot}/{$folder}/{$name}" => $name])->all();
                            $this->addJob($workFiles, $work, $archive, array_merge($molecule, $values), $files);
                        }
                    }

                    return true;
                });

            $bar->finish();
            $this->newLine(2);

            // With --limit only a part of the predictions is seen, nothing can be considered removed.
            if ($limit === null) {
                foreach (array_keys($previous) as $archive) {
                    if (! isset($exported[$archive])) {
                        fwrite($workFiles['delete'], "{$archive}\n");
                        $counts['removed']++;
                    }
                }
            }

            $counts['total'] = count($exported);
            unset($previous, $exported);

            if ($isDryRun) {
                return ['date' => $date, 'export_path' => "{$exportRoot}/cosmo_export.zip", 'size' => 0, 'counts' => $counts];
            }

            $this->step = 'uploading the work files';
            $this->line('Uploading the work files ('.number_format($workFiles['count']).' archives to build)...');
            $this->uploadWorkFiles($shell, $work, $workFiles, $manifestName);
        } finally {
            $this->removeWorkFiles($workFiles);
        }

        $this->step = 'building the archives on the backup server';
        $this->line('Building the archives on the backup server...');
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
     * Local temporary work files, filled while the predictions are processed so that nothing
     * proportional to the number of predictions has to be held in memory.
     *
     * @return array{directory: string, molecules: resource, jobs: resource, delete: resource, manifest: resource, count: int}
     */
    private function openWorkFiles(string $manifestName): array
    {
        $directory = sys_get_temp_dir().'/cosmo-export-'.getmypid().'-'.now()->format('Ymd_His');

        if (! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create the local work directory [{$directory}].");
        }

        $molecules = fopen("{$directory}/molecules.tar", 'w');
        $jobs = fopen("{$directory}/jobs.tsv", 'w');
        $delete = fopen("{$directory}/delete.txt", 'w');
        $manifest = fopen("{$directory}/{$manifestName}", 'w');

        if ($molecules === false || $jobs === false || $delete === false || $manifest === false) {
            throw new RuntimeException('Unable to create the local work files.');
        }

        fputcsv($manifest, self::MANIFEST_COLUMNS, ',', '"', '');

        return ['directory' => $directory, 'molecules' => $molecules, 'jobs' => $jobs, 'delete' => $delete, 'manifest' => $manifest, 'count' => 0];
    }

    /**
     * @param  array{directory: string, molecules: resource, jobs: resource, delete: resource, manifest: resource, count: int}  $workFiles
     * @param  array<string, mixed>  $molecule
     * @param  array<string, string>  $files  absolute source path => name in the archive
     */
    private function addJob(array &$workFiles, string $work, string $archive, array $molecule, array $files): void
    {
        $index = ++$workFiles['count'];
        $this->writeTarEntry($workFiles['molecules'], "{$index}.json", (string) json_encode($molecule, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        fwrite($workFiles['jobs'], "{$archive}\t{$work}/molecules/{$index}.json\tmolecule.json\n");

        foreach ($files as $source => $name) {
            fwrite($workFiles['jobs'], "{$archive}\t{$source}\t{$name}\n");
        }
    }

    /**
     * Appends one file to a ustar archive - written as a stream, so memory does not grow with the archive.
     *
     * @param  resource  $handle
     */
    private function writeTarEntry($handle, string $name, string $contents): void
    {
        $header = str_pad($name, 100, "\0")
            .sprintf('%07o', 0644)."\0"
            .sprintf('%07o', 0)."\0"
            .sprintf('%07o', 0)."\0"
            .sprintf('%011o', strlen($contents))."\0"
            .sprintf('%011o', time())."\0"
            .str_repeat(' ', 8)
            .'0'
            .str_repeat("\0", 100)
            ."ustar\0".'00'
            .str_repeat("\0", 32 + 32 + 8 + 8 + 155);
        $header = str_pad($header, 512, "\0");
        $checksum = array_sum(array_map('ord', str_split($header)));
        $header = substr_replace($header, sprintf('%06o', $checksum)."\0 ", 148, 8);

        fwrite($handle, $header.$contents.str_repeat("\0", (512 - strlen($contents) % 512) % 512));
    }

    /**
     * @param  resource  $handle
     * @param  array<string, mixed>  $row
     */
    private function writeManifestRow($handle, array $row): void
    {
        $row['temperature'] = number_format((float) $row['temperature'], 1, '.', '');
        fputcsv($handle, array_map(fn (string $column): mixed => $row[$column] ?? null, self::MANIFEST_COLUMNS), ',', '"', '');
    }

    /**
     * @param  array{directory: string, molecules: resource, jobs: resource, delete: resource, manifest: resource, count: int}  $workFiles
     */
    private function uploadWorkFiles(RemoteShell $shell, string $work, array &$workFiles, string $manifestName): void
    {
        $directory = $workFiles['directory'];
        $this->closeWorkFiles($workFiles);

        foreach (['molecules.tar', 'jobs.tsv', 'delete.txt', $manifestName] as $name) {
            $shell->putFile("{$work}/{$name}", "{$directory}/{$name}");
        }
    }

    /**
     * @param  array{directory: string, molecules: resource, jobs: resource, delete: resource, manifest: resource, count: int}  $workFiles
     */
    private function closeWorkFiles(array &$workFiles): void
    {
        if (is_resource($workFiles['molecules'])) {
            // End of the tar archive: two empty blocks.
            fwrite($workFiles['molecules'], str_repeat("\0", 1024));
        }

        foreach (['molecules', 'jobs', 'delete', 'manifest'] as $key) {
            if (is_resource($workFiles[$key])) {
                fclose($workFiles[$key]);
            }
        }
    }

    /**
     * @param  array{directory: string, molecules: resource, jobs: resource, delete: resource, manifest: resource, count: int}  $workFiles
     */
    private function removeWorkFiles(array &$workFiles): void
    {
        $this->closeWorkFiles($workFiles);

        foreach (glob("{$workFiles['directory']}/*") ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($workFiles['directory']);
    }

    /**
     * Newest manifest as archive (relative to archives/) => "fingerprint<TAB>logK<TAB>logPerm".
     * Kept compact, it is the only per-archive data held in memory for the whole run.
     *
     * @return array<string, string>
     */
    private function previousManifest(RemoteShell $shell, string $exportRoot, string $localDirectory): array
    {
        $manifests = array_filter($shell->list("{$exportRoot}/manifests"), fn (string $name): bool => (bool) preg_match('/^manifest_\d{4}-\d{2}-\d{2}\.csv$/', $name));

        if ($manifests === []) {
            return [];
        }

        rsort($manifests);
        $localPath = "{$localDirectory}/previous_manifest.csv";
        $shell->getFile("{$exportRoot}/manifests/{$manifests[0]}", $localPath);

        $handle = fopen($localPath, 'r') ?: throw new RuntimeException('Unable to read the previous manifest.');
        $header = array_flip(fgetcsv($handle, escape: '') ?: []);
        $rows = [];

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            if (! isset($row[$header['archive']])) {
                continue;
            }

            $rows[substr($row[$header['archive']], strlen('archives/'))] = implode("\t", [
                $row[$header['fingerprint']] ?? '',
                $row[$header['logK']] ?? '',
                $row[$header['logPerm']] ?? '',
            ]);
        }

        fclose($handle);
        @unlink($localPath);

        return $rows;
    }

    /**
     * COSMO conformer files of the given prediction structures grouped by their result folder, name => hash.
     *
     * @param  array<int, int>  $structureIds
     * @return array<string, array<string, string>>
     */
    private function cosmoFilesForStructures(string $diskName, array $structureIds): array
    {
        $files = [];

        PredictionFile::query()
            ->where('type', PredictionFile::TYPE_COSMO_CONFORMERS)
            ->where('storage', $diskName)
            ->whereIn(DB::raw("split_part(path, '/', 1)"), array_map('strval', $structureIds))
            ->orderBy('path')
            ->toBase()
            ->get(['path', 'hash'])
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
