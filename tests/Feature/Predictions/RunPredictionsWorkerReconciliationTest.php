<?php

require_once __DIR__.'/remote_prediction_test_helpers.php';

use App\Console\Commands\Cron\RunPredictionsWorker;
use App\Enums\NotificationType;
use App\Jobs\CheckPredictionStatus;
use App\Models\QueuedNotification;
use App\Models\User;
use App\Services\NotificationBatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionStat;
use Modules\PredictionWorkers\Models\PredictionStructure;
use Spatie\Activitylog\Models\Activity;

/*
 * The worker's priority window: reconciliation of submitted predictions
 * with the remote queue, pausing and resuming, activation limits, status
 * check dispatching, statistics and result download failures.
 */

beforeEach(function () {
    enableRemotePredictionService();
    config()->set('services.rdkit.url', 'https://rdkit.test');

    Queue::fake();
    Notification::fake();
});

/**
 * Fakes the remote service and RDKit with one router: the given URL
 * prefixes win over the defaults (valid structures, accepted submissions
 * numbered calc-new, calc-new-2, ...). Prefixes are matched in order.
 *
 * @param  array<string, mixed>  $routes  URL prefix => response or closure(Request) returning one.
 */
function fakeReconciliationRemote(array $routes = []): void
{
    $submissions = 0;
    $routes += [
        'https://rdkit.test/structure/predictions/validate' => Http::response(['data' => ['valid' => true, 'canonical_smiles' => 'CCO', 'errors' => []]]),
        'https://predictor.test/jobs/pause' => Http::response(['results' => []]),
        'https://predictor.test/calculations/status' => Http::response(['calculations' => []]),
        'https://predictor.test/jobs' => function () use (&$submissions) {
            $submissions++;

            return Http::response([
                'molecule_id' => 'mol-new',
                'calculation_id' => $submissions === 1 ? 'calc-new' : "calc-new-{$submissions}",
                'molecule_status' => 'queued',
                'calculation_status' => 'queued',
            ]);
        },
    ];

    fakePredictionHttpRoutes($routes);
}

/**
 * @param  array<string, mixed>  $options
 */
function runReconcilingWorker(array $options = []): int
{
    return test()->artisan(RunPredictionsWorker::class, [
        '--skip-stats' => true,
        '--skip-dispatch' => true,
        '--skip-results' => true,
        ...$options,
    ])->run();
}

function remoteRequestSent(string $url): bool
{
    return Http::recorded(fn (Request $request): bool => $request->url() === $url)->isNotEmpty();
}

test('a running prediction pushed out of the priority window is paused remotely', function () {
    config()->set('prediction-workers.remote.worker.max_active', 1);
    ['prediction' => $running] = createPredictionDataset(User::factory()->create(), [
        'remote_calculation_id' => 'calc-low',
        'remote_status' => 'running',
        'priority' => Prediction::PRIORITY_LOW,
    ]);
    ['prediction' => $urgent] = createPredictionDataset(User::factory()->create(), [
        'state' => Prediction::STATE_PREPARED,
        'priority' => Prediction::PRIORITY_HIGH,
    ]);
    $runningSmiles = $running->predictionStructure->canonical_smiles;
    fakeReconciliationRemote([
        'https://predictor.test/calculations/status' => Http::response(['calculations' => [['id' => 'calc-low', 'status' => 'running']]]),
        'https://predictor.test/jobs/pause' => Http::response(['results' => [['smiles' => $runningSmiles, 'status' => 'paused']]]),
    ]);

    expect(runReconcilingWorker())->toBe(0);

    $running->refresh();
    expect($running->remote_paused_at)->not->toBeNull()
        ->and($running->remote_pause_reason)->toContain('outside the active priority window')
        ->and($running->effectiveState())->toBe('paused')
        ->and(collect($running->logs)->last()['type'])->toBe('REMOTE PAUSE')
        ->and($urgent->refresh()->remote_calculation_id)->toBe('calc-new');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://predictor.test/jobs/pause'
        && $request['smiles'] === [$runningSmiles]
        && $request['cancel_running'] === true);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://predictor.test/calculations/status'
        && $request['calculation_ids'] === ['calc-low']);

    $activity = Activity::query()->where('event', 'prediction_remote_working_set_reconciled')->sole();
    expect($activity->properties['paused'])->toBe(1);
});

