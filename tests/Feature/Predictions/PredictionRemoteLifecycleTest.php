<?php

require_once __DIR__.'/prediction_helpers.php';

use App\Jobs\CheckPredictionStatus;
use App\Models\Config;
use App\Models\Filesystem;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionFile;
use Modules\PredictionWorkers\Services\RemotePrediction\RemotePredictionClient;

/*
 * A prediction's life on the remote service: submission, status checks and
 * the download of its result, with the service faked over HTTP.
 */

beforeEach(function () {
    Config::set(Config::KEY_REMOTE_PREDICTION_ENABLED, '1');
    Config::set(Config::KEY_REMOTE_PREDICTION_URL, 'https://predictor.test');
    Config::set(Config::KEY_REMOTE_PREDICTION_TOKEN, 'valid-token');
    Config::set(Config::KEY_REMOTE_PREDICTION_TOKEN_EXPIRES_AT, now()->addDays(10)->toIso8601String());
    config()->set('prediction-workers.remote.retry_delays', [0]);
});

/**
 * @param  array<string, mixed>  $calculation
 * @return array<string, mixed>
 */
function remoteJobSnapshot(Prediction $prediction, array $calculation): array
{
    return [
        'id' => 'mol-1',
        'canonical_smiles' => $prediction->predictionStructure->canonical_smiles,
        'status' => 'completed',
        'calculations' => [[
            'id' => 'calc-1',
            'molecule_id' => 'mol-1',
            'membrane_key' => Str::slug($prediction->predictionMembrane->abbreviation),
            'temperature_c' => 25.0,
            ...$calculation,
        ]],
        'events' => [],
    ];
}

function fakePredictionResultsDisk(): string
{
    $filesystem = Filesystem::query()->where('type', Filesystem::TYPE_PREDICTIONS_STORAGE)->firstOrFail();
    config()->set("filesystems.disks.{$filesystem->systemName}", ['driver' => 'local']);
    Storage::fake($filesystem->systemName);

    return $filesystem->systemName;
}

test('a submitted prediction stores the remote ids and runs', function () {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED]);
    Http::fake([
        'https://predictor.test/jobs' => Http::response([
            'molecule_id' => 'mol-1',
            'calculation_id' => 'calc-1',
            'molecule_status' => 'queued',
            'calculation_status' => 'queued',
        ]),
    ]);

    $prediction->submitAndStoreRemotePrediction();
    $prediction->refresh();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://predictor.test/jobs'
        && $request['membrane_key'] === Str::slug($prediction->predictionMembrane->abbreviation));

    expect($prediction->state)->toBe(Prediction::STATE_RUNNING)
        ->and($prediction->remote_calculation_id)->toBe('calc-1')
        ->and($prediction->remote_status)->toBe('queued')
        ->and($prediction->remote_submitted_at)->not->toBeNull();
});

test('a status check moves a completed calculation to the result download', function () {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-1', 'remote_status' => 'running']);
    Http::fake(['https://predictor.test/jobs/status' => Http::response(remoteJobSnapshot($prediction, ['status' => 'completed', 'finished_at' => now()->toIso8601String()]))]);

    (new CheckPredictionStatus($prediction->id))->handle(app(RemotePredictionClient::class));
    $prediction->refresh();

    expect($prediction->remote_status)->toBe('completed')
        ->and($prediction->step)->toBe(Prediction::STEP_RESULT_DOWNLOAD)
        ->and($prediction->remote_finished_at)->not->toBeNull();
});

test('a status check marks a failed calculation with its message', function () {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-1', 'remote_status' => 'running']);
    Http::fake(['https://predictor.test/jobs/status' => Http::response(remoteJobSnapshot($prediction, ['status' => 'failed', 'message' => 'Conformer search failed.']))]);

    (new CheckPredictionStatus($prediction->id))->handle(app(RemotePredictionClient::class));
    $prediction->refresh();

    expect($prediction->state)->toBe(Prediction::STATE_ERROR)
        ->and($prediction->remote_error_message)->toBe('Conformer search failed.');
});

test('a failing status check is recorded on the prediction instead of thrown', function () {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-1', 'remote_status' => 'running']);
    Http::fake(['https://predictor.test/jobs/status' => Http::response(['detail' => 'Database is locked.'], 500)]);

    (new CheckPredictionStatus($prediction->id))->handle(app(RemotePredictionClient::class));
    $prediction->refresh();

    expect($prediction->state)->toBe(Prediction::STATE_RUNNING)
        ->and($prediction->remote_error_message)->toBe('Database is locked.');
});

test('predictions that are not running remotely are not checked', function (array $attributes) {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), $attributes);
    Http::fake();

    (new CheckPredictionStatus($prediction->id))->handle(app(RemotePredictionClient::class));

    Http::assertNothingSent();
})->with([
    'not submitted' => [['remote_calculation_id' => null]],
    'paused' => [['remote_calculation_id' => 'calc-1', 'remote_paused_at' => now()]],
    'finished' => [['remote_calculation_id' => 'calc-1', 'state' => Prediction::STATE_FINISHED]],
    'completed remotely' => [['remote_calculation_id' => 'calc-1', 'remote_status' => 'completed']],
    'failed' => [['remote_calculation_id' => 'calc-1', 'state' => Prediction::STATE_ERROR]],
]);

test('a completed result is stored under the prediction folder whatever file name the service sends', function () {
    $disk = fakePredictionResultsDisk();
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-1', 'remote_status' => 'completed']);
    $cosmoXml = '<micoutput><joblist><job><jobnumber>1</jobnumber><symmetry>planar</symmetry><nrlayer>1</nrlayer><layerposition>0.0</layerposition><temperature>298.15</temperature><solutelist><solute><meanposition>1.5</meanposition><logP_micelle_water>2.75</logP_micelle_water><logPerm_membrane_cm_s>-4.5</logPerm_membrane_cm_s><distribution>0.5</distribution></solute></solutelist></job></joblist></micoutput>';

    Http::fake([
        'https://predictor.test/jobs/status' => Http::response(remoteJobSnapshot($prediction, ['status' => 'completed'])),
        'https://predictor.test/calculations/calc-1/download' => Http::response($cosmoXml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="../../../etc/cron.d/evil file.xml"',
        ]),
    ]);

    $result = $prediction->storeRemotePredictionResult();
    $prediction->refresh();
    $file = PredictionFile::query()->findOrFail($result->file_id);

    expect($prediction->state)->toBe(Prediction::STATE_FINISHED)
        ->and($prediction->result_id)->toBe($result->id)
        ->and($file->path)->toStartWith("{$prediction->predictionStructure->id}/")
        ->and($file->path)->not->toContain('..')
        ->and(basename($file->path))->toBe('evil_file.xml');

    Storage::disk($disk)->assertExists($file->path);
});

test('a result of an unfinished calculation is not downloaded', function () {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-1', 'remote_status' => 'running']);
    Http::fake();

    expect(fn () => $prediction->storeRemotePredictionResult())->toThrow(RuntimeException::class, 'is not completed');
    Http::assertNothingSent();
});
