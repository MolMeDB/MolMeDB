<?php

require_once __DIR__.'/prediction_helpers.php';

use App\Console\Commands\Cron\RunPredictionsWorker;
use App\Enums\NotificationType;
use App\Jobs\CheckPredictionStatus;
use App\Models\Config;
use App\Models\Filesystem;
use App\Models\QueuedNotification;
use App\Models\User;
use App\Notifications\TemplatedNotification;
use App\Services\NotificationBatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\PredictionWorkers\Exceptions\RemotePredictionException;
use Modules\PredictionWorkers\Models\Prediction;
use Spatie\Activitylog\Models\Activity;

/*
 * The scheduled remote prediction worker, with the remote service and the
 * RDKit structure validation faked over HTTP.
 */

beforeEach(function () {
    Config::set(Config::KEY_REMOTE_PREDICTION_ENABLED, '1');
    Config::set(Config::KEY_REMOTE_PREDICTION_URL, 'https://predictor.test');
    Config::set(Config::KEY_REMOTE_PREDICTION_TOKEN, 'valid-token');
    Config::set(Config::KEY_REMOTE_PREDICTION_TOKEN_EXPIRES_AT, now()->addDays(10)->toIso8601String());
    config()->set('prediction-workers.remote.retry_delays', [0]);
    config()->set('prediction-workers.remote.manager_secret', 'manager-secret');
    config()->set('services.rdkit.url', 'https://rdkit.test');

    Queue::fake();
    Notification::fake();
});

/**
 * @param  array<string, mixed>  $responses  Further URL patterns faked before the defaults.
 */
function fakeRemotePredictionService(array $responses = [], int $validationStatus = 200, bool $validSmiles = true): void
{
    Http::fake([
        ...$responses,
        'https://rdkit.test/structure/predictions/validate*' => $validationStatus >= 500
            ? Http::response(['detail' => 'down'], $validationStatus)
            : Http::response(['data' => $validSmiles
                ? ['valid' => true, 'canonical_smiles' => 'CCO', 'errors' => []]
                : ['valid' => false, 'canonical_smiles' => null, 'errors' => ['Molecule has too many atoms.']]]),
        'https://predictor.test/jobs' => Http::response([
            'molecule_id' => 'mol-1',
            'calculation_id' => 'calc-1',
            'molecule_status' => 'queued',
            'calculation_status' => 'queued',
        ]),
    ]);
}

function runWorker(array $options = []): int
{
    return test()->artisan(RunPredictionsWorker::class, [
        '--skip-reconcile' => true,
        '--skip-stats' => true,
        '--skip-dispatch' => true,
        ...$options,
    ])->run();
}

test('a disabled remote service makes the worker do nothing', function () {
    Config::set(Config::KEY_REMOTE_PREDICTION_ENABLED, '0');
    Http::fake();

    $this->artisan(RunPredictionsWorker::class)->assertSuccessful();

    Http::assertNothingSent();
});

test('a prepared prediction with a valid structure is submitted', function () {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED]);
    fakeRemotePredictionService();

    runWorker(['--skip-results' => true]);

    expect($prediction->refresh()->state)->toBe(Prediction::STATE_RUNNING)
        ->and($prediction->remote_calculation_id)->toBe('calc-1');
    Queue::assertPushed(CheckPredictionStatus::class, fn (CheckPredictionStatus $job): bool => $job->predictionId === $prediction->id);
});

test('a prepared prediction with an invalid structure is rejected without being submitted', function () {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED]);
    fakeRemotePredictionService(validSmiles: false);

    runWorker(['--skip-results' => true]);

    expect($prediction->refresh()->state)->toBe(Prediction::STATE_ERROR)
        ->and($prediction->remote_error_message)->toContain('Molecule has too many atoms.');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://predictor.test/jobs');
});

test('an RDKit outage skips submissions but still downloads finished results', function () {
    ['prediction' => $prepared] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED]);
    ['prediction' => $completed] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-9', 'remote_status' => 'completed']);
    fakeRemotePredictionService(validationStatus: 503);

    runWorker();

    expect($prepared->refresh()->state)->toBe(Prediction::STATE_PREPARED)
        ->and($prepared->remote_calculation_id)->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://predictor.test/jobs/status');
});