test('a prediction sharing its molecule with the priority window is not paused', function () {
    config()->set('prediction-workers.remote.worker.max_active', 1);
    $structure = PredictionStructure::query()->create(['canonical_smiles' => 'c1ccccc1O']);
    createPredictionDataset(User::factory()->create(), [
        'state' => Prediction::STATE_PREPARED,
        'priority' => Prediction::PRIORITY_HIGH,
    ], $structure);
    ['prediction' => $running] = createPredictionDataset(User::factory()->create(), [
        'remote_calculation_id' => 'calc-low',
        'remote_status' => 'running',
        'priority' => Prediction::PRIORITY_LOW,
    ], $structure);
    fakeReconciliationRemote();

    runReconcilingWorker();

    expect($running->refresh()->remote_paused_at)->toBeNull()
        ->and(remoteRequestSent('https://predictor.test/jobs/pause'))->toBeFalse()
        ->and(Activity::query()->where('event', 'prediction_remote_working_set_reconciled')->sole()->properties['shared_molecule_skips'])->toBe(1);
});

test('a pause the service does not confirm is rolled back and keeps the slot taken', function (mixed $pauseResponse) {
    config()->set('prediction-workers.remote.worker.max_active', 1);
    ['prediction' => $running] = createPredictionDataset(User::factory()->create(), [
        'remote_calculation_id' => 'calc-low',
        'remote_status' => 'running',
        'priority' => Prediction::PRIORITY_LOW,
    ]);
    ['prediction' => $urgent] = createPredictionDataset(User::factory()->create(), [
        'state' => Prediction::STATE_PREPARED,
        'priority' => Prediction::PRIORITY_HIGH,
    ]);
    $runningSmiles = $running->predictionStructure->canonical_smiles;
    fakeReconciliationRemote([
        'https://predictor.test/jobs/pause' => is_callable($pauseResponse) ? $pauseResponse($runningSmiles) : $pauseResponse,
    ]);

    runReconcilingWorker();

    expect($running->refresh()->remote_paused_at)->toBeNull()
        ->and($running->remote_pause_reason)->toBeNull()
        ->and($urgent->refresh()->remote_calculation_id)->toBeNull()
        ->and(Activity::query()->where('event', 'prediction_worker_completed_with_errors')->exists())->toBeTrue();
})->with([
    'molecule unknown to the service' => fn () => fn (string $smiles) => Http::response(['results' => [['smiles' => $smiles, 'status' => 'not_found']]]),
    'pause request fails' => fn () => Http::response(['detail' => 'Queue is locked.'], 500),
]);

test('calculations finished remotely leave the window and free their slot', function (string $status, int $expectedState) {
    config()->set('prediction-workers.remote.worker.max_active', 1);
    ['prediction' => $finished] = createPredictionDataset(User::factory()->create(), [
        'remote_calculation_id' => 'calc-1',
        'remote_status' => 'running',
        'priority' => Prediction::PRIORITY_HIGH,
    ]);
    ['prediction' => $prepared] = createPredictionDataset(User::factory()->create(), [
        'state' => Prediction::STATE_PREPARED,
        'priority' => Prediction::PRIORITY_LOW,
    ]);
    fakeReconciliationRemote([
        'https://predictor.test/calculations/status' => Http::response(['calculations' => [[
            'id' => 'calc-1',
            'status' => $status,
            'message' => 'Turbomole crashed.',
            'finished_at' => '2026-11-20T10:00:00+00:00',
        ]]]),
    ]);

    runReconcilingWorker();
    $finished->refresh();

    expect($finished->remote_status)->toBe($status)
        ->and($finished->state)->toBe($expectedState)
        ->and($finished->remote_finished_at->toIso8601String())->toBe('2026-11-20T10:00:00+00:00')
        ->and($finished->remote_error_message)->toBe($status === 'failed' ? 'Turbomole crashed.' : null)
        ->and($finished->step)->toBe($status === 'completed' ? Prediction::STEP_RESULT_DOWNLOAD : Prediction::STEP_COSMO)
        ->and($prepared->refresh()->remote_calculation_id)->toBe('calc-new');
})->with([
    'completed' => ['completed', Prediction::STATE_RUNNING],
    'failed' => ['failed', Prediction::STATE_ERROR],
]);

