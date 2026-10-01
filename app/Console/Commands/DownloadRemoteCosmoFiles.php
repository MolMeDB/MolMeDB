<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Enumerable;
use Modules\PredictionWorkers\Enums\RemotePredictionStatus;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Services\RemotePrediction\RemotePredictionClient;
use Throwable;

/**
 * One-off: results of the remote prediction pipeline were stored without the conformer COSMO files.
 * Downloads the COSMO artifact of every finished remote prediction and stores its .ccf files next to cosmo.xml.
 */
class DownloadRemoteCosmoFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'predictions:download-cosmo-files
        {--execute : Download the files (without it only the remote calculation states are checked)}
        {--id=* : Only these prediction IDs}
        {--limit= : Process at most this many predictions}
        {--sleep=200 : Pause between downloads in milliseconds}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'One-off: downloads .ccf files of finished remote predictions and stores them next to their cosmo.xml.';

    /** @var array<string, array{predictions: int, files: int}> */
    private array $summary = [];

    public function handle(RemotePredictionClient $client): int
    {
        $execute = (bool) $this->option('execute');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $sleepMicroseconds = max(0, (int) $this->option('sleep')) * 1000;

        $query = Prediction::query()
            ->where('state', Prediction::STATE_FINISHED)
            ->whereNotNull('result_id')
            ->whereNotNull('remote_calculation_id')
            ->when($this->option('id'), fn ($query, array $ids) => $query->whereIn('id', array_map('intval', $ids)))
            ->with(['predictionResult.file']);

        $total = min($query->count(), $limit ?? PHP_INT_MAX);
        $this->warn(($execute ? 'EXECUTE' : 'DRY RUN').": {$total} remote predictions will be checked.");

        $client->ensureValidToken();

        $reportPath = storage_path('app/remote-cosmo-files/report-'.now()->format('Ymd_His').'.csv');
        @mkdir(dirname($reportPath), 0775, true);
        $report = fopen($reportPath, 'w');
        fputcsv($report, ['prediction_id', 'structure_id', 'remote_calculation_id', 'status', 'files', 'message'], ',', '"', '');

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $predictions = $query->lazyById(100);

        foreach (($limit !== null ? $predictions->take($limit) : $predictions)->chunk(100) as $chunk) {
            try {
                $remoteStatuses = $this->remoteStatuses($client, $chunk);
                $statusError = null;
            } catch (Throwable $throwable) {
                $remoteStatuses = [];
                $statusError = $throwable->getMessage();
            }

            foreach ($chunk as $prediction) {
                $row = $statusError === null
                    ? $this->processPrediction($prediction, $client, $remoteStatuses, $execute)
                    : ['status' => 'error', 'files' => 0, 'message' => "remote status check failed: {$statusError}"];

                if ($row['status'] === 'downloaded' && $sleepMicroseconds > 0) {
                    usleep($sleepMicroseconds);
                }

                $this->summary[$row['status']]['predictions'] = ($this->summary[$row['status']]['predictions'] ?? 0) + 1;
                $this->summary[$row['status']]['files'] = ($this->summary[$row['status']]['files'] ?? 0) + $row['files'];

                fputcsv($report, [
                    $prediction->id,
                    $prediction->structure_id,
                    $prediction->remote_calculation_id,
                    $row['status'],
                    $row['files'],
                    $row['message'],
                ], ',', '"', '');

                $bar->advance();
            }
        }

        $bar->finish();
        fclose($report);
        $this->newLine(2);

        ksort($this->summary);
        $this->table(['status', 'predictions', 'files'], collect($this->summary)->map(fn (array $stats, string $status): array => [
            $status,
            $stats['predictions'],
            $stats['files'],
        ])->values()->all());

        $this->info("Report: {$reportPath}");

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, string>  $remoteStatuses  remote calculation ID => status
     * @return array{status: string, files: int, message: ?string}
     */
    private function processPrediction(Prediction $prediction, RemotePredictionClient $client, array $remoteStatuses, bool $execute): array
    {
        try {
            if ($prediction->hasStoredCosmoFiles()) {
                return ['status' => 'already_stored', 'files' => 0, 'message' => null];
            }

            $remoteStatus = $remoteStatuses[(string) $prediction->remote_calculation_id] ?? null;

            if ($remoteStatus === null) {
                return ['status' => 'remote_not_found', 'files' => 0, 'message' => null];
            }

            if ($remoteStatus !== RemotePredictionStatus::COMPLETED->value) {
                return ['status' => 'remote_not_completed', 'files' => 0, 'message' => "remote status {$remoteStatus}"];
            }

            if (! $execute) {
                return ['status' => 'would_download', 'files' => 0, 'message' => null];
            }

            return ['status' => 'downloaded', 'files' => $prediction->storeRemotePredictionCosmoFiles($client), 'message' => null];
        } catch (Throwable $throwable) {
            return ['status' => 'error', 'files' => 0, 'message' => $throwable->getMessage()];
        }
    }

    /**
     * @param  Enumerable<int, Prediction>  $predictions
     * @return array<string, string> remote calculation ID => status
     */
    private function remoteStatuses(RemotePredictionClient $client, Enumerable $predictions): array
    {
        return $client->calculationStatuses($predictions->pluck('remote_calculation_id')->map(fn ($id): string => (string) $id)->all())
            ->mapWithKeys(fn ($calculation): array => [
                $calculation->id => $calculation->status instanceof RemotePredictionStatus ? $calculation->status->value : (string) $calculation->status,
            ])
            ->all();
    }
}
