<?php

require_once __DIR__.'/filament_prediction_helpers.php';

use App\Enums\PermissionEnums;
use App\Filament\Resources\PredictionDatasets\Pages\CreatePredictionDataset;
use App\Filament\Resources\PredictionDatasets\Pages\EditPredictionDataset;
use App\Filament\Resources\PredictionDatasets\Pages\ListPredictionDatasets;
use App\Filament\Resources\PredictionDatasets\RelationManagers\PredictionsRelationManager;
use App\Filament\Resources\PredictionDatasets\RelationManagers\StructuresRelationManager;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Livewire\Livewire;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionDataset;
use Modules\PredictionWorkers\Models\PredictionMethod;

/*
 * Prediction datasets in the admin panel: every dataset for holders of
 * prediction_dataset.manage.all, otherwise only the owner's own dataset
 * opened directly.
 */

beforeEach(function () {
    seedFilamentPredictionPermissions();
});

test('dataset managers list the datasets of every user', function () {
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTION_DATASET_MANAGE_ALL]));
    ['dataset' => $foreign] = createPredictionDataset(User::factory()->create());
    ['dataset' => $anonymous] = createPredictionDataset(null);

    Livewire::test(ListPredictionDatasets::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$foreign, $anonymous]);
});

test('the dataset list is forbidden to dataset owners and prediction managers without the manage all permission', function (array $permissions) {
    $user = createFilamentPredictionUser($permissions);
    createPredictionDataset($user);
    $this->actingAs($user);

    Livewire::test(ListPredictionDatasets::class)->assertForbidden();
    Livewire::test(CreatePredictionDataset::class)->assertForbidden();
})->with([
    'owner with panel access only' => [[]],
    'predictions.manage holder' => [[PermissionEnums::PREDICTIONS_MANAGE]],
]);

test('the dataset list filters by owner, priority and method and is searchable by id', function () {
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTION_DATASET_MANAGE_ALL]));
    $owner = User::factory()->create();
    ['dataset' => $owned] = createPredictionDataset($owner);
    ['dataset' => $urgent] = createPredictionDataset(User::factory()->create());
    $urgent->forceFill(['priority' => Prediction::PRIORITY_HIGH])->saveQuietly();
    PredictionMethod::query()->create(['key' => 'cosmomic', 'label' => 'CosmoMic', 'remote_key' => 'cosmomic']);
    forgetFilamentPredictionMethodCache();
    ['dataset' => $micelle] = createPredictionDataset(User::factory()->create());
    $micelle->forceFill(['method_type' => 'cosmomic'])->saveQuietly();

    Livewire::test(ListPredictionDatasets::class)
        ->filterTable('user_id', [$owner->id])
        ->assertCanSeeTableRecords([$owned])
        ->assertCanNotSeeTableRecords([$urgent, $micelle])
        ->resetTableFilters()
        ->filterTable('priority', [Prediction::PRIORITY_HIGH])
        ->assertCanSeeTableRecords([$urgent])
        ->assertCanNotSeeTableRecords([$owned, $micelle])
        ->resetTableFilters()
        ->filterTable('method_type', ['cosmomic'])
        ->assertCanSeeTableRecords([$micelle])
        ->assertCanNotSeeTableRecords([$owned, $urgent])
        ->resetTableFilters()
        ->searchTable((string) $urgent->id)
        ->assertCanSeeTableRecords([$urgent])
        ->assertCanNotSeeTableRecords([$owned]);
});

test('a dataset manager creates a dataset in a membrane with a COSMO file', function () {
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTION_DATASET_MANAGE_ALL]));
    $membrane = createCosmoPredictionMembrane();

    Livewire::test(CreatePredictionDataset::class)
        ->fillForm([
            'temperature' => 30,
            'method_type' => 'cosmoperm',
            'membrane_id' => $membrane->id,
            'priority' => Prediction::PRIORITY_HIGH,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PredictionDataset::query()->sole())
        ->temperature->toEqual(30)
        ->method_type->toBe('cosmoperm')
        ->membrane_id->toBe($membrane->id)
        ->priority->toBe(Prediction::PRIORITY_HIGH);
});

