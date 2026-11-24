<?php

require_once __DIR__.'/remote_prediction_test_helpers.php';

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\PredictionWorkers\Enums\RemotePredictionStep;
use Modules\PredictionWorkers\Exceptions\RemotePredictionException;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionFile;
use Modules\PredictionWorkers\Models\PredictionResult;

/*
 * A prediction's remote state: refreshing it from status snapshots,
 * requeueing (also forced from a reached step) and submitting into a
 * membrane the service does not know yet.
 */

beforeEach(function () {
    enableRemotePredictionService();
});

/**
 * @param  array<string, mixed>  $snapshot
 * @return array<string, mixed>
 */
function remoteSnapshot(array $snapshot = []): array
{
    return [
        'id' => 'mol-1',
        'canonical_smiles' => 'CCO',
        'status' => 'running',
        'steps' => [],
        'conformers' => [],
        'calculations' => [],
        'events' => [],
        ...$snapshot,
    ];
}

function remoteMembraneKey(Prediction $prediction): string
{
    return Str::slug($prediction->predictionMembrane->abbreviation);
}

test('a paused prediction keeps its stored state when refreshed', function () {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), [
        'remote_calculation_id' => 'calc-1',
        'remote_status' => 'queued',
        'remote_paused_at' => now()->subHour(),
    ]);
    fakePredictionHttpRoutes(['https://predictor.test/jobs/status' => Http::response(remoteSnapshot(['status' => 'completed']))]);

    $prediction->refreshRemotePredictionState();
    $prediction->refresh();

    expect($prediction->remote_status)->toBe('queued')
        ->and($prediction->remote_last_status_at)->toBeNull()
        ->and($prediction->effectiveRemoteStatus())->toBe('paused')
        ->and($prediction->effectiveRemoteStatusLabel())->toBe('Paused')
        ->and($prediction->effectiveStateLabel())->toBe('Paused');
});

test('a running conformer optimization sets the optimization step and the newest heartbeat', function () {
    ['prediction' => $prediction, 'structure' => $structure] = createPredictionDataset(User::factory()->create(), [
        'remote_calculation_id' => 'calc-1',
        'remote_status' => 'waiting_for_conformers',
        'step' => Prediction::STEP_SDF_READY,
    ]);
    fakePredictionHttpRoutes(['https://predictor.test/jobs/status' => Http::response(remoteSnapshot([
        'status' => 'waiting_for_conformers',
        'current_step' => 'conformers',
        'steps' => [
            ['step' => 'rdkit', 'status' => 'completed', 'heartbeat_at' => '2026-11-21T07:00:00+00:00'],
            ['step' => 'conformers', 'status' => 'completed'],
        ],
        'conformers' => [
            ['id' => 'conf-0', 'conformer_index' => 0, 'status' => 'running', 'steps' => [
                ['step' => 'optimization-orca', 'status' => 'completed', 'heartbeat_at' => '2026-11-21T09:00:00+00:00'],
                ['step' => 'optimization-turbomole', 'status' => 'running', 'heartbeat_at' => '2026-11-21T09:30:00+00:00'],
            ]],
            ['id' => 'conf-1', 'conformer_index' => 1, 'status' => 'running', 'steps' => [
                ['step' => 'optimization-orca', 'status' => 'running', 'heartbeat_at' => '2026-11-21T09:45:00+00:00'],
            ]],
        ],
        'calculations' => [['id' => 'calc-1', 'membrane_key' => 'dopc', 'temperature_c' => 25.0, 'status' => 'pending']],
        'events' => [['type' => 'STEP', 'message' => 'Optimization started.', 'created_at' => '2026-11-21T09:00:00+00:00']],
    ]))]);

    $prediction->refreshRemotePredictionState();
    $prediction->refresh();

    expect($prediction->remote_status)->toBe('waiting_for_conformers')
        ->and($prediction->remote_current_step)->toBe('optimization-turbomole')
        ->and($prediction->step)->toBe(Prediction::STEP_OPTIMIZATION)
        ->and($prediction->state)->toBe(Prediction::STATE_RUNNING)
        ->and($prediction->remote_heartbeat_at->toIso8601String())->toBe('2026-11-21T09:45:00+00:00')
        ->and($prediction->remote_finished_at)->toBeNull()
        ->and($prediction->remote_error_message)->toBeNull()
        ->and($prediction->logs)->toBe([['type' => 'STEP', 'message' => 'Optimization started.', 'created_at' => '2026-11-21T09:00:00.000000Z']])
        ->and($structure->refresh()->remote_molecule_status)->toBe('waiting_for_conformers');
});