test('the owner is notified once when the last prediction of a dataset is downloaded', function () {
    $filesystem = Filesystem::query()->where('type', Filesystem::TYPE_PREDICTIONS_STORAGE)->firstOrFail();
    config()->set("filesystems.disks.{$filesystem->systemName}", ['driver' => 'local']);
    Storage::fake($filesystem->systemName);

    $owner = User::factory()->create();
    ['prediction' => $prediction, 'dataset' => $dataset] = createPredictionDataset($owner, ['remote_calculation_id' => 'calc-1', 'remote_status' => 'completed']);
    $cosmoXml = '<micoutput><joblist><job><jobnumber>1</jobnumber><symmetry>planar</symmetry><nrlayer>1</nrlayer><layerposition>0.0</layerposition><temperature>298.15</temperature><solutelist><solute><meanposition>1.5</meanposition><logP_micelle_water>2.75</logP_micelle_water><logPerm_membrane_cm_s>-4.5</logPerm_membrane_cm_s><distribution>0.5</distribution></solute></solutelist></job></joblist></micoutput>';

    fakeRemotePredictionService([
        'https://predictor.test/jobs/status' => Http::response([
            'id' => 'mol-1',
            'status' => 'completed',
            'calculations' => [[
                'id' => 'calc-1',
                'membrane_key' => Str::slug($prediction->predictionMembrane->abbreviation),
                'temperature_c' => 25.0,
                'status' => 'completed',
            ]],
        ]),
        'https://predictor.test/calculations/calc-1/download/*' => Http::response(['detail' => 'No COSMO files.'], 404),
        'https://predictor.test/calculations/calc-1/download' => Http::response($cosmoXml, 200, ['Content-Type' => 'application/xml']),
    ]);

    runWorker(['--skip-submit' => true]);
    runWorker(['--skip-submit' => true]);

    // "Job finished" is a batched notification type: it is queued for the
    // owner and sent by FlushQueuedNotifications.
    $queued = QueuedNotification::query()
        ->where('group_key', NotificationBatcher::groupKey(NotificationType::PREDICTION_JOB_FINISHED))
        ->where('notifiable_id', $owner->id)
        ->count();

    expect($prediction->refresh()->state)->toBe(Prediction::STATE_FINISHED)
        ->and($dataset->refresh()->finished_notification_sent_at)->not->toBeNull()
        ->and($queued)->toBe(1);
});

test('an authentication failure alerts prediction managers at most every thirty minutes', function () {
    Config::set(Config::KEY_REMOTE_PREDICTION_TOKEN, null);
    $manager = createPredictionManager();
    Http::fake(['https://predictor.test/auth/tokens' => Http::response(['detail' => 'Invalid manager secret.'], 401)]);

    expect(runWorker())->toBe(1);
    expect(runWorker())->toBe(1);

    Notification::assertSentToTimes($manager, TemplatedNotification::class, 1);
});

test('a structure the validation service fails on does not block other submissions', function () {
    ['prediction' => $failing] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED, 'priority' => Prediction::PRIORITY_HIGH]);
    ['prediction' => $valid] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED]);
    $failingSmiles = $failing->predictionStructure->canonical_smiles;

    Http::fake(function (Request $request) use ($failingSmiles) {
        if (str_starts_with($request->url(), 'https://rdkit.test/structure/predictions/validate')) {
            return ($request->data()['smi'] ?? null) === $failingSmiles
                ? Http::response(['detail' => 'RDKit crashed on this molecule.'], 500)
                : Http::response(['data' => ['valid' => true, 'canonical_smiles' => 'CCO', 'errors' => []]]);
        }

        return Http::response(['molecule_id' => 'mol-2', 'calculation_id' => 'calc-2', 'calculation_status' => 'queued']);
    });

    runWorker(['--skip-results' => true]);

    expect($failing->refresh()->state)->toBe(Prediction::STATE_PREPARED)
        ->and($failing->remote_calculation_id)->toBeNull()
        ->and($valid->refresh()->remote_calculation_id)->toBe('calc-2');
});

test('paused predictions are resumed while structure validation is down', function () {
    ['prediction' => $prepared] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED, 'priority' => Prediction::PRIORITY_HIGH]);
    ['prediction' => $paused] = createPredictionDataset(User::factory()->create(), [
        'remote_calculation_id' => 'calc-1',
        'remote_status' => 'queued',
        'remote_paused_at' => now()->subHour(),
    ]);
    fakeRemotePredictionService(validationStatus: 503);

    runWorker(['--skip-results' => true]);

    expect($prepared->refresh()->remote_calculation_id)->toBeNull()
        ->and($paused->refresh()->remote_paused_at)->toBeNull();
});