test('a new dataset needs a known method and priority, a COSMO membrane and a non-negative temperature', function (array $data, array $errors) {
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTION_DATASET_MANAGE_ALL]));
    $membrane = createCosmoPredictionMembrane();
    $membraneWithoutCosmo = createCosmoPredictionMembrane(withCosmoFile: false);

    $resolved = array_map(fn ($value) => match ($value) {
        'cosmo membrane' => $membrane->id,
        'membrane without cosmo' => $membraneWithoutCosmo->id,
        default => $value,
    }, [
        'temperature' => 37,
        'method_type' => 'cosmoperm',
        'membrane_id' => 'cosmo membrane',
        'priority' => Prediction::PRIORITY_MEDIUM,
        ...$data,
    ]);

    Livewire::test(CreatePredictionDataset::class)
        ->fillForm($resolved)
        ->call('create')
        ->assertHasFormErrors($errors);

    expect(PredictionDataset::query()->count())->toBe(0);
})->with([
    'membrane without COSMO file' => [['membrane_id' => 'membrane without cosmo'], ['membrane_id']],
    'negative temperature' => [['temperature' => -5], ['temperature' => 'min']],
    'unknown method' => [['method_type' => 'unknown'], ['method_type']],
    'unknown priority' => [['priority' => 99], ['priority']],
    'missing method' => [['method_type' => null], ['method_type' => 'required']],
]);

test('the owner cannot open their own dataset in the panel without the manage all permission', function () {
    // The dataset policy lets owners update and delete their datasets (used by
    // the API), but every page of a Filament resource also needs viewAny.
    $owner = createFilamentPredictionUser();
    ['dataset' => $dataset] = createPredictionDataset($owner);
    $this->actingAs($owner);

    Livewire::test(EditPredictionDataset::class, ['record' => $dataset->getRouteKey()])->assertForbidden();
});

test('a dataset of another user cannot be opened without the manage all permission', function (array $permissions) {
    ['dataset' => $dataset] = createPredictionDataset(User::factory()->create());
    $this->actingAs(createFilamentPredictionUser($permissions));

    Livewire::test(EditPredictionDataset::class, ['record' => $dataset->getRouteKey()])->assertForbidden();
})->with([
    'panel access only' => [[]],
    'predictions.manage holder' => [[PermissionEnums::PREDICTIONS_MANAGE]],
]);

test('a dataset manager edits and deletes a dataset of another user', function () {
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTION_DATASET_MANAGE_ALL]));
    ['dataset' => $dataset] = createPredictionDataset(User::factory()->create());
    $membrane = createCosmoPredictionMembrane();

    $page = Livewire::test(EditPredictionDataset::class, ['record' => $dataset->getRouteKey()])
        ->fillForm(['temperature' => 20, 'membrane_id' => $membrane->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($dataset->refresh())
        ->temperature->toEqual(20)
        ->membrane_id->toBe($membrane->id);

    $page->callAction(DeleteAction::class);

    expect(PredictionDataset::query()->find($dataset->id))->toBeNull();
});

test('raising the priority of a dataset raises the priority of its predictions to the highest of their datasets', function () {
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTION_DATASET_MANAGE_ALL]));
    ['dataset' => $dataset, 'prediction' => $prediction] = createPredictionDataset(User::factory()->create());
    ['dataset' => $other, 'prediction' => $untouched] = createPredictionDataset(User::factory()->create());
    $other->predictions()->attach($prediction->id);
    $dataset->forceFill(['membrane_id' => createCosmoPredictionMembrane()->id])->saveQuietly();

    Livewire::test(EditPredictionDataset::class, ['record' => $dataset->getRouteKey()])
        ->fillForm(['priority' => Prediction::PRIORITY_HIGH])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($prediction->refresh()->priority)->toBe(Prediction::PRIORITY_HIGH)
        ->and($untouched->refresh()->priority)->toBe(Prediction::PRIORITY_MEDIUM);

    // Another dataset of the prediction still has the high priority.
    $other->update(['priority' => Prediction::PRIORITY_LOW]);

    expect($prediction->refresh()->priority)->toBe(Prediction::PRIORITY_HIGH);
});

test('the predictions and structures of a dataset are shown only to predictions.manage holders', function () {
    $owner = createFilamentPredictionUser([PermissionEnums::PREDICTION_DATASET_MANAGE_ALL]);
    ['dataset' => $dataset] = createPredictionDataset($owner);

    $this->actingAs($owner);

    expect(PredictionsRelationManager::canViewForRecord($dataset, EditPredictionDataset::class))->toBeFalse()
        ->and(StructuresRelationManager::canViewForRecord($dataset, EditPredictionDataset::class))->toBeFalse();

    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTION_DATASET_MANAGE_ALL, PermissionEnums::PREDICTIONS_MANAGE]));

    expect(PredictionsRelationManager::canViewForRecord($dataset, EditPredictionDataset::class))->toBeTrue()
        ->and(StructuresRelationManager::canViewForRecord($dataset, EditPredictionDataset::class))->toBeTrue();
});
