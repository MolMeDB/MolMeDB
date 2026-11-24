<?php

require_once __DIR__.'/filament_prediction_helpers.php';

use App\Enums\PermissionEnums;
use App\Filament\Resources\PredictionDatasets\PredictionDatasetResource;
use App\Filament\Resources\Predictions\Pages\CreatePrediction;
use App\Filament\Resources\Predictions\Pages\EditPrediction;
use App\Filament\Resources\Predictions\Pages\ListPredictions;
use App\Filament\Resources\Predictions\RelationManagers\PredictionDatasetsRelationManager;
use App\Filament\Resources\Predictions\RelationManagers\PredictionResultsRelationManager;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionFile;
use Modules\PredictionWorkers\Models\PredictionResult;

/*
 * Prediction requests in the admin panel: listing, the detail page with its
 * requeue actions and the datasets and results tabs, for holders of
 * predictions.manage only.
 */

beforeEach(function () {
    seedFilamentPredictionPermissions();
    enableRemotePredictionService();
});

function actAsFilamentPredictionManager(object $test): void
{
    $test->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTIONS_MANAGE]));
}

test('predictions are forbidden without predictions.manage, also to dataset managers', function () {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create());
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTION_DATASET_MANAGE_ALL]));

    Livewire::test(ListPredictions::class)->assertForbidden();
    Livewire::test(EditPrediction::class, ['record' => $prediction->getRouteKey()])->assertForbidden();
});

test('the prediction list filters by state, pause, method and priority and searches by id', function () {
    actAsFilamentPredictionManager($this);
    ['prediction' => $running] = createPredictionDataset(User::factory()->create());
    ['prediction' => $failed] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_ERROR]);
    ['prediction' => $paused] = createPredictionDataset(User::factory()->create(), ['remote_paused_at' => now(), 'remote_pause_reason' => 'Maintenance']);
    ['prediction' => $urgent] = createPredictionDataset(User::factory()->create(), ['priority' => Prediction::PRIORITY_HIGH, 'state' => Prediction::STATE_FINISHED]);

    Livewire::test(ListPredictions::class)
        ->assertCanSeeTableRecords([$running, $failed, $paused, $urgent])
        ->filterTable('state', [Prediction::STATE_ERROR])
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$running, $paused, $urgent])
        ->resetTableFilters()
        ->filterTable('remote_paused_at', true)
        ->assertCanSeeTableRecords([$paused])
        ->assertCanNotSeeTableRecords([$running, $failed, $urgent])
        ->resetTableFilters()
        ->filterTable('priority', [Prediction::PRIORITY_HIGH])
        ->assertCanSeeTableRecords([$urgent])
        ->assertCanNotSeeTableRecords([$running, $failed, $paused])
        ->resetTableFilters()
        ->filterTable('method_type', ['cosmoperm'])
        ->assertCanSeeTableRecords([$running, $failed, $paused, $urgent])
        ->resetTableFilters()
        ->searchTable((string) $failed->id)
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$running]);
});

test('the prediction detail shows the status of the request', function () {
    actAsFilamentPredictionManager($this);
    ['prediction' => $prediction, 'structure' => $structure] = createPredictionDataset(User::factory()->create(), [
        'remote_calculation_id' => 'calc-1',
        'remote_current_step' => 'cosmo',
    ]);

    Livewire::test(EditPrediction::class, ['record' => $prediction->getRouteKey()])
        ->assertOk()
        ->assertSee($structure->canonical_smiles)
        ->assertActionEnabled('requeue')
        ->assertActionEnabled('forceRequeue');
});

test('requeueing is disabled before the prediction reaches the remote service', function () {
    actAsFilamentPredictionManager($this);
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => null]);

    Livewire::test(EditPrediction::class, ['record' => $prediction->getRouteKey()])
        ->assertActionDisabled('requeue')
        ->assertActionDisabled('forceRequeue');
});

test('a requeue sends the prediction to the service again', function () {
    actAsFilamentPredictionManager($this);
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), [
        'remote_calculation_id' => 'calc-1',
        'remote_status' => 'failed',
        'state' => Prediction::STATE_ERROR,
        'remote_error_message' => 'Turbomole crashed.',
    ]);
    fakePredictionHttpRoutes([
        'https://predictor.test/jobs/requeue' => Http::response(filamentRemoteJobSnapshot([
            'status' => 'queued',
            'requeue' => ['from_step' => 'optimization-turbomole', 'requeued_at' => now()->toIso8601String()],
        ])),
    ]);

    Livewire::test(EditPrediction::class, ['record' => $prediction->getRouteKey()])
        ->callAction('requeue')
        ->assertNotified('Prediction requeued');

    expect($prediction->refresh())
        ->state->toBe(Prediction::STATE_RUNNING)
        ->remote_status->toBe('queued')
        ->remote_current_step->toBe('optimization-turbomole')
        ->remote_error_message->toBeNull();
});

test('a refused requeue is reported and keeps the prediction failed', function () {
    actAsFilamentPredictionManager($this);
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), [
        'remote_calculation_id' => 'calc-1',
        'state' => Prediction::STATE_ERROR,
    ]);
    fakePredictionHttpRoutes([
        'https://predictor.test/jobs/requeue' => Http::response(['detail' => 'Job not found'], 404),
    ]);

    Livewire::test(EditPrediction::class, ['record' => $prediction->getRouteKey()])
        ->callAction('requeue')
        ->assertNotified('Prediction requeue failed');

    expect($prediction->refresh()->state)->toBe(Prediction::STATE_ERROR);
});

