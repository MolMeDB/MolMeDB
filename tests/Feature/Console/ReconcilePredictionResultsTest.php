<?php

require_once __DIR__.'/../Predictions/prediction_helpers.php';

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionFile;
use Modules\PredictionWorkers\Models\PredictionResult;

/*
 * `predictions:reconcile-results` checks legacy predictions (not owned by the remote
 * pipeline) against their stored result files: a verified result marks the prediction
 * finished, anything else not failed is requeued.
 */

beforeEach(function () {
    $this->resultsRoot = sys_get_temp_dir().'/reconcile-results-test-'.getmypid().'-'.Str::random(6);
    config()->set('filesystems.disks.reconcile-results', ['driver' => 'local', 'root' => $this->resultsRoot]);
    Storage::forgetDisk('reconcile-results');
});

afterEach(function () {
    File::deleteDirectory($this->resultsRoot);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function reconcilablePrediction(array $attributes = [], ?bool $resultFileExists = null): Prediction
{
    if ($resultFileExists !== null) {
        $path = Str::random(8).'/cosmo.xml';

        if ($resultFileExists) {
            Storage::disk('reconcile-results')->put($path, '<micoutput/>');
        }

        $file = PredictionFile::query()->create([
            'type' => PredictionFile::TYPE_RESULT_COSMO_XML,
            'mime' => 'application/xml',
            'storage' => 'reconcile-results',
            'path' => $path,
            'hash' => md5($path),
        ]);
        $attributes['result_id'] = PredictionResult::query()->create(['file_id' => $file->id])->id;
    }

    return createPredictionDataset(null, $attributes)['prediction'];
}

test('predictions are finished or requeued according to their stored result files', function () {
    $verified = reconcilablePrediction(['state' => Prediction::STATE_RUNNING, 'step' => Prediction::STEP_COSMO], resultFileExists: true);
    $alreadyFinished = reconcilablePrediction(['state' => Prediction::STATE_FINISHED, 'step' => Prediction::STEP_RESULT_DB_STORE], resultFileExists: true);
    $missingFile = reconcilablePrediction(['state' => Prediction::STATE_FINISHED, 'step' => Prediction::STEP_RESULT_DB_STORE], resultFileExists: false);
    $running = reconcilablePrediction(['state' => Prediction::STATE_RUNNING, 'step' => Prediction::STEP_OPTIMIZATION]);
    $pending = reconcilablePrediction(['state' => Prediction::STATE_PREPARED, 'step' => Prediction::STEP_PENDING]);
    $failed = reconcilablePrediction(['state' => Prediction::STATE_ERROR, 'step' => Prediction::STEP_COSMO]);
    $remote = reconcilablePrediction(['state' => Prediction::STATE_RUNNING, 'step' => Prediction::STEP_COSMO, 'remote_calculation_id' => 'calc-42']);

    $this->artisan('predictions:reconcile-results')
        ->expectsOutputToContain('Total 6 records will be checked.')
        ->expectsOutputToContain("prediction #{$verified->id}: [FINISHED] verified result file exists.")
        ->expectsOutputToContain("prediction #{$missingFile->id}: [PENDING] no verified result, requeued.")
        ->expectsOutputToContain("prediction #{$running->id}: [PENDING] no verified result, requeued.")
        ->doesntExpectOutputToContain("prediction #{$alreadyFinished->id}:")
        ->doesntExpectOutputToContain("prediction #{$pending->id}:")
        ->doesntExpectOutputToContain("prediction #{$remote->id}:")
        ->expectsOutputToContain('Done. Finished: 2, failed (left as is): 1, queued: 3, unchanged: 3.')
        ->assertSuccessful();

    $stateAndStep = fn (Prediction $prediction): array => [$prediction->refresh()->state, $prediction->step];

    expect($stateAndStep($verified))->toBe([Prediction::STATE_FINISHED, Prediction::STEP_RESULT_DB_STORE])
        ->and($stateAndStep($alreadyFinished))->toBe([Prediction::STATE_FINISHED, Prediction::STEP_RESULT_DB_STORE])
        ->and($stateAndStep($missingFile))->toBe([Prediction::STATE_PREPARED, Prediction::STEP_PENDING])
        ->and($missingFile->result_id)->not->toBeNull()
        ->and($stateAndStep($running))->toBe([Prediction::STATE_PREPARED, Prediction::STEP_PENDING])
        ->and($stateAndStep($pending))->toBe([Prediction::STATE_PREPARED, Prediction::STEP_PENDING])
        ->and($stateAndStep($failed))->toBe([Prediction::STATE_ERROR, Prediction::STEP_COSMO])
        ->and($stateAndStep($remote))->toBe([Prediction::STATE_RUNNING, Prediction::STEP_COSMO]);
});

test('reconciling without legacy predictions changes nothing', function () {
    $remote = reconcilablePrediction(['state' => Prediction::STATE_RUNNING, 'step' => Prediction::STEP_COSMO, 'remote_calculation_id' => 'calc-43']);

    $this->artisan('predictions:reconcile-results')
        ->expectsOutputToContain('Total 0 records will be checked.')
        ->expectsOutputToContain('Done. Finished: 0, failed (left as is): 0, queued: 0, unchanged: 0.')
        ->assertSuccessful();

    expect($remote->refresh()->state)->toBe(Prediction::STATE_RUNNING);
});
