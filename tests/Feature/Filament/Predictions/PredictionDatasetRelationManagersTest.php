<?php

use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\NotificationMailbox;

require_once __DIR__.'/filament_prediction_helpers.php';

use App\Enums\PermissionEnums;
use App\Filament\Resources\PredictionDatasets\Pages\EditPredictionDataset;
use App\Filament\Resources\PredictionDatasets\RelationManagers\PredictionsRelationManager;
use App\Filament\Resources\PredictionDatasets\RelationManagers\StructuresRelationManager;
use App\Models\Config;
use App\Models\Filesystem;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File as FileFacade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionStructure;

/*
 * The predictions and structures tabs of a prediction dataset, with the
 * bulk requeue and result download of the dataset's predictions.
 */

beforeEach(function () {
    seedFilamentPredictionPermissions();
    enableRemotePredictionService();

    $this->actingAs(createFilamentPredictionUser([
        PermissionEnums::PREDICTION_DATASET_MANAGE_ALL,
        PermissionEnums::PREDICTIONS_MANAGE,
    ]));
});

afterEach(function () {
    if (isset($this->predictionResultsRoot)) {
        FileFacade::deleteDirectory($this->predictionResultsRoot);
    }
});

/**
 * Adds another prediction of a new structure to the dataset.
 *
 * @param  array<string, mixed>  $attributes
 */
function addPredictionToFilamentDataset(array $dataset, array $attributes = []): Prediction
{
    $structure = PredictionStructure::query()->create(['canonical_smiles' => 'CCN'.Str::random(4)]);

    $prediction = Prediction::query()->create([
        'structure_id' => $structure->id,
        'state' => Prediction::STATE_RUNNING,
        'step' => Prediction::STEP_COSMO,
        'temperature' => 25.0,
        'membrane_id' => $dataset['membrane']->id,
        'method_type' => 'cosmoperm',
        'priority' => Prediction::PRIORITY_MEDIUM,
        ...$attributes,
    ]);

    $dataset['dataset']->predictions()->attach($prediction->id);

    return $prediction;
}

/**
 * The title of the last notification sent to the browser.
 */
function lastFilamentNotificationTitle(): ?string
{
    app(NotificationMailbox::class)->flush();

    $notifications = new Notifications;
    $notifications->mount();
    $notifications->pullNotifications();

    return $notifications->notifications->last()?->getTitle();
}

/**
 * Points the predictions storage filesystem to a temporary directory of
 * this test and returns the disk name.
 */
function usePredictionResultsDirectory(object $test): string
{
    $test->predictionResultsRoot = sys_get_temp_dir().'/filament-prediction-results-'.Str::random(12);
    $filesystem = Filesystem::query()->where('type', Filesystem::TYPE_PREDICTIONS_STORAGE)->firstOrFail();
    config()->set("filesystems.disks.{$filesystem->systemName}", ['driver' => 'local', 'root' => $test->predictionResultsRoot]);
    Storage::forgetDisk($filesystem->systemName);

    return $filesystem->systemName;
}

test('requeueing failed predictions is offered only when the dataset has some', function () {
    $dataset = createPredictionDataset(User::factory()->create());

    Livewire::test(PredictionsRelationManager::class, ['ownerRecord' => $dataset['dataset'], 'pageClass' => EditPredictionDataset::class])
        ->assertTableActionHidden('requeue_failed')
        ->assertTableActionVisible('download_results');

    addPredictionToFilamentDataset($dataset, ['state' => Prediction::STATE_ERROR, 'remote_calculation_id' => 'calc-failed']);

    Livewire::test(PredictionsRelationManager::class, ['ownerRecord' => $dataset['dataset'], 'pageClass' => EditPredictionDataset::class])
        ->assertTableActionVisible('requeue_failed');
});

test('requeueing failed predictions requeues each failed one and counts the failures', function () {
    $dataset = createPredictionDataset(User::factory()->create());
    $requeued = addPredictionToFilamentDataset($dataset, ['state' => Prediction::STATE_ERROR, 'remote_calculation_id' => 'calc-ok']);
    $refused = addPredictionToFilamentDataset($dataset, ['state' => Prediction::STATE_STOPPED, 'remote_calculation_id' => 'calc-refused']);
    $running = $dataset['prediction'];
    fakePredictionHttpRoutes([
        'https://predictor.test/jobs/requeue' => fn (Request $request) => $request['smiles'] === $refused->predictionStructure->canonical_smiles
            ? Http::response(['detail' => 'Unknown job'], 404)
            : Http::response(filamentRemoteJobSnapshot(['status' => 'queued', 'current_step' => 'cosmo'])),
    ]);

    Livewire::test(PredictionsRelationManager::class, ['ownerRecord' => $dataset['dataset'], 'pageClass' => EditPredictionDataset::class])
        ->assertCanSeeTableRecords([$requeued, $refused, $running])
        ->callTableAction('requeue_failed')
        ->assertNotified('Requeued 1 prediction(s), 1 failed');

    expect($requeued->refresh()->state)->toBe(Prediction::STATE_RUNNING)
        ->and($requeued->remote_status)->toBe('queued')
        ->and($refused->refresh()->state)->toBe(Prediction::STATE_STOPPED);

    Http::assertNotSent(fn (Request $request): bool => $request['smiles'] === $running->predictionStructure->canonical_smiles);
});