test('a force requeue runs the job again from a reached step', function () {
    actAsFilamentPredictionManager($this);
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), [
        'remote_calculation_id' => 'calc-1',
        'state' => Prediction::STATE_FINISHED,
    ]);
    fakePredictionHttpRoutes([
        'https://predictor.test/jobs/status' => Http::response(filamentRemoteJobSnapshot(['current_step' => 'conformers'])),
        'https://predictor.test/jobs/requeue/conformers' => Http::response(filamentRemoteJobSnapshot(['status' => 'queued'])),
    ]);

    Livewire::test(EditPrediction::class, ['record' => $prediction->getRouteKey()])
        ->callAction('forceRequeue', data: ['step' => 'conformers'])
        ->assertHasNoActionErrors()
        ->assertNotified('Prediction force requeued');

    expect($prediction->refresh())
        ->state->toBe(Prediction::STATE_RUNNING)
        ->remote_current_step->toBe('conformers');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://predictor.test/jobs/requeue/conformers');
});

test('a force requeue needs a step the job has reached', function () {
    actAsFilamentPredictionManager($this);
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-1']);
    fakePredictionHttpRoutes([
        'https://predictor.test/jobs/status' => Http::response(filamentRemoteJobSnapshot()),
    ]);

    Livewire::test(EditPrediction::class, ['record' => $prediction->getRouteKey()])
        ->callAction('forceRequeue', data: ['step' => null])
        ->assertHasActionErrors(['step' => 'required']);

    Livewire::test(EditPrediction::class, ['record' => $prediction->getRouteKey()])
        ->callAction('forceRequeue', data: ['step' => 'cosmo'])
        ->assertHasActionErrors(['step']);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/jobs/requeue'));
});

test('the prediction detail has no save button', function () {
    actAsFilamentPredictionManager($this);
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create());

    Livewire::test(EditPrediction::class, ['record' => $prediction->getRouteKey()])
        ->assertActionDoesNotExist(TestAction::make('save'));
});

test('the datasets tab lists the datasets the prediction is part of', function () {
    // The tab is shown to users who may see prediction datasets.
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTIONS_MANAGE, PermissionEnums::PREDICTION_DATASET_MANAGE_ALL]));
    ['prediction' => $prediction, 'dataset' => $dataset] = createPredictionDataset(User::factory()->create());
    ['dataset' => $second] = createPredictionDataset(User::factory()->create());
    ['dataset' => $unrelated] = createPredictionDataset(User::factory()->create());
    $second->predictions()->attach($prediction->id);

    Livewire::test(PredictionDatasetsRelationManager::class, ['ownerRecord' => $prediction, 'pageClass' => EditPrediction::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$dataset, $second])
        ->assertCanNotSeeTableRecords([$unrelated])
        ->assertTableActionHasUrl('edit', PredictionDatasetResource::getUrl('edit', ['record' => $dataset]), $dataset);

    expect(PredictionDatasetsRelationManager::getBadge($prediction, EditPrediction::class))->toBe('2');
});

test('the results tab links the stored result file for download', function () {
    actAsFilamentPredictionManager($this);
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_FINISHED]);
    $file = PredictionFile::query()->create(['type' => PredictionFile::TYPE_RESULT_COSMO_XML, 'mime' => 'application/xml', 'storage' => 'local', 'path' => 'r/cosmo.xml', 'hash' => md5('result')]);
    $result = PredictionResult::query()->create(['file_id' => $file->id, 'data' => []]);
    $prediction->forceFill(['result_id' => $result->id])->save();

    Livewire::test(PredictionResultsRelationManager::class, ['ownerRecord' => $prediction, 'pageClass' => EditPrediction::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$result])
        ->assertTableActionEnabled('download', $result)
        ->assertTableActionHasUrl('download', route('predictionResult.download', ['hash' => $file->refresh()->hash]), $result);

    expect(PredictionResultsRelationManager::getBadge($prediction, EditPrediction::class))->toBe('1')
        ->and(PredictionResultsRelationManager::getBadgeColor($prediction, EditPrediction::class))->toBe('success');
});

test('a result without a stored file cannot be downloaded', function () {
    actAsFilamentPredictionManager($this);
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_FINISHED]);
    $result = PredictionResult::query()->create(['file_id' => null, 'data' => []]);
    $prediction->forceFill(['result_id' => $result->id])->save();

    Livewire::test(PredictionResultsRelationManager::class, ['ownerRecord' => $prediction, 'pageClass' => EditPrediction::class])
        ->assertTableActionDisabled('download', $result);
});

test('a prediction without a result shows an empty results tab in the danger color', function () {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create());

    expect(PredictionResultsRelationManager::getBadge($prediction, EditPrediction::class))->toBe('0')
        ->and(PredictionResultsRelationManager::getBadgeColor($prediction, EditPrediction::class))->toBe('danger');
});

test('the create page cannot create a prediction because its settings are locked', function () {
    actAsFilamentPredictionManager($this);

    Livewire::test(CreatePrediction::class)
        ->call('create')
        ->assertHasFormErrors(['method_type', 'membrane_id']);

    expect(Prediction::query()->count())->toBe(0);
});
