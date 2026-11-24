<?php

require_once __DIR__.'/../../Predictions/remote_prediction_test_helpers.php';

use App\Enums\PermissionEnums;
use App\Enums\RoleEnums;
use App\Models\File;
use App\Models\Membrane;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionMembrane;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every permission and the built-in roles, as the migrations do in
 * production, and forgets the statically cached prediction methods.
 */
function seedFilamentPredictionPermissions(): void
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    collect(PermissionEnums::cases())->each(function (PermissionEnums $permissionEnum): void {
        Permission::query()->firstOrCreate([
            'name' => $permissionEnum->value,
            'guard_name' => 'web',
        ], [
            'description' => $permissionEnum->description(),
        ]);
    });

    collect(RoleEnums::cases())->each(function (RoleEnums $roleEnum): void {
        Role::query()->firstOrCreate([
            'name' => $roleEnum->value,
            'guard_name' => 'web',
        ]);
    });

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    forgetFilamentPredictionMethodCache();
}

/**
 * Prediction methods are cached statically for the whole process.
 */
function forgetFilamentPredictionMethodCache(): void
{
    Closure::bind(function (): void {
        static::$methodsCache = null;
    }, null, Prediction::class)();
}

/**
 * A user of a custom role that may enter the admin panel and has the given
 * permissions on top of it.
 *
 * @param  array<int, PermissionEnums>  $permissions
 */
function createFilamentPredictionUser(array $permissions = []): User
{
    $role = Role::query()->create([
        'name' => 'Prediction panel role '.Str::random(8),
        'guard_name' => 'web',
    ]);

    $role->givePermissionTo([
        PermissionEnums::ADMIN_PANEL->value,
        ...array_map(fn (PermissionEnums $permission): string => $permission->value, $permissions),
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$role]);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

/**
 * A prediction membrane whose MolMeDB membrane has a COSMO definition file,
 * so it may be chosen for a new prediction dataset.
 */
function createCosmoPredictionMembrane(bool $withCosmoFile = true): PredictionMembrane
{
    $membrane = createApiMembrane(['abbreviation' => 'POPC'.Str::random(4)]);

    if ($withCosmoFile) {
        $file = File::query()->create([
            'path' => 'membranes/'.$membrane->id.'.mic',
            'name' => $membrane->id.'.mic',
            'type' => File::TYPE_COSMO_MEMBRANE,
            'storage' => 'local',
            'mime' => 'text/plain',
            'hash' => md5('membrane-'.$membrane->id),
        ]);

        $membrane->files()->attach($file->id, ['model_type' => Membrane::class]);
    }

    return PredictionMembrane::query()->create([
        'remote_id' => $membrane->id,
        'name' => $membrane->name,
        'abbreviation' => $membrane->abbreviation,
    ]);
}

/**
 * A remote job snapshot as the prediction service returns it.
 *
 * @param  array<string, mixed>  $snapshot
 * @return array<string, mixed>
 */
function filamentRemoteJobSnapshot(array $snapshot = []): array
{
    return [
        'id' => 'mol-1',
        'canonical_smiles' => 'CCO',
        'status' => 'running',
        'steps' => [],
        'conformers' => [],
        'calculations' => [],
        'events' => [],
        ...$snapshot,
    ];
}
