<?php

require_once __DIR__.'/prediction_helpers.php';

use App\Enums\PermissionEnums;
use App\Models\Permission;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/*
 * Prediction datasets are private to their owner. They are reached either
 * by a signed-in user the PredictionDatasetPolicy allows, or by anyone with
 * the dataset's token (?token=), never by guessing an id.
 */

beforeEach(function () {
    Permission::query()->firstOrCreate(
        ['name' => PermissionEnums::PREDICTION_DATASET_MANAGE_ALL->value, 'guard_name' => 'web'],
        ['description' => PermissionEnums::PREDICTION_DATASET_MANAGE_ALL->description()],
    );
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

dataset('dataset endpoints', [
    'detail' => fn (): string => '',
    'records' => fn (): string => '/records',
    'structures' => fn (): string => '/structures',
]);

test('the owner and prediction managers read a dataset', function (string $suffix) {
    $owner = User::factory()->create();
    ['dataset' => $dataset] = createPredictionDataset($owner);

    $this->actingAs($owner)->getJson("/api/predictions/datasets/{$dataset->id}{$suffix}")->assertOk();
    $this->actingAs(createPredictionManager())->getJson("/api/predictions/datasets/{$dataset->id}{$suffix}")->assertOk();
})->with('dataset endpoints');

test('another signed-in user cannot read a dataset by its id', function (string $suffix) {
    ['dataset' => $dataset] = createPredictionDataset(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->getJson("/api/predictions/datasets/{$dataset->id}{$suffix}")
        ->assertForbidden();
})->with('dataset endpoints');

test('a guest reads a dataset only with its token', function (string $suffix) {
    ['dataset' => $dataset] = createPredictionDataset(User::factory()->create());
    ['dataset' => $otherDataset] = createPredictionDataset(User::factory()->create());

    $this->getJson("/api/predictions/datasets/{$dataset->id}{$suffix}")->assertForbidden();
    $this->getJson("/api/predictions/datasets/{$dataset->id}{$suffix}?token={$dataset->token}")->assertOk();
    $this->getJson("/api/predictions/datasets/{$dataset->id}{$suffix}?token={$otherDataset->token}")->assertForbidden();
})->with('dataset endpoints');

test('a wrong token is refused even for the signed-in owner', function () {
    $owner = User::factory()->create();
    ['dataset' => $dataset] = createPredictionDataset($owner);

    $this->actingAs($owner)
        ->getJson("/api/predictions/datasets/{$dataset->id}?token=wrong-token")
        ->assertForbidden();
});

test('only the owner or a token holder may change the dataset comment', function () {
    $owner = User::factory()->create();
    ['dataset' => $dataset] = createPredictionDataset($owner);

    $this->actingAs(User::factory()->create())
        ->patchJson("/api/predictions/datasets/{$dataset->id}", ['comment' => 'Hijacked'])
        ->assertForbidden();

    expect($dataset->refresh()->comment)->toBe('Ethanol permeability');

    $this->actingAs($owner)
        ->patchJson("/api/predictions/datasets/{$dataset->id}", ['comment' => 'Rerun at 37 °C'])
        ->assertOk();

    expect($dataset->refresh()->comment)->toBe('Rerun at 37 °C');
});

test('the dataset list shows a user only their own datasets and managers all of them', function () {
    $owner = User::factory()->create();
    ['dataset' => $own] = createPredictionDataset($owner);
    ['dataset' => $foreign] = createPredictionDataset(User::factory()->create());

    $ownIds = collect($this->actingAs($owner)->getJson('/api/predictions/datasets')->assertOk()->json('data'))->pluck('id');
    $managerIds = collect($this->actingAs(createPredictionManager())->getJson('/api/predictions/datasets')->assertOk()->json('data'))->pluck('id');

    expect($ownIds->all())->toBe([$own->id])
        ->and($managerIds->all())->toContain($own->id, $foreign->id);
});

test('predictions of a structure need a token of a dataset with it or ownership of one', function () {
    $owner = User::factory()->create();
    ['dataset' => $dataset, 'structure' => $structure] = createPredictionDataset($owner);
    ['dataset' => $unrelated] = createPredictionDataset(User::factory()->create());

    $this->getJson("/api/predictions/byStructure/{$structure->id}")->assertForbidden();
    $this->getJson("/api/predictions/byStructure/{$structure->id}?token={$unrelated->token}")->assertForbidden();
    $this->getJson("/api/predictions/byStructure/{$structure->id}?token={$dataset->token}")->assertOk();
    $this->actingAs(User::factory()->create())->getJson("/api/predictions/byStructure/{$structure->id}")->assertForbidden();
    $this->actingAs($owner)->getJson("/api/predictions/byStructure/{$structure->id}")->assertOk();
});

test('a signed-in owner sees only the predictions of their own datasets for a shared structure', function () {
    $owner = User::factory()->create();
    ['prediction' => $own, 'structure' => $structure] = createPredictionDataset($owner);
    ['prediction' => $foreign] = createPredictionDataset(User::factory()->create(), structure: $structure);

    $ids = collect($this->actingAs($owner)->getJson("/api/predictions/byStructure/{$structure->id}")->assertOk()->json('data'))->pluck('id');

    expect($ids->all())->toContain($own->id)->not->toContain($foreign->id);
});
