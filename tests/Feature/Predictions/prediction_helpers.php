<?php

require_once __DIR__.'/../Api/api_test_helpers.php';

use App\Enums\PermissionEnums;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionDataset;
use Modules\PredictionWorkers\Models\PredictionMembrane;
use Modules\PredictionWorkers\Models\PredictionStructure;
use Spatie\Permission\PermissionRegistrar;

/**
 * A prediction dataset of the owner with one running prediction of a new
 * structure in a new prediction membrane.
 *
 * @param  array<string, mixed>  $predictionAttributes
 * @return array{dataset: PredictionDataset, prediction: Prediction, structure: PredictionStructure, membrane: PredictionMembrane}
 */
function createPredictionDataset(?User $owner, array $predictionAttributes = [], ?PredictionStructure $structure = null): array
{
    $molmedbMembrane = createApiMembrane(['abbreviation' => 'DOPC'.Str::random(3)]);
    $membrane = PredictionMembrane::query()->create([
        'remote_id' => $molmedbMembrane->id,
        'name' => $molmedbMembrane->name,
        'abbreviation' => $molmedbMembrane->abbreviation,
    ]);

    $structure ??= PredictionStructure::query()->create([
        'canonical_smiles' => 'CCO'.Str::random(4),
    ]);

    $dataset = PredictionDataset::query()->create([
        'comment' => 'Ethanol permeability',
        'token' => Str::random(32),
        'user_id' => $owner?->id,
        'temperature' => 25.0,
        'membrane_id' => $membrane->id,
        'method_type' => 'cosmoperm',
        'priority' => Prediction::PRIORITY_MEDIUM,
    ]);

    $prediction = Prediction::query()->create([
        'structure_id' => $structure->id,
        'state' => Prediction::STATE_RUNNING,
        'step' => Prediction::STEP_COSMO,
        'temperature' => 25.0,
        'membrane_id' => $membrane->id,
        'method_type' => 'cosmoperm',
        'priority' => Prediction::PRIORITY_MEDIUM,
        ...$predictionAttributes,
    ]);

    $dataset->predictions()->attach($prediction->id);

    return ['dataset' => $dataset, 'prediction' => $prediction, 'structure' => $structure, 'membrane' => $membrane];
}

function createPredictionManager(): User
{
    $permission = Permission::query()->firstOrCreate(
        ['name' => PermissionEnums::PREDICTION_DATASET_MANAGE_ALL->value, 'guard_name' => 'web'],
        ['description' => PermissionEnums::PREDICTION_DATASET_MANAGE_ALL->description()],
    );
    $role = Role::query()->create(['name' => 'Prediction managers '.Str::random(4), 'guard_name' => 'web']);
    $role->givePermissionTo($permission);

    $manager = User::factory()->create();
    $manager->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $manager->refresh();
}