test('requeueing failed predictions stops at the admin bulk limit', function () {
    config()->set('prediction-workers.remote.worker.admin_bulk_limit', 1);
    $dataset = createPredictionDataset(User::factory()->create());
    addPredictionToFilamentDataset($dataset, ['state' => Prediction::STATE_ERROR, 'remote_calculation_id' => 'calc-1']);
    addPredictionToFilamentDataset($dataset, ['state' => Prediction::STATE_ERROR, 'remote_calculation_id' => 'calc-2']);
    fakePredictionHttpRoutes([
        'https://predictor.test/jobs/requeue' => Http::response(filamentRemoteJobSnapshot(['status' => 'queued'])),
    ]);

    Livewire::test(PredictionsRelationManager::class, ['ownerRecord' => $dataset['dataset'], 'pageClass' => EditPredictionDataset::class])
        ->callTableAction('requeue_failed');

    expect(lastFilamentNotificationTitle())->toBe('Requeued 1 prediction(s)')
        ->and(Prediction::query()->where('state', Prediction::STATE_ERROR)->count())->toBe(1);
    Http::assertSentCount(1);
});

test('bulk actions report an unusable token without contacting the jobs', function (string $action) {
    Config::set(Config::KEY_REMOTE_PREDICTION_TOKEN_EXPIRES_AT, now()->subDay()->toIso8601String());
    $dataset = createPredictionDataset(User::factory()->create());
    $failed = addPredictionToFilamentDataset($dataset, ['state' => Prediction::STATE_ERROR, 'remote_calculation_id' => 'calc-1']);
    fakePredictionHttpRoutes([]);

    Livewire::test(PredictionsRelationManager::class, ['ownerRecord' => $dataset['dataset'], 'pageClass' => EditPredictionDataset::class])
        ->callTableAction($action);

    expect(lastFilamentNotificationTitle())->toStartWith('Token error: ')
        ->and($failed->refresh()->state)->toBe(Prediction::STATE_ERROR);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/jobs') || str_contains($request->url(), '/calculations'));
})->with(['requeue_failed', 'download_results']);

test('downloading results stores the results of completed predictions and records failures', function () {
    $disk = usePredictionResultsDirectory($this);
    $dataset = createPredictionDataset(User::factory()->create());
    $completed = addPredictionToFilamentDataset($dataset, ['remote_calculation_id' => 'calc-ok', 'remote_status' => 'completed']);
    $broken = addPredictionToFilamentDataset($dataset, ['remote_calculation_id' => 'calc-broken', 'remote_status' => 'completed']);
    $running = $dataset['prediction'];
    $calculationIds = [
        $completed->predictionStructure->canonical_smiles => 'calc-ok',
        $broken->predictionStructure->canonical_smiles => 'calc-broken',
    ];
    fakePredictionHttpRoutes([
        'https://predictor.test/jobs/status' => fn (Request $request) => Http::response(filamentRemoteJobSnapshot([
            'canonical_smiles' => $request['smiles'],
            'status' => 'completed',
            'calculations' => [[
                'id' => $calculationIds[$request['smiles']],
                'molecule_id' => 'mol-1',
                'membrane_key' => Str::slug($dataset['membrane']->abbreviation),
                'temperature_c' => 25.0,
                'status' => 'completed',
            ]],
        ])),
        'https://predictor.test/calculations/calc-ok/download' => Http::response(cosmoResultXml(), 200, ['Content-Type' => 'application/xml']),
        'https://predictor.test/calculations/calc-broken/download' => Http::response(['detail' => 'Gone'], 410),
    ]);

    Livewire::test(PredictionsRelationManager::class, ['ownerRecord' => $dataset['dataset'], 'pageClass' => EditPredictionDataset::class])
        ->callTableAction('download_results');

    expect(lastFilamentNotificationTitle())->toBe('Downloaded 1 result(s), 1 failed')
        ->and($completed->refresh()->result_id)->not->toBeNull()
        ->and($completed->state)->toBe(Prediction::STATE_FINISHED)
        ->and($broken->refresh()->result_id)->toBeNull()
        ->and($broken->remote_error_message)->not->toBeNull()
        ->and($running->refresh()->result_id)->toBeNull();

    Storage::disk($disk)->assertExists($completed->predictionResult->file->path);
});

test('the structures tab lists only the structures of the dataset predictions', function () {
    $dataset = createPredictionDataset(User::factory()->create());
    $second = addPredictionToFilamentDataset($dataset);
    ['structure' => $foreignStructure] = createPredictionDataset(User::factory()->create());

    Livewire::test(StructuresRelationManager::class, ['ownerRecord' => $dataset['dataset'], 'pageClass' => EditPredictionDataset::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$dataset['structure'], $second->predictionStructure])
        ->assertCanNotSeeTableRecords([$foreignStructure]);

    expect(StructuresRelationManager::getBadge($dataset['dataset'], EditPredictionDataset::class))->toBe('2')
        ->and(PredictionsRelationManager::getBadge($dataset['dataset'], EditPredictionDataset::class))->toBe('2');
});

test('an empty dataset shows zero badges in the danger color', function () {
    $dataset = createPredictionDataset(User::factory()->create());
    $dataset['dataset']->predictions()->detach();

    expect(StructuresRelationManager::getBadge($dataset['dataset'], EditPredictionDataset::class))->toBe('0')
        ->and(StructuresRelationManager::getBadgeColor($dataset['dataset'], EditPredictionDataset::class))->toBe('danger')
        ->and(PredictionsRelationManager::getBadgeColor($dataset['dataset'], EditPredictionDataset::class))->toBe('danger');
});