test('a running molecule step maps to its local step with that step heartbeat', function (string $remoteStep, int $localStep) {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), [
        'remote_calculation_id' => 'calc-1',
        'remote_status' => 'pending',
        'step' => Prediction::STEP_PENDING,
    ]);
    fakePredictionHttpRoutes(['https://predictor.test/jobs/status' => Http::response(remoteSnapshot([
        'status' => 'running',
        'steps' => [['step' => $remoteStep, 'status' => 'running', 'heartbeat_at' => '2026-11-21T08:15:00+00:00']],
    ]))]);

    $prediction->refreshRemotePredictionState();
    $prediction->refresh();

    expect($prediction->remote_current_step)->toBe($remoteStep)
        ->and($prediction->step)->toBe($localStep)
        ->and($prediction->remote_status)->toBe('running')
        ->and($prediction->remote_heartbeat_at->toIso8601String())->toBe('2026-11-21T08:15:00+00:00');
})->with([
    'RDKit' => ['rdkit', Prediction::STEP_SDF_READY],
    'conformer search' => ['conformers', Prediction::STEP_OPTIMIZATION],
]);

test('a queued COSMO calculation found by membrane and temperature moves the prediction to the COSMO step', function () {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), [
        'remote_calculation_id' => null,
        'remote_status' => null,
        'step' => Prediction::STEP_OPTIMIZATION,
    ]);
    fakePredictionHttpRoutes(['https://predictor.test/jobs/status' => Http::response(remoteSnapshot([
        'status' => 'completed',
        'calculations' => [
            ['id' => 'calc-other', 'molecule_id' => 'mol-1', 'membrane_key' => remoteMembraneKey($prediction), 'temperature_c' => 37.0, 'status' => 'completed'],
            ['id' => 'calc-25', 'molecule_id' => 'mol-1', 'membrane_key' => remoteMembraneKey($prediction), 'temperature_c' => 25.0, 'status' => 'queued', 'heartbeat_at' => '2026-11-21T10:00:00+00:00'],
        ],
    ]))]);

    $prediction->refreshRemotePredictionState();
    $prediction->refresh();

    expect($prediction->remote_calculation_id)->toBe('calc-25')
        ->and($prediction->remote_molecule_id)->toBe('mol-1')
        ->and($prediction->remote_method)->toBe('cosmoperm')
        ->and($prediction->remote_status)->toBe('queued')
        ->and($prediction->remote_current_step)->toBe('cosmo')
        ->and($prediction->step)->toBe(Prediction::STEP_COSMO)
        ->and($prediction->remote_heartbeat_at->toIso8601String())->toBe('2026-11-21T10:00:00+00:00');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://predictor.test/jobs/status'
        && $request['membrane_key'] === remoteMembraneKey($prediction)
        && $request['temperature_c'] === 25.0
        && $request['events_limit'] === 100);
});

test('a requeue resets the result and continues from the step the service reports', function () {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), [
        'remote_calculation_id' => 'calc-1',
        'remote_status' => 'failed',
        'state' => Prediction::STATE_ERROR,
        'remote_error_message' => 'Turbomole crashed.',
        'remote_finished_at' => now()->subHour(),
    ]);
    fakePredictionHttpRoutes(['https://predictor.test/jobs/requeue' => Http::response(remoteSnapshot([
        'status' => 'queued',
        'requeue' => ['from_step' => 'optimization-turbomole', 'requeued_at' => '2026-11-21T10:00:00+00:00'],
    ]))]);

    $snapshot = $prediction->requeueAndStoreRemotePrediction();
    $prediction->refresh();

    expect($snapshot->requeue['requeued_at']->toIso8601String())->toBe('2026-11-21T10:00:00+00:00')
        ->and($prediction->state)->toBe(Prediction::STATE_RUNNING)
        ->and($prediction->remote_status)->toBe('queued')
        ->and($prediction->remote_current_step)->toBe('optimization-turbomole')
        ->and($prediction->step)->toBe(Prediction::STEP_OPTIMIZATION)
        ->and($prediction->remote_error_message)->toBeNull()
        ->and($prediction->remote_finished_at)->toBeNull()
        ->and(collect($prediction->logs)->last())->toMatchArray([
            'type' => 'REMOTE REQUEUE',
            'message' => 'Remote prediction requeued.',
            'payload' => ['step' => 'optimization-turbomole', 'force' => false, 'previous_result_id' => null],
        ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://predictor.test/jobs/requeue'
        && $request->data() === [
            'smiles' => $prediction->predictionStructure->canonical_smiles,
            'membrane_key' => remoteMembraneKey($prediction),
            'temperature_c' => 25.0,
        ]);
});