test('a failing status reconciliation pauses nothing and submits only into free slots', function () {
    config()->set('prediction-workers.remote.worker.max_active', 1);
    ['prediction' => $running] = createPredictionDataset(User::factory()->create(), [
        'remote_calculation_id' => 'calc-low',
        'remote_status' => 'running',
        'priority' => Prediction::PRIORITY_LOW,
    ]);
    ['prediction' => $urgent] = createPredictionDataset(User::factory()->create(), [
        'state' => Prediction::STATE_PREPARED,
        'priority' => Prediction::PRIORITY_HIGH,
    ]);
    fakeReconciliationRemote([
        'https://predictor.test/calculations/status' => Http::response(['detail' => 'Database is locked.'], 500),
    ]);

    expect(runReconcilingWorker())->toBe(0);

    expect($running->refresh()->remote_paused_at)->toBeNull()
        ->and($urgent->refresh()->remote_calculation_id)->toBeNull()
        ->and(remoteRequestSent('https://predictor.test/jobs/pause'))->toBeFalse()
        ->and(Activity::query()->where('event', 'prediction_worker_completed_with_errors')->sole()->properties['reconciliation_errors'])->toBe(1);
});

test('a prediction that fails to resume stays paused with the error', function () {
    ['prediction' => $paused] = createPredictionDataset(User::factory()->create(), [
        'remote_calculation_id' => 'calc-1',
        'remote_status' => 'queued',
        'remote_paused_at' => now()->subHour(),
        'remote_pause_reason' => 'Deferred.',
    ]);
    fakeReconciliationRemote([
        'https://predictor.test/jobs' => Http::response(['detail' => 'Membrane is being rebuilt.'], 409),
    ]);

    runReconcilingWorker(['--skip-reconcile' => true]);
    $paused->refresh();

    expect($paused->remote_paused_at)->not->toBeNull()
        ->and($paused->remote_error_message)->toBe('Membrane is being rebuilt.');
    Queue::assertNotPushed(CheckPredictionStatus::class);
});

test('a resumed prediction is logged and checked', function () {
    ['prediction' => $paused] = createPredictionDataset(User::factory()->create(), [
        'remote_calculation_id' => 'calc-1',
        'remote_status' => 'queued',
        'remote_paused_at' => now()->subHour(),
        'remote_pause_reason' => 'Deferred.',
    ]);
    fakeReconciliationRemote();

    runReconcilingWorker(['--skip-reconcile' => true]);
    $paused->refresh();

    expect($paused->remote_paused_at)->toBeNull()
        ->and($paused->remote_pause_reason)->toBeNull()
        ->and($paused->remote_calculation_id)->toBe('calc-new')
        ->and(collect($paused->logs)->last()['type'])->toBe('REMOTE RESUME');
    Queue::assertPushed(CheckPredictionStatus::class, fn (CheckPredictionStatus $job): bool => $job->predictionId === $paused->id);
    expect(Activity::query()->where('event', 'prediction_worker_completed')->sole()->properties['resumed'])->toBe(1);
});

test('a rejected submission leaves the prediction prepared with the service error', function () {
    ['prediction' => $prepared] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED]);
    fakeReconciliationRemote([
        'https://predictor.test/jobs' => Http::response(['detail' => ['code' => 'quota_exceeded', 'message' => 'Daily quota exceeded.']], 429),
    ]);

    runReconcilingWorker(['--skip-reconcile' => true]);
    $prepared->refresh();

    expect($prepared->state)->toBe(Prediction::STATE_PREPARED)
        ->and($prepared->remote_calculation_id)->toBeNull()
        ->and($prepared->remote_error_message)->toBe('Daily quota exceeded.')
        ->and($prepared->remote_method)->toBe($prepared->remotePredictionMethod())
        ->and($prepared->remote_last_status_at)->not->toBeNull()
        ->and(Activity::query()->where('event', 'prediction_worker_activation_stalled')->exists())->toBeTrue();
});

test('submissions follow priority and stop at the submission limit', function () {
    ['prediction' => $low] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED, 'priority' => Prediction::PRIORITY_LOW]);
    ['prediction' => $high] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED, 'priority' => Prediction::PRIORITY_HIGH]);
    ['prediction' => $medium] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED, 'priority' => Prediction::PRIORITY_MEDIUM]);
    fakeReconciliationRemote();

    runReconcilingWorker(['--skip-reconcile' => true, '--max-submit' => 2]);

    expect($high->refresh()->state)->toBe(Prediction::STATE_RUNNING)
        ->and($medium->refresh()->state)->toBe(Prediction::STATE_RUNNING)
        ->and($low->refresh()->state)->toBe(Prediction::STATE_PREPARED);
});

