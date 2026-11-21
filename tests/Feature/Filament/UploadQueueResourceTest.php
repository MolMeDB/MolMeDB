<?php

require_once __DIR__.'/../Api/api_test_helpers.php';

use App\Enums\PermissionEnums;
use App\Enums\RoleEnums;
use App\Filament\Resources\UploadQueues\Pages\EditUploadQueue;
use App\Filament\Resources\UploadQueues\Pages\ListUploadQueues;
use App\Filament\Resources\UploadQueues\UploadQueueResource;
use App\Models\Dataset;
use App\Models\File;
use App\Models\Permission;
use App\Models\Role;
use App\Models\UploadQueue;
use App\Models\User;
use App\ValueObjects\UploadQueueConfig;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Queue::fake();
    fakePublicStorageForTests();

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
});

/**
 * @param  array<int, PermissionEnums>  $permissions
 */
function createUploadQueueUser(array $permissions): User
{
    $role = Role::query()->create([
        'name' => 'Uploader '.fake()->unique()->numberBetween(1, 1_000_000),
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

function createUploadQueueRecord(User $owner, string $fileName, array $attributes = []): UploadQueue
{
    $file = File::query()->create([
        'path' => 'upload_queue/passive/'.$fileName,
        'name' => $fileName,
        'type' => File::TYPE_UPLOAD_PASSIVE,
        'storage' => 'public',
        'mime' => 'text/csv',
        'hash' => md5($fileName),
    ]);

    return UploadQueue::query()->create([
        'type' => Dataset::TYPE_PASSIVE,
        'state' => UploadQueue::STATE_PENDING,
        'file_id' => $file->id,
        'dataset_id' => createApiDataset(['owner' => $owner, 'name' => 'Dataset of '.$fileName])->id,
        'user_id' => $owner->id,
        'config' => [],
        ...$attributes,
    ]);
}

/**
 * An upload of the given owner whose data passed detailed validation and
 * waits for an administrator's decision.
 */
function createUploadQueueAwaitingReview(User $owner): UploadQueue
{
    Storage::disk('public')->put('upload_queue/passive/review.csv', "smiles,g_pen\nCCO,1.2\n");

    return createUploadQueueRecord($owner, 'review.csv', [
        'state' => UploadQueue::STATE_REVIEW_REQUIRED,
        'config' => [
            UploadQueueConfig::SEPARATOR => ',',
            UploadQueueConfig::SKIP_FIRST_ROW => 1,
            UploadQueueConfig::ATTRIBUTES => ['smiles', 'g_pen'],
            UploadQueueConfig::QUICK_VALIDATION_OK => true,
            UploadQueueConfig::DETAILED_VALIDATION_OK => true,
        ],
    ]);
}

test('an uploader with manage own permission lists only their own uploads', function () {
    $uploader = createUploadQueueUser([PermissionEnums::UPLOAD_QUEUE_MANAGE_OWN]);
    $own = createUploadQueueRecord($uploader, 'own.csv');
    $foreign = createUploadQueueRecord(User::factory()->create(), 'foreign.csv');

    $this->actingAs($uploader);

    Livewire::test(ListUploadQueues::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign]);
});

test('an uploader with manage all permission lists every upload', function () {
    $manager = createUploadQueueUser([PermissionEnums::UPLOAD_QUEUE_MANAGE_ALL]);
    $first = createUploadQueueRecord(User::factory()->create(), 'first.csv');
    $second = createUploadQueueRecord(User::factory()->create(), 'second.csv');

    $this->actingAs($manager);

    Livewire::test(ListUploadQueues::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$first, $second]);
});

test('an uploader with manage own permission may update their own upload but not a foreign one', function () {
    $uploader = createUploadQueueUser([PermissionEnums::UPLOAD_QUEUE_MANAGE_OWN]);
    $own = createUploadQueueRecord($uploader, 'own.csv');
    $foreign = createUploadQueueRecord(User::factory()->create(), 'foreign.csv');

    $this->actingAs($uploader);

    expect(Gate::allows('update', $own))->toBeTrue()
        ->and(Gate::allows('update', $foreign))->toBeFalse()
        ->and(Gate::allows('view', $foreign))->toBeFalse();
});

test('an uploader with manage own permission cannot open the detail of a foreign upload', function () {
    $uploader = createUploadQueueUser([PermissionEnums::UPLOAD_QUEUE_MANAGE_OWN]);
    $foreign = createUploadQueueRecord(User::factory()->create(), 'foreign.csv');

    $this->actingAs($uploader);

    $this->get(EditUploadQueue::getUrl(['record' => $foreign]))->assertNotFound();
});

test('an uploader with manage own permission can open the detail of their own upload', function () {
    $uploader = createUploadQueueUser([PermissionEnums::UPLOAD_QUEUE_MANAGE_OWN]);
    $own = createUploadQueueRecord($uploader, 'own.csv');

    $this->actingAs($uploader);

    Livewire::test(EditUploadQueue::class, ['record' => $own->getRouteKey()])->assertOk();
});

test('upload queues are never deleted in bulk', function () {
    $this->actingAs(createUploadQueueUser([PermissionEnums::UPLOAD_QUEUE_MANAGE_ALL]));

    expect(Gate::allows('deleteAny', UploadQueue::class))->toBeFalse();
});

test('an uploader cannot approve or reject the review of their own upload', function () {
    $uploader = createUploadQueueUser([PermissionEnums::UPLOAD_QUEUE_MANAGE_OWN]);
    $record = createUploadQueueAwaitingReview($uploader);

    $this->actingAs($uploader);

    expect(UploadQueueResource::approveReviewAction()->record($record)->isAuthorized())->toBeFalse()
        ->and(UploadQueueResource::rejectReviewAction()->record($record)->isAuthorized())->toBeFalse();

    Livewire::test(EditUploadQueue::class, ['record' => $record->getRouteKey()])
        ->mountAction('review_data')
        ->assertActionMounted('review_data')
        ->assertDontSee('Approve');

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_REVIEW_REQUIRED);
});

