<?php

namespace App\Jobs;

use App\Services\PredictionResultImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionMethod;
use Throwable;

class ImportFinishedPredictionResults implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * How long before the hard $timeout the loop stops picking up new
     * predictions - leaves enough headroom to finish the current item and
     * return normally instead of being killed mid-run by the queue worker.
     */
    private const TIME_BUDGET_MARGIN_SECONDS = 20;

    /**
     * Keeps the unique lock held for the job's entire run, not just until the
     * next dispatch - this is what guarantees only one instance is ever
     * queued-or-running at a time, regardless of how many queue workers exist.
     */
    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue(config('prediction-workers.remote.worker.queue', 'predictions'));
    }

    public function uniqueId(): string
    {
        return 'import-finished-prediction-results';
    }

    public function handle(PredictionResultImporter $importer): void
    {
        $batchSize = max(1, (int) config('prediction-workers.remote.worker.result_import_batch_size', 50));

        $predictions = Prediction::query()
            ->with(['predictionResult', 'predictionStructure', 'predictionMembrane'])
            ->where('step', Prediction::STEP_RESULT_PARSE)
            ->where('state', Prediction::STATE_FINISHED)
            // A failed import logs on the prediction (touching updated_at) and
            // stays at this step, so ordering by the last touch rotates failing
            // predictions to the back instead of letting them fill every batch.
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($batchSize)
            ->get();

        if ($predictions->isEmpty()) {
            return;
        }

        $methods = PredictionMethod::query()
            ->whereIn('key', $predictions->pluck('method_type')->unique())
            ->get()
            ->keyBy('key');

        $imported = 0;
        $duplicates = 0;
        $errors = 0;
        $skipped = 0;

        $deadline = microtime(true) + $this->timeout - self::TIME_BUDGET_MARGIN_SECONDS;

        foreach ($predictions as $prediction) {
            if (microtime(true) >= $deadline) {
                $skipped = $predictions->count() - $imported - $duplicates - $errors;
                break;
            }

            try {
                match ($importer->import($prediction, $methods->get($prediction->method_type))) {
                    'imported' => $imported++,
                    'duplicate' => $duplicates++,
                    default => $errors++,
                };
            } catch (Throwable $e) {
                $errors++;

                $prediction->forceFill([
                    'logs' => $prediction->logsWithWorkerEvent(
                        "Import failed: {$e->getMessage()}",
                        [],
                        'RESULT IMPORT',
                        'error',
                    ),
                ])->save();
            }
        }

        Log::info('ImportFinishedPredictionResults finished.', [
            'processed' => $imported + $duplicates + $errors,
            'imported' => $imported,
            'duplicates' => $duplicates,
            'errors' => $errors,
            'skipped_time_budget' => $skipped,
        ]);
    }
}