test('nothing is submitted while the remote window is full', function () {
    config()->set('prediction-workers.remote.worker.max_active', 1);
    createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-1', 'remote_status' => 'running']);
    ['prediction' => $prepared] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED, 'priority' => Prediction::PRIORITY_HIGH]);
    fakeReconciliationRemote();

    runReconcilingWorker(['--skip-reconcile' => true]);

    expect($prepared->refresh()->remote_calculation_id)->toBeNull()
        ->and(remoteRequestSent('https://predictor.test/jobs'))->toBeFalse();
});

test('status checks are dispatched for due, unpaused, active predictions, oldest check first', function () {
    ['prediction' => $neverChecked] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-1', 'remote_status' => 'running']);
    ['prediction' => $stale] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-2', 'remote_status' => 'queued', 'remote_last_status_at' => now()->subMinutes(10)]);
    ['prediction' => $staler] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-7', 'remote_status' => 'queued', 'remote_last_status_at' => now()->subMinutes(20), 'priority' => Prediction::PRIORITY_LOW]);
    ['prediction' => $fresh] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-3', 'remote_status' => 'running', 'remote_last_status_at' => now()->subMinute()]);
    ['prediction' => $paused] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-4', 'remote_status' => 'running', 'remote_paused_at' => now()]);
    ['prediction' => $completed] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-5', 'remote_status' => 'completed']);
    ['prediction' => $failed] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-6', 'state' => Prediction::STATE_ERROR]);
    Http::fake();

    runReconcilingWorker(['--skip-reconcile' => true, '--skip-submit' => true, '--skip-dispatch' => false]);

    $dispatched = Queue::pushed(CheckPredictionStatus::class)->map(fn (CheckPredictionStatus $job): int => $job->predictionId)->all();
    expect($dispatched)->toBe([$neverChecked->id, $staler->id, $stale->id]);

    // With room for one check only, the never checked prediction goes first.
    config()->set('prediction-workers.remote.worker.max_active', 1);
    Queue::fake();
    runReconcilingWorker(['--skip-reconcile' => true, '--skip-submit' => true, '--skip-dispatch' => false]);

    expect(Queue::pushed(CheckPredictionStatus::class)->map(fn (CheckPredictionStatus $job): int => $job->predictionId)->all())->toBe([$neverChecked->id]);
});

test('remote statistics are stored as a daily snapshot and their failure does not stop the worker', function () {
    $responses = [
        Http::response(['queue' => ['queued' => 3, 'running' => 1], 'generated_at' => '2026-11-21T08:00:00+00:00']),
        Http::response(['queue' => ['queued' => 5, 'running' => 2]]),
        Http::response(['detail' => 'Stats are being rebuilt.'], 500),
        Http::response(['detail' => 'Stats are being rebuilt.'], 500),
    ];
    fakeReconciliationRemote([
        'https://predictor.test/stats' => function () use (&$responses) {
            return array_shift($responses);
        },
    ]);
    $options = ['--skip-reconcile' => true, '--skip-submit' => true, '--skip-stats' => false];

    runReconcilingWorker($options);
    $this->travel(1)->day();
    runReconcilingWorker($options);
    expect(runReconcilingWorker($options))->toBe(0);

    $stats = PredictionStat::query()->newest()->get();
    expect($stats)->toHaveCount(2)
        ->and($stats->pluck('server_url')->unique()->all())->toBe(['https://predictor.test'])
        ->and($stats->first()->stats_date->toDateString())->toBe(now()->toDateString())
        ->and($stats->first()->payload['queue'])->toBe(['queued' => 5, 'running' => 2])
        ->and($stats->last()->payload['generated_at'])->toBe('2026-11-21T08:00:00.000000Z')
        ->and(Activity::query()->where('event', 'prediction_worker_completed_with_errors')->sole()->properties['statistics_errors'])->toBe(1);
});

test('a failed result download is recorded on the prediction', function () {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-1', 'remote_status' => 'completed']);
    fakeReconciliationRemote([
        'https://predictor.test/jobs/status' => Http::response([
            'id' => 'mol-1',
            'status' => 'completed',
            'calculations' => [['id' => 'calc-1', 'status' => 'completed']],
        ]),
        'https://predictor.test/calculations/calc-1/download' => Http::response(['detail' => 'Result archive is missing.'], 410),
    ]);

    runReconcilingWorker(['--skip-reconcile' => true, '--skip-submit' => true, '--skip-results' => false]);
    $prediction->refresh();

    expect($prediction->result_id)->toBeNull()
        ->and($prediction->state)->toBe(Prediction::STATE_RUNNING)
        ->and($prediction->remote_error_message)->toBe('Result archive is missing.')
        ->and($prediction->remote_last_status_at)->not->toBeNull();
});