test('a requeue without step information continues from the first failed step', function (array $snapshot, array $attributes, string $expectedStep) {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), [
        'remote_calculation_id' => 'calc-1',
        'state' => Prediction::STATE_ERROR,
        ...$attributes,
    ]);
    fakePredictionHttpRoutes(['https://predictor.test/jobs/requeue' => Http::response(remoteSnapshot($snapshot))]);

    $prediction->requeueAndStoreRemotePrediction();

    expect($prediction->refresh()->remote_current_step)->toBe($expectedStep);
})->with([
    'failed molecule and conformer steps' => [[
        'steps' => [['step' => 'conformers', 'status' => 'failed']],
        'conformers' => [['id' => 'c0', 'steps' => [['step' => 'optimization-orca', 'status' => 'failed']]]],
    ], [], 'conformers'],
    'failed conformer step' => [[
        'steps' => [['step' => 'rdkit', 'status' => 'completed']],
        'conformers' => [['id' => 'c0', 'steps' => [['step' => 'optimization-turbomole', 'status' => 'failed']]]],
    ], [], 'optimization-turbomole'],
    'failed calculation' => [[
        'calculations' => [['id' => 'calc-1', 'status' => 'failed']],
    ], [], 'cosmo'],
    'only the current step' => [['current_step' => 'conformers'], [], 'conformers'],
    'nothing, stored remote step' => [[], ['remote_current_step' => 'optimization-orca'], 'optimization-orca'],
    'nothing, local step' => [[], ['step' => Prediction::STEP_SDF_READY], 'conformers'],
]);

test('the last remote step for a requeue falls back to the local step', function (?string $remoteStep, int $step, RemotePredictionStep $expected) {
    $prediction = (new Prediction)->forceFill(['remote_current_step' => $remoteStep, 'step' => $step]);

    expect($prediction->lastRemotePredictionStepForRequeue())->toBe($expected);
})->with([
    'stored remote step' => ['optimization-orca', Prediction::STEP_PENDING, RemotePredictionStep::OPTIMIZATION_ORCA],
    'unknown remote step' => ['xtb', Prediction::STEP_COSMO, RemotePredictionStep::COSMO],
    'result download' => [null, Prediction::STEP_RESULT_DOWNLOAD, RemotePredictionStep::COSMO],
    'optimization' => [null, Prediction::STEP_OPTIMIZATION, RemotePredictionStep::OPTIMIZATION_TURBOMOLE],
    'SDF ready' => [null, Prediction::STEP_SDF_READY, RemotePredictionStep::CONFORMERS],
    'pending' => [null, Prediction::STEP_PENDING, RemotePredictionStep::RDKIT],
]);

test('force requeue offers only the steps the prediction has reached', function () {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-1']);
    fakePredictionHttpRoutes(['https://predictor.test/jobs/status' => Http::response(remoteSnapshot([
        'steps' => [
            ['step' => 'rdkit', 'status' => 'completed'],
            ['step' => 'conformers', 'status' => 'pending', 'attempts' => 0],
        ],
        'conformers' => [[
            'id' => 'c0',
            'current_step' => 'optimization-turbomole',
            'steps' => [
                ['step' => 'optimization-orca', 'status' => 'pending', 'attempts' => 1],
            ],
        ]],
        'calculations' => [['id' => 'calc-1', 'status' => 'failed']],
    ]))]);

    expect($prediction->forceRequeueStepOptions())->toBe([
        'rdkit' => 'RDKit',
        'optimization-orca' => 'Structure optimization (ORCA)',
        'optimization-turbomole' => 'Structure optimization (Turbomole)',
        'cosmo' => 'COSMO calculation',
    ]);
});

