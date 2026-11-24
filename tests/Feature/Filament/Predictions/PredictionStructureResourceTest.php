<?php

require_once __DIR__.'/filament_prediction_helpers.php';

use App\Enums\PermissionEnums;
use App\Filament\Resources\PredictionDatasets\RelationManagers\PredictionsRelationManager;
use App\Filament\Resources\Predictions\RelationManagers\PredictionDatasetsRelationManager;
use App\Filament\Resources\PredictionStructures\Pages\EditPredictionStructure;
use App\Filament\Resources\PredictionStructures\Pages\ListPredictionStructures;
use App\Filament\Resources\PredictionStructures\PredictionStructureResource;
use App\Filament\Resources\Structures\StructureResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Livewire\Livewire;
use Modules\PredictionWorkers\Models\PredictionStructure;

/*
 * Structures submitted for prediction, for holders of predictions.manage
 * only: listing, linking to MolMeDB structures, deleting and the datasets
 * and predictions tabs of a structure.
 */

beforeEach(function () {
    seedFilamentPredictionPermissions();
});

test('prediction structures are forbidden without predictions.manage, also to dataset managers', function () {
    ['structure' => $structure] = createPredictionDataset(User::factory()->create());
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTION_DATASET_MANAGE_ALL]));

    Livewire::test(ListPredictionStructures::class)->assertForbidden();
    Livewire::test(EditPredictionStructure::class, ['record' => $structure->getRouteKey()])->assertForbidden();
});

test('the structure list shows linked and unlinked structures and searches by SMILES', function () {
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTIONS_MANAGE]));
    $molmedbStructure = createApiStructure();
    $linked = PredictionStructure::query()->create(['canonical_smiles' => 'CCCCO', 'remote_id' => $molmedbStructure->id]);
    $unlinked = PredictionStructure::query()->create(['canonical_smiles' => 'c1ccccc1']);

    Livewire::test(ListPredictionStructures::class)
        ->assertCanSeeTableRecords([$linked, $unlinked])
        ->assertTableColumnStateSet('structure.identifier', $molmedbStructure->identifier, $linked)
        ->assertTableActionEnabled('show_structure', $linked)
        ->assertTableActionHasUrl('show_structure', StructureResource::getUrl('edit', ['record' => $molmedbStructure]), $linked)
        ->assertTableActionDisabled('show_structure', $unlinked)
        ->assertTableActionHasUrl('edit', PredictionStructureResource::getUrl('edit', ['record' => $linked]), $linked)
        ->searchTable('c1ccccc1')
        ->assertCanSeeTableRecords([$unlinked])
        ->assertCanNotSeeTableRecords([$linked]);
});

test('predictions.manage holders delete structures one by one and in bulk', function () {
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTIONS_MANAGE]));
    $first = PredictionStructure::query()->create(['canonical_smiles' => 'CO']);
    $second = PredictionStructure::query()->create(['canonical_smiles' => 'CCO']);
    $kept = PredictionStructure::query()->create(['canonical_smiles' => 'CCCO']);

    Livewire::test(ListPredictionStructures::class)
        ->callTableBulkAction(DeleteBulkAction::class, [$first, $second]);

    Livewire::test(EditPredictionStructure::class, ['record' => $kept->getRouteKey()])
        ->assertOk()
        ->assertSee('CCCO')
        ->callAction(DeleteAction::class);

    expect(PredictionStructure::query()->count())->toBe(0);
});

test('the predictions tab of a structure lists only its predictions', function () {
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTIONS_MANAGE]));
    ['structure' => $structure, 'prediction' => $prediction] = createPredictionDataset(User::factory()->create());
    ['prediction' => $samePrediction] = createPredictionDataset(User::factory()->create(), structure: $structure);
    ['prediction' => $foreign] = createPredictionDataset(User::factory()->create());

    Livewire::test(PredictionsRelationManager::class, ['ownerRecord' => $structure, 'pageClass' => EditPredictionStructure::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$prediction, $samePrediction])
        ->assertCanNotSeeTableRecords([$foreign]);
});

test('the datasets tab of a structure lists the datasets of its predictions', function () {
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTIONS_MANAGE, PermissionEnums::PREDICTION_DATASET_MANAGE_ALL]));
    // A structure without predictions, so that structure and prediction ids differ.
    $withoutPredictions = PredictionStructure::query()->create(['canonical_smiles' => 'O']);
    ['structure' => $structure, 'dataset' => $dataset] = createPredictionDataset(User::factory()->create());
    ['dataset' => $secondDataset] = createPredictionDataset(User::factory()->create(), structure: $structure);
    ['dataset' => $foreign] = createPredictionDataset(User::factory()->create());

    Livewire::test(PredictionDatasetsRelationManager::class, ['ownerRecord' => $structure, 'pageClass' => EditPredictionStructure::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$dataset, $secondDataset])
        ->assertCanNotSeeTableRecords([$foreign]);

    Livewire::test(PredictionDatasetsRelationManager::class, ['ownerRecord' => $withoutPredictions, 'pageClass' => EditPredictionStructure::class])
        ->assertCountTableRecords(0);

    expect(PredictionDatasetsRelationManager::getBadge($structure, EditPredictionStructure::class))->toBe('2')
        ->and(PredictionDatasetsRelationManager::getBadge($withoutPredictions, EditPredictionStructure::class))->toBe('0');
});
