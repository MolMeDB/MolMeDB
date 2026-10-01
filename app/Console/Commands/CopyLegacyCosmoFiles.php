<?php

namespace App\Console\Commands;

use App\Models\Filesystem;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\StorageAttributes;
use Modules\PredictionWorkers\Models\Prediction;
use RuntimeException;
use Throwable;

/**
 * One-off: the legacy prediction workflow kept the conformer COSMO files (.ccf) only on the old
 * MetaCentrum storage ({bucket}/{molecule_id}/{ion_id}/03-COSMO_INPUT/*.ccf). Legacy ions are
 * paired with prediction structures by SMILES using the ion map exported from the legacy database,
 * and every pairing is checked against the conformer path stored in the legacy cosmo.xml.
 */
class CopyLegacyCosmoFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'legacy:copy-cosmo-files
        {--execute : Copy the files (without it only a dry run listing the files is made)}
        {--id=* : Only these prediction IDs}
        {--limit= : Process at most this many predictions}
        {--source-root=/storage/brno12-cerit/home/xjur2k/.MolMeDB/COSMO : Legacy COSMO folder on the MetaCentrum storage}
        {--ion-map=database/legacy/cosmo_ions.csv : Legacy ion map (ion_id, fragment_id, smiles)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'One-off: copies .ccf files of legacy predictions from the old MetaCentrum storage next to their cosmo.xml.';

    /** @var array<string, array{predictions: int, files: int, bytes: int}> */
    private array $summary = [];

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $ionMap = (string) $this->option('ion-map');
        $ions = $this->loadIonMap(str_starts_with($ionMap, '/') ? $ionMap : base_path($ionMap));
        $source = $this->legacyDisk((string) $this->option('source-root'));
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $query = Prediction::query()
            ->where('state', Prediction::STATE_FINISHED)
            ->whereNotNull('result_id')
            ->whereNull('remote_calculation_id')
            ->when($this->option('id'), fn ($query, array $ids) => $query->whereIn('id', array_map('intval', $ids)))
            ->with(['predictionStructure', 'predictionResult.file']);

        $total = min($query->count(), $limit ?? PHP_INT_MAX);
        $this->warn(($execute ? 'EXECUTE' : 'DRY RUN').": {$total} legacy predictions will be checked.");

        $reportPath = storage_path('app/legacy-cosmo-files/report-'.now()->format('Ymd_His').'.csv');
        @mkdir(dirname($reportPath), 0775, true);
        $report = fopen($reportPath, 'w');
        fputcsv($report, ['prediction_id', 'structure_id', 'ion_id', 'status', 'files', 'bytes', 'message'], ',', '"', '');

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $predictions = $query->lazyById(500);

        foreach ($limit !== null ? $predictions->take($limit) : $predictions as $prediction) {
            try {
                $row = $this->processPrediction($prediction, $ions, $source, $execute);
            } catch (Throwable $throwable) {
                $row = ['status' => 'error', 'ion_id' => null, 'files' => 0, 'bytes' => 0, 'message' => $throwable->getMessage()];
            }

            $this->summary[$row['status']]['predictions'] = ($this->summary[$row['status']]['predictions'] ?? 0) + 1;
            $this->summary[$row['status']]['files'] = ($this->summary[$row['status']]['files'] ?? 0) + $row['files'];
            $this->summary[$row['status']]['bytes'] = ($this->summary[$row['status']]['bytes'] ?? 0) + $row['bytes'];

            fputcsv($report, [
                $prediction->id,
                $prediction->structure_id,
                $row['ion_id'],
                $row['status'],
                $row['files'],
                $row['bytes'],
                $row['message'],
            ], ',', '"', '');

            $bar->advance();
        }

        $bar->finish();
        fclose($report);
        $this->newLine(2);

        ksort($this->summary);
        $this->table(['status', 'predictions', 'files', 'MB'], collect($this->summary)->map(fn (array $stats, string $status): array => [
            $status,
            $stats['predictions'],
            $stats['files'],
            number_format($stats['bytes'] / 1048576, 1),
        ])->values()->all());

        $this->info("Report: {$reportPath}");

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, array{ion_id: int, fragment_id: int}>  $ions
     * @return array{status: string, ion_id: ?int, files: int, bytes: int, message: ?string}
     */
    private function processPrediction(Prediction $prediction, array $ions, FilesystemAdapter $source, bool $execute): array
    {
        if ($prediction->hasStoredCosmoFiles()) {
            return ['status' => 'already_stored', 'ion_id' => null, 'files' => 0, 'bytes' => 0, 'message' => null];
        }

        $ion = $ions[(string) $prediction->predictionStructure?->canonical_smiles] ?? null;

        if ($ion === null) {
            return ['status' => 'no_legacy_ion', 'ion_id' => null, 'files' => 0, 'bytes' => 0, 'message' => $prediction->predictionStructure?->canonical_smiles];
        }

        $ionDirectory = $this->legacyIonDirectory($ion['fragment_id'], $ion['ion_id']);

        // Independent check of the SMILES pairing: the legacy cosmo.xml names the conformer files it was computed from.
        $resultDirectory = $this->legacyIonDirectoryFromResult($prediction);

        if ($resultDirectory === null) {
            return ['status' => 'unverified', 'ion_id' => $ion['ion_id'], 'files' => 0, 'bytes' => 0, 'message' => 'cosmo.xml does not reference legacy conformer files'];
        }

        if ($resultDirectory !== $ionDirectory) {
            return ['status' => 'mapping_mismatch', 'ion_id' => $ion['ion_id'], 'files' => 0, 'bytes' => 0, 'message' => "SMILES pairing {$ionDirectory}, cosmo.xml {$resultDirectory}"];
        }

        $directory = "{$ionDirectory}/03-COSMO_INPUT";

        if (! $source->directoryExists($directory)) {
            return ['status' => 'no_ccf', 'ion_id' => $ion['ion_id'], 'files' => 0, 'bytes' => 0, 'message' => "{$directory} does not exist"];
        }

        $filenamePrefix = "{$ion['fragment_id']}_{$ion['ion_id']}_";
        $files = collect($source->getDriver()->listContents($directory, false))
            ->filter(fn (StorageAttributes $item): bool => $item->isFile()
                && str_starts_with(basename($item->path()), $filenamePrefix)
                && strtolower(pathinfo($item->path(), PATHINFO_EXTENSION)) === 'ccf')
            ->mapWithKeys(fn (StorageAttributes $item): array => [$item->path() => (int) $item->fileSize()])
            ->sortKeys(SORT_NATURAL)
            ->all();

        if ($files === []) {
            return ['status' => 'no_ccf', 'ion_id' => $ion['ion_id'], 'files' => 0, 'bytes' => 0, 'message' => "{$directory} has no .ccf files"];
        }

        $bytes = array_sum($files);

        if (! $execute) {
            return ['status' => 'would_copy', 'ion_id' => $ion['ion_id'], 'files' => count($files), 'bytes' => $bytes, 'message' => implode(' ', array_map('basename', array_keys($files)))];
        }

        $contents = [];

        foreach (array_keys($files) as $path) {
            $contents[basename($path)] = $source->get($path) ?? throw new RuntimeException("Unable to read legacy file [{$path}].");
        }

        $prediction->storeCosmoFiles($contents, 'legacy MetaCentrum storage');

        return ['status' => 'copied', 'ion_id' => $ion['ion_id'], 'files' => count($files), 'bytes' => $bytes, 'message' => null];
    }

    /**
     * The legacy cosmo.xml contains the absolute path of its conformers, e.g.
     * ".../COSMO/1530000-1540000/1536225/6/03-COSMO_INPUT/1536225_6_0.ccf".
     */
    private function legacyIonDirectoryFromResult(Prediction $prediction): ?string
    {
        $resultFile = $prediction->predictionResult->file;
        $xml = Storage::disk($resultFile->storage)->get($resultFile->path) ?? '';

        if (! preg_match_all('#/COSMO/(\d+-\d+/\d+/\d+)/03-COSMO_INPUT/#', $xml, $matches)) {
            return null;
        }

        $directories = array_unique($matches[1]);

        return count($directories) === 1 ? reset($directories) : 'ambiguous: '.implode(', ', $directories);
    }

    /**
     * Legacy layout: {from}-{to}/{molecule_id}/{ion_id}, buckets of 10 000 molecule IDs.
     */
    private function legacyIonDirectory(int $fragmentId, int $ionId): string
    {
        $bucketStart = intdiv($fragmentId, 10000) * 10000;

        return $bucketStart.'-'.($bucketStart + 10000)."/{$fragmentId}/{$ionId}";
    }

    /**
     * @return array<string, array{ion_id: int, fragment_id: int}> keyed by ion SMILES
     */
    private function loadIonMap(string $path): array
    {
        $handle = fopen($path, 'r') ?: throw new RuntimeException("Unable to open ion map [{$path}].");
        $header = fgetcsv($handle, escape: '');
        $ions = [];

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $row = array_combine($header, $row);
            $ions[$row['smiles']] = ['ion_id' => (int) $row['ion_id'], 'fragment_id' => (int) $row['fragment_id']];
        }

        fclose($handle);

        return $ions;
    }

    /**
     * The MetaCentrum filesystem is configured for the current storage, the legacy data live under another root.
     */
    private function legacyDisk(string $root): FilesystemAdapter
    {
        $filesystem = Filesystem::query()
            ->where('type', Filesystem::TYPE_PREDICTIONS_METACENTRUM)
            ->first();

        if (! $filesystem?->isInitialized()) {
            throw new RuntimeException('MetaCentrum predictions filesystem is not configured.');
        }

        $config = config('filesystems.disks.'.$filesystem->systemName);

        if (($config['driver'] ?? null) !== 'sftp') {
            throw new RuntimeException("MetaCentrum predictions filesystem [{$filesystem->name}] must be an unscoped sftp disk.");
        }

        return Storage::build(array_merge($config, ['root' => $root]));
    }
}