test('a forced requeue from a reached step clears the previous result', function () {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-1', 'state' => Prediction::STATE_FINISHED]);
    $file = PredictionFile::query()->create(['type' => PredictionFile::TYPE_RESULT_COSMO_XML, 'mime' => 'application/xml', 'storage' => 'local', 'path' => 'r/cosmo.xml', 'hash' => md5('r')]);
    $result = PredictionResult::query()->create(['file_id' => $file->id, 'data' => []]);
    $prediction->forceFill(['result_id' => $result->id])->save();
    fakePredictionHttpRoutes([
        'https://predictor.test/jobs/status' => Http::response(remoteSnapshot(['current_step' => 'conformers'])),
        'https://predictor.test/jobs/requeue/conformers' => Http::response(remoteSnapshot(['status' => 'queued'])),
    ]);

    $prediction->requeueAndStoreRemotePrediction(RemotePredictionStep::CONFORMERS, force: true);
    $prediction->refresh();

    expect($prediction->result_id)->toBeNull()
        ->and($prediction->state)->toBe(Prediction::STATE_RUNNING)
        ->and($prediction->remote_current_step)->toBe('conformers')
        ->and($prediction->step)->toBe(Prediction::STEP_OPTIMIZATION)
        ->and(collect($prediction->logs)->last()['message'])->toBe('Remote prediction force requeued.')
        ->and(collect($prediction->logs)->last()['payload'])->toBe(['step' => 'conformers', 'force' => true, 'previous_result_id' => $result->id]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://predictor.test/jobs/requeue/conformers'
        && $request['force'] === true);
});

test('a forced requeue needs a reached target step', function (?RemotePredictionStep $step, string $message) {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-1']);
    fakePredictionHttpRoutes(['https://predictor.test/jobs/status' => Http::response(remoteSnapshot())]);

    expect(fn () => $prediction->requeueAndStoreRemotePrediction($step, force: true))->toThrow(RuntimeException::class, $message);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/jobs/requeue'));
    expect($prediction->refresh()->state)->toBe(Prediction::STATE_RUNNING);
})->with([
    'no step' => [null, 'A target step is required'],
    'unreached step' => [RemotePredictionStep::COSMO, 'Remote step [cosmo] has not been reached'],
]);

test('a submission into a membrane unknown to the service uploads the membrane and retries', function () {
    $disk = 'prediction-membranes';
    Storage::fake($disk);
    Storage::disk($disk)->put('dopc.mic', 'membrane definition');
    ['prediction' => $prediction, 'membrane' => $membrane] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED]);
    $file = PredictionFile::query()->create(['type' => PredictionFile::TYPE_MEMBARNE_COSMO, 'mime' => 'text/plain', 'storage' => $disk, 'path' => 'dopc.mic', 'hash' => md5('membrane definition')]);
    $membrane->forceFill(['file_id' => $file->id])->save();
    $jobs = 0;

    fakePredictionHttpRoutes([
        // A stale listing still offers the membrane under its old key.
        'https://predictor.test/membranes/' => Http::response(['key' => 'dopc-v2', 'md5' => md5('membrane definition'), 'file_valid' => true]),
        'https://predictor.test/membranes' => Http::response(['membranes' => [['key' => 'dopc-old', 'md5' => md5('membrane definition'), 'file_valid' => true]]]),
        'https://predictor.test/jobs' => function () use (&$jobs) {
            return ++$jobs === 1
                ? Http::response(['detail' => 'Unknown membrane key: dopc-old'], 422)
                : Http::response(['molecule_id' => 'mol-1', 'calculation_id' => 'calc-1', 'molecule_status' => 'queued', 'calculation_status' => 'queued']);
        },
    ]);

    $submission = $prediction->submitAndStoreRemotePrediction('dopc-v2');

    expect($submission->calculationId)->toBe('calc-1')
        ->and($prediction->refresh()->remote_calculation_id)->toBe('calc-1');

    $jobRequests = Http::recorded(fn (Request $request): bool => $request->url() === 'https://predictor.test/jobs')
        ->map(fn (array $pair): string => $pair[0]['membrane_key'])
        ->values()
        ->all();
    expect($jobRequests)->toBe(['dopc-old', 'dopc-v2']);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://predictor.test/membranes/dopc-v2'
        && $request->body() === 'membrane definition');
});

test('a submission error other than an unknown membrane is not retried', function (string $detail) {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED]);
    fakePredictionHttpRoutes(['https://predictor.test/jobs' => Http::response(['detail' => $detail], 422)]);

    expect(fn () => $prediction->submitAndStoreRemotePrediction())->toThrow(RemotePredictionException::class, $detail);

    Http::assertSentCount(1);
    expect($prediction->refresh()->state)->toBe(Prediction::STATE_PREPARED);
})->with([
    'another error' => 'SMILES could not be parsed.',
    // Without a definition file there is nothing to upload.
    'unknown membrane without a definition file' => 'Unknown membrane key: dopc',
]);