test('a dataset with a failed prediction gets the finished-with-errors notification', function () {
    fakePredictionStorageDisk();

    $owner = User::factory()->create();
    ['prediction' => $prediction, 'dataset' => $dataset] = createPredictionDataset($owner, ['remote_calculation_id' => 'calc-1', 'remote_status' => 'completed']);
    $failed = Prediction::query()->create([
        'structure_id' => PredictionStructure::query()->create(['canonical_smiles' => 'CCN'])->id,
        'state' => Prediction::STATE_ERROR,
        'step' => Prediction::STEP_COSMO,
        'temperature' => 25.0,
        'membrane_id' => $prediction->membrane_id,
        'method_type' => 'cosmoperm',
    ]);
    $dataset->predictions()->attach($failed->id);

    fakeReconciliationRemote([
        'https://predictor.test/jobs/status' => Http::response([
            'id' => 'mol-1',
            'status' => 'completed',
            'calculations' => [['id' => 'calc-1', 'membrane_key' => Str::slug($prediction->predictionMembrane->abbreviation), 'temperature_c' => 25.0, 'status' => 'completed']],
        ]),
        'https://predictor.test/calculations/calc-1/download/' => Http::response(['detail' => 'No COSMO files.'], 404),
        'https://predictor.test/calculations/calc-1/download' => Http::response(cosmoResultXml(), 200, ['Content-Type' => 'application/xml']),
    ]);

    runReconcilingWorker(['--skip-reconcile' => true, '--skip-submit' => true, '--skip-results' => false]);

    $groupCount = fn (NotificationType $type): int => QueuedNotification::query()
        ->where('group_key', NotificationBatcher::groupKey($type))
        ->where('notifiable_id', $owner->id)
        ->count();

    expect($prediction->refresh()->state)->toBe(Prediction::STATE_FINISHED)
        ->and($dataset->refresh()->finished_notification_sent_at)->not->toBeNull()
        ->and($groupCount(NotificationType::PREDICTION_JOB_FINISHED_WITH_ERRORS))->toBe(1)
        ->and($groupCount(NotificationType::PREDICTION_JOB_FINISHED))->toBe(0);
});

test('a dataset with predictions still running is not announced as finished', function () {
    fakePredictionStorageDisk();

    $owner = User::factory()->create();
    ['prediction' => $prediction, 'dataset' => $dataset] = createPredictionDataset($owner, ['remote_calculation_id' => 'calc-1', 'remote_status' => 'completed']);
    $stillRunning = Prediction::query()->create([
        'structure_id' => PredictionStructure::query()->create(['canonical_smiles' => 'CCN'])->id,
        'state' => Prediction::STATE_RUNNING,
        'step' => Prediction::STEP_OPTIMIZATION,
        'temperature' => 25.0,
        'membrane_id' => $prediction->membrane_id,
        'method_type' => 'cosmoperm',
    ]);
    $dataset->predictions()->attach($stillRunning->id);

    fakeReconciliationRemote([
        'https://predictor.test/jobs/status' => Http::response([
            'id' => 'mol-1',
            'status' => 'completed',
            'calculations' => [['id' => 'calc-1', 'status' => 'completed']],
        ]),
        'https://predictor.test/calculations/calc-1/download/' => Http::response(['detail' => 'No COSMO files.'], 404),
        'https://predictor.test/calculations/calc-1/download' => Http::response(cosmoResultXml(), 200, ['Content-Type' => 'application/xml']),
    ]);

    runReconcilingWorker(['--skip-reconcile' => true, '--skip-submit' => true, '--skip-results' => false]);

    expect($prediction->refresh()->state)->toBe(Prediction::STATE_FINISHED)
        ->and($dataset->refresh()->finished_notification_sent_at)->toBeNull()
        ->and(QueuedNotification::query()->where('notifiable_id', $owner->id)->count())->toBe(0);
});

test('a second worker does nothing while another one holds the lock', function () {
    ['prediction' => $prepared] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED]);
    fakeReconciliationRemote();
    $lock = Cache::lock('remote-prediction:worker', 600);
    $lock->get();

    try {
        expect(runReconcilingWorker())->toBe(0);
    } finally {
        $lock->release();
    }

    expect($prepared->refresh()->state)->toBe(Prediction::STATE_PREPARED);
    Http::assertNothingSent();
});