test('upload queue managers approve the review of an upload', function () {
    $manager = createUploadQueueUser([PermissionEnums::UPLOAD_QUEUE_MANAGE_ALL]);
    $record = createUploadQueueAwaitingReview(User::factory()->create());

    $this->actingAs($manager);

    Livewire::test(EditUploadQueue::class, ['record' => $record->getRouteKey()])
        ->callAction([TestAction::make('review_data'), TestAction::make('approve_review')]);

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_PENDING);
});

test('an uploader cannot move their upload to a dataset they may not edit', function () {
    $uploader = createUploadQueueUser([PermissionEnums::UPLOAD_QUEUE_MANAGE_OWN, PermissionEnums::DATASET_EDIT_OWN]);
    $record = createUploadQueueRecord($uploader, 'own.csv', ['state' => UploadQueue::STATE_UPLOADED]);
    $ownDatasetId = $record->dataset_id;
    $foreignDataset = createApiDataset(['owner' => User::factory()->create(), 'name' => 'Foreign dataset']);

    $this->actingAs($uploader);

    Livewire::test(EditUploadQueue::class, ['record' => $record->getRouteKey()])
        ->fillForm(['dataset_id' => $foreignDataset->id])
        ->call('save')
        ->assertHasFormErrors(['dataset_id']);

    expect($record->refresh()->dataset_id)->toBe($ownDatasetId);
});

test('the dataset choice of an upload offers only datasets the user may edit', function () {
    $uploader = createUploadQueueUser([PermissionEnums::UPLOAD_QUEUE_MANAGE_OWN, PermissionEnums::DATASET_EDIT_OWN]);
    $own = createApiDataset(['owner' => $uploader, 'name' => 'Own dataset']);
    $foreign = createApiDataset(['owner' => User::factory()->create(), 'name' => 'Foreign dataset']);

    $this->actingAs($uploader);

    $offered = UploadQueueResource::scopeDatasetOptions(Dataset::query())->pluck('id')->all();

    expect($offered)->toContain($own->id)->not->toContain($foreign->id);
});

test('an uploader may revert their upload only when they may edit its dataset', function () {
    $uploader = createUploadQueueUser([PermissionEnums::UPLOAD_QUEUE_MANAGE_OWN, PermissionEnums::DATASET_EDIT_OWN]);
    $intoOwnDataset = createUploadQueueRecord($uploader, 'own.csv', ['state' => UploadQueue::STATE_DONE]);
    $intoForeignDataset = createUploadQueueRecord($uploader, 'foreign.csv', [
        'state' => UploadQueue::STATE_DONE,
        'dataset_id' => createApiDataset(['owner' => User::factory()->create(), 'name' => 'Foreign dataset'])->id,
    ]);

    $this->actingAs($uploader);

    expect(Gate::allows('revert', $intoOwnDataset))->toBeTrue()
        ->and(Gate::allows('revert', $intoForeignDataset))->toBeFalse();

    Livewire::test(EditUploadQueue::class, ['record' => $intoForeignDataset->getRouteKey()])
        ->assertActionHidden('revert');
});