/**
 * The remote service refuses every job with the given answer.
 */
function fakeRefusingRemotePredictionService(int $status, string $detail): void
{
    // A fake registered later does not replace an earlier one, so the answer
    // is kept aside and may be changed during a test.
    $registered = isset(test()->remoteAnswer);
    test()->remoteAnswer = [$status, $detail];

    if ($registered) {
        return;
    }

    Http::fake(fn (Request $request) => str_starts_with($request->url(), 'https://rdkit.test/')
        ? Http::response(['data' => ['valid' => true, 'canonical_smiles' => 'CCO', 'errors' => []]])
        : Http::response(['detail' => test()->remoteAnswer[1]], test()->remoteAnswer[0]));
}

test('a prediction the remote service refuses is marked as failed after repeated attempts', function (int $status, string $detail) {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED]);
    fakeRefusingRemotePredictionService($status, $detail);

    foreach (range(1, RunPredictionsWorker::MAX_REJECTED_SUBMISSION_ATTEMPTS - 1) as $attempt) {
        runWorker(['--skip-results' => true]);

        // Not yet: one refusal may be a slip of the service.
        expect($prediction->refresh()->state)->toBe(Prediction::STATE_PREPARED)
            ->and($prediction->remote_error_message)->toBe($detail)
            ->and(collect($prediction->logs)->where('type', 'REMOTE SUBMIT')->count())->toBe($attempt);
    }

    runWorker(['--skip-results' => true]);

    expect($prediction->refresh()->state)->toBe(Prediction::STATE_ERROR)
        ->and($prediction->remote_error_message)->toBe($detail)
        ->and($prediction->remote_calculation_id)->toBeNull()
        ->and(collect($prediction->logs)->last()['message'])->toContain($detail)->toContain('marked as failed');
})->with([
    'a structure the service cannot read' => [400, 'Invalid SMILES according to Bingo: dearomatization: non-unique dearomatization'],
    'a request that does not validate' => [422, 'Temperature out of range.'],
    'a membrane the service does not know' => [404, 'Unknown membrane key: dppcdppgchol'],
    'a structure refused under another status' => [409, 'Invalid SMILES according to Bingo: element: cannot calculate implicit hydrogens on aromatic S'],
]);

test('a prediction is not given up while the remote service is failing or busy', function (int $status) {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED]);
    fakeRefusingRemotePredictionService($status, 'Not now.');

    foreach (range(1, RunPredictionsWorker::MAX_REJECTED_SUBMISSION_ATTEMPTS + 1) as $attempt) {
        runWorker(['--skip-results' => true]);
    }

    expect($prediction->refresh()->state)->toBe(Prediction::STATE_PREPARED)
        ->and(collect($prediction->logs)->where('type', 'REMOTE SUBMIT'))->toBeEmpty();
})->with([
    'server error' => [500],
    'unavailable' => [503],
    'too many requests' => [429],
    'not found' => [404],
    'forbidden' => [403],
    'unauthorized' => [401],
]);

test('a failed prediction sent back by an administrator starts counting anew', function () {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED]);

    $prediction->forceFill(['logs' => [
        ...array_fill(0, RunPredictionsWorker::MAX_REJECTED_SUBMISSION_ATTEMPTS, [
            'type' => 'REMOTE SUBMIT', 'context' => 'error', 'message' => 'Refused.', 'payload' => [], 'timestamp' => now()->toIso8601String(),
        ]),
    ], 'state' => Prediction::STATE_ERROR])->save();

    // An administrator sends the failed prediction back; it has no remote
    // job, so nothing is asked of the remote service.
    fakeRefusingRemotePredictionService(400, 'Invalid SMILES.');
    expect($prediction->requeueAndStoreRemotePrediction())->toBeNull();
    Http::assertNothingSent();

    expect($prediction->refresh()->state)->toBe(Prediction::STATE_PREPARED)
        ->and($prediction->remote_error_message)->toBeNull();

    runWorker(['--skip-results' => true]);

    // Counted from one again.
    expect($prediction->refresh()->state)->toBe(Prediction::STATE_PREPARED)
        ->and(collect($prediction->logs)->last()['payload']['attempt'])->toBe(1);
});

