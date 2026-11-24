<?php

require_once __DIR__.'/filament_prediction_helpers.php';

use App\Enums\PermissionEnums;
use App\Filament\Resources\PredictionMethods\Pages\CreatePredictionMethod;
use App\Filament\Resources\PredictionMethods\Pages\EditPredictionMethod;
use App\Filament\Resources\PredictionMethods\Pages\ListPredictionMethods;
use Filament\Actions\DeleteAction;
use Livewire\Livewire;
use Modules\PredictionWorkers\Models\PredictionMethod;

/*
 * Prediction methods configure the remote calculations and are managed by
 * holders of predictions.manage only.
 */

beforeEach(function () {
    seedFilamentPredictionPermissions();
});

/**
 * Valid form data of a new prediction method.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function filamentPredictionMethodData(array $overrides = []): array
{
    return [
        'key' => 'cosmomic',
        'remote_id' => createApiMethod(['abbreviation' => 'COSMOmic'])->id,
        'label' => 'COSMOmic',
        'remote_key' => 'cosmomic-remote',
        'short_key' => 'mic',
        'enabled' => true,
        'primary_publication_id' => createApiPublication()->id,
        ...$overrides,
    ];
}

test('prediction methods are forbidden without predictions.manage', function () {
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTION_DATASET_MANAGE_ALL, PermissionEnums::MEMBRANE_METHOD_EDIT]));

    Livewire::test(ListPredictionMethods::class)->assertForbidden();
    Livewire::test(CreatePredictionMethod::class)->assertForbidden();
    Livewire::test(EditPredictionMethod::class, ['record' => 'cosmoperm'])->assertForbidden();
});

test('predictions.manage holders create a prediction method', function () {
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTIONS_MANAGE]));
    $data = filamentPredictionMethodData();

    Livewire::test(CreatePredictionMethod::class)
        ->fillForm($data)
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PredictionMethod::query()->find('cosmomic'))
        ->remote_id->toBe($data['remote_id'])
        ->label->toBe('COSMOmic')
        ->remote_key->toBe('cosmomic-remote')
        ->short_key->toBe('mic')
        ->enabled->toBeTrue()
        ->primary_publication_id->toBe($data['primary_publication_id'])
        ->secondary_publication_id->toBeNull();
});

test('a new prediction method is validated', function (array $overrides, array $errors) {
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTIONS_MANAGE]));

    Livewire::test(CreatePredictionMethod::class)
        ->fillForm(filamentPredictionMethodData($overrides))
        ->call('create')
        ->assertHasFormErrors($errors);

    expect(PredictionMethod::query()->count())->toBe(1);
})->with([
    'key with capitals' => [['key' => 'CosmoMic'], ['key' => 'regex']],
    'too long key' => [['key' => str_repeat('a', 21)], ['key' => 'max']],
    'existing key' => [['key' => 'cosmoperm'], ['key' => 'unique']],
    'short key with a dash' => [['short_key' => 'mi-c'], ['short_key' => 'regex']],
    'too long short key' => [['short_key' => str_repeat('a', 11)], ['short_key' => 'max']],
    'existing remote key' => [['remote_key' => 'cosmoperm'], ['remote_key' => 'unique']],
    'missing method' => [['remote_id' => null], ['remote_id' => 'required']],
    'missing primary reference' => [['primary_publication_id' => null], ['primary_publication_id' => 'required']],
    'missing label' => [['label' => ''], ['label' => 'required']],
]);

test('a method already mapped to another prediction method cannot be mapped again', function () {
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTIONS_MANAGE]));
    $data = filamentPredictionMethodData();
    PredictionMethod::query()->whereKey('cosmoperm')->update(['remote_id' => $data['remote_id']]);

    Livewire::test(CreatePredictionMethod::class)
        ->fillForm($data)
        ->call('create')
        ->assertHasFormErrors(['remote_id' => 'unique']);
});

test('the key and short key of a prediction method stay as created', function () {
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTIONS_MANAGE]));
    $publication = createApiPublication();
    PredictionMethod::query()->whereKey('cosmoperm')->update([
        'remote_id' => createApiMethod()->id,
        'primary_publication_id' => $publication->id,
    ]);
    $secondary = createApiPublication(['citation' => 'Roe R.: Another publication.']);

    Livewire::test(EditPredictionMethod::class, ['record' => 'cosmoperm'])
        ->assertFormFieldDisabled('key')
        ->assertFormFieldDisabled('short_key')
        ->fillForm([
            'key' => 'renamed',
            'short_key' => 'ren',
            'label' => 'COSMOperm v2',
            'enabled' => false,
            'secondary_publication_id' => $secondary->id,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(PredictionMethod::query()->find('cosmoperm'))
        ->short_key->toBe('perm')
        ->label->toBe('COSMOperm v2')
        ->enabled->toBeFalse()
        ->secondary_publication_id->toBe($secondary->id)
        ->and(PredictionMethod::query()->find('renamed'))->toBeNull();
});

test('a prediction method without predictions can be deleted', function () {
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTIONS_MANAGE]));
    PredictionMethod::query()->create(['key' => 'unused', 'label' => 'Unused', 'remote_key' => 'unused']);

    Livewire::test(EditPredictionMethod::class, ['record' => 'unused'])
        ->callAction(DeleteAction::class);

    expect(PredictionMethod::query()->find('unused'))->toBeNull();
});

test('the method list filters by the enabled flag and searches by label', function () {
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTIONS_MANAGE]));
    $enabled = PredictionMethod::query()->findOrFail('cosmoperm');
    $disabled = PredictionMethod::query()->create(['key' => 'retired', 'label' => 'Retired method', 'remote_key' => 'retired', 'enabled' => false]);

    Livewire::test(ListPredictionMethods::class)
        ->assertCanSeeTableRecords([$enabled, $disabled])
        ->filterTable('enabled', false)
        ->assertCanSeeTableRecords([$disabled])
        ->assertCanNotSeeTableRecords([$enabled])
        ->resetTableFilters()
        ->searchTable('Retired')
        ->assertCanSeeTableRecords([$disabled])
        ->assertCanNotSeeTableRecords([$enabled]);
});

test('the method list searches by the name of the mapped method in the main database', function () {
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTIONS_MANAGE]));
    $unmapped = PredictionMethod::query()->findOrFail('cosmoperm');
    $mapped = PredictionMethod::query()->create([
        'key' => 'pampa',
        'label' => 'Prediction of PAMPA',
        'remote_key' => 'pampa',
        'remote_id' => createApiMethod(['name' => 'Black Lipid Membrane assay'])->id,
    ]);

    Livewire::test(ListPredictionMethods::class)
        ->assertTableColumnExists('method.name', fn ($column): bool => ! $column->isSortable())
        ->searchTable('lipid membrane')
        ->assertCanSeeTableRecords([$mapped])
        ->assertCanNotSeeTableRecords([$unmapped]);
});
