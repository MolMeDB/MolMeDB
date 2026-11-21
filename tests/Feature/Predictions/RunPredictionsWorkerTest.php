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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\PredictionWorkers\Models\Prediction;

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