test('only a failed prediction without a remote job and a result is prepared again', function (array $attributes) {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => null, ...$attributes]);
    Http::fake(['*' => Http::response(['detail' => 'No such job.'], 404)]);

    expect($prediction->canBePreparedForSubmissionAgain())->toBeFalse();

    // It goes the way of a remote requeue, as before.
    expect(fn () => $prediction->requeueAndStoreRemotePrediction())->toThrow(RemotePredictionException::class);
    expect($prediction->refresh()->state)->toBe($attributes['state']);
})->with([
    'stopped' => [['state' => Prediction::STATE_STOPPED]],
    'removed' => [['state' => Prediction::STATE_REMOVE]],
    'with a remote job' => [['state' => Prediction::STATE_ERROR, 'remote_calculation_id' => 'calc-7']],
]);

test('refusals with an outage between them still add up', function () {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED]);

    foreach (range(1, RunPredictionsWorker::MAX_REJECTED_SUBMISSION_ATTEMPTS - 1) as $attempt) {
        fakeRefusingRemotePredictionService(400, 'Invalid SMILES.');
        runWorker(['--skip-results' => true]);
        fakeRefusingRemotePredictionService(503, 'Down.');
        runWorker(['--skip-results' => true]);
    }

    expect($prediction->refresh()->state)->toBe(Prediction::STATE_PREPARED);

    fakeRefusingRemotePredictionService(400, 'Invalid SMILES.');
    runWorker(['--skip-results' => true]);

    expect($prediction->refresh()->state)->toBe(Prediction::STATE_ERROR);
});

test('no more than the daily limit of refused predictions is marked as failed', function () {
    Cache::put('prediction-worker:given-up-submissions:'.now()->toDateString(), RunPredictionsWorker::MAX_GIVEN_UP_SUBMISSIONS_PER_DAY - 1, now()->addDay());

    $refusals = array_fill(0, RunPredictionsWorker::MAX_REJECTED_SUBMISSION_ATTEMPTS - 1, [
        'type' => 'REMOTE SUBMIT', 'context' => 'error', 'message' => 'Refused.', 'payload' => [], 'timestamp' => now()->toIso8601String(),
    ]);
    ['prediction' => $first] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED, 'priority' => Prediction::PRIORITY_HIGH, 'logs' => $refusals]);
    ['prediction' => $second] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED, 'logs' => $refusals]);
    fakeRefusingRemotePredictionService(422, 'The request does not validate.');

    runWorker(['--skip-results' => true]);

    // The last one of the day is given up, the next one waits untouched.
    expect($first->refresh()->state)->toBe(Prediction::STATE_ERROR)
        ->and($second->refresh()->state)->toBe(Prediction::STATE_PREPARED)
        ->and($second->logs)->toHaveCount(count($refusals))
        ->and(Activity::query()->where('event', 'prediction_worker_give_up_limit_reached')->count())->toBe(1);

    // On the next day there is room again.
    $this->travel(1)->days();
    runWorker(['--skip-results' => true]);

    expect($second->refresh()->state)->toBe(Prediction::STATE_ERROR);
});

test('predictions the remote service refuses stop holding the slots of the others', function () {
    ['prediction' => $refused] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED, 'priority' => Prediction::PRIORITY_HIGH]);
    ['prediction' => $waiting] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED]);
    $refusedSmiles = $refused->predictionStructure->canonical_smiles;

    Http::fake(function (Request $request) use ($refusedSmiles) {
        if (str_starts_with($request->url(), 'https://rdkit.test/structure/predictions/validate')) {
            return Http::response(['data' => ['valid' => true, 'canonical_smiles' => $request->data()['smi'], 'errors' => []]]);
        }

        return str_contains(json_encode($request->data()), $refusedSmiles)
            ? Http::response(['detail' => 'Invalid SMILES according to Bingo.'], 400)
            : Http::response(['molecule_id' => 'mol-2', 'calculation_id' => 'calc-2', 'calculation_status' => 'queued']);
    });

    // One slot only: the refused prediction comes first on every run.
    foreach (range(1, RunPredictionsWorker::MAX_REJECTED_SUBMISSION_ATTEMPTS) as $attempt) {
        expect($waiting->refresh()->remote_calculation_id)->toBeNull();
        runWorker(['--skip-results' => true, '--max-submit' => 1]);
    }

    expect($refused->refresh()->state)->toBe(Prediction::STATE_ERROR);

    runWorker(['--skip-results' => true, '--max-submit' => 1]);

    expect($waiting->refresh()->remote_calculation_id)->toBe('calc-2');
});
