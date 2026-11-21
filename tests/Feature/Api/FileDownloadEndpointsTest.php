<?php

require_once __DIR__.'/api_test_helpers.php';

use App\Enums\PermissionEnums;
use App\Enums\RoleEnums;
use App\Models\Dataset;
use App\Models\File;
use App\Models\Permission;
use App\Models\Role;
use App\Models\UploadQueue;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    fakePublicStorageForTests();

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

function createDownloadableFile(int $type, string $content, string $name = 'file.csv'): File
{
    $path = 'downloads/'.uniqid('file_', true).'.csv';
    Storage::disk('public')->put($path, $content);

    return File::query()->create([
        'path' => $path,
        'name' => $name,
        'type' => $type,
        'storage' => 'public',
        'mime' => 'text/csv',
        'hash' => md5($content),
    ]);
}

function createUploadQueueWithFile(?User $owner, string $content, array $attributes = []): UploadQueue
{
    $file = createDownloadableFile(File::TYPE_UPLOAD_PASSIVE, $content, 'upload.csv');

    return UploadQueue::query()->create([
        'type' => Dataset::TYPE_PASSIVE,
        'state' => UploadQueue::STATE_PENDING,
        'file_id' => $file->id,
        'dataset_id' => createApiDataset(['owner' => $owner ?? User::factory()->create()])->id,
        'user_id' => $owner?->id,
        'config' => [],
        ...$attributes,
    ]);
}

test('a public export file is downloaded by its hash without signing in', function () {
    $file = createDownloadableFile(File::TYPE_EXPORT_INTERACTIONS_MEMBRANE, "name;logp\ncaffeine;-0.07\n", 'export.csv');

    $response = $this->get(route('public.download', ['hash' => $file->hash]));

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertDownload('export.csv');

    expect($response->streamedContent())->toBe("name;logp\ncaffeine;-0.07\n");
});

test('an unknown hash gives not found', function () {
    $this->get(route('public.download', ['hash' => md5('missing')]))->assertNotFound();
});

test('a file whose stored copy is gone gives not found', function () {
    $file = createDownloadableFile(File::TYPE_EXPORT_INTERACTIONS_METHOD, 'gone');
    Storage::disk('public')->delete($file->path);

    $this->get(route('public.download', ['hash' => $file->hash]))->assertNotFound();
});

test('files uploaded to the upload queue are not downloadable by their hash', function (int $type) {
    $file = createDownloadableFile($type, 'unpublished contributor data '.$type);

    $this->get(route('public.download', ['hash' => $file->hash]))->assertNotFound();
})->with([
    'passive upload' => File::TYPE_UPLOAD_PASSIVE,
    'active upload' => File::TYPE_UPLOAD_ACTIVE,
    'energy upload' => File::TYPE_UPLOAD_ENERGY,
]);

test('unknown prediction file hashes give not found', function (string $routeName) {
    $this->get(route($routeName, ['hash' => md5('missing')]))->assertNotFound();
})->with([
    'prediction file' => 'public.download-prediction',
    'prediction result' => 'predictionResult.download',
]);

test('the uploader downloads the raw file of their upload', function () {
    $owner = User::factory()->create();
    $record = createUploadQueueWithFile($owner, "smiles;logp\nCCO;-0.31\n");

    $this->actingAs($owner)
        ->get(route('export.upload-queue.raw', ['record' => $record]))
        ->assertOk()
        ->assertDownload();
});

test('another signed-in user cannot download the raw file of an upload', function () {
    $record = createUploadQueueWithFile(User::factory()->create(), 'private');

    $this->actingAs(User::factory()->create())
        ->get(route('export.upload-queue.raw', ['record' => $record]))
        ->assertUnauthorized();
});

test('upload queue managers download the raw file of any upload', function () {
    $role = Role::query()->create(['name' => 'Upload managers', 'guard_name' => 'web']);
    $role->givePermissionTo(PermissionEnums::UPLOAD_QUEUE_MANAGE_ALL->value);
    $manager = User::factory()->create();
    $manager->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $record = createUploadQueueWithFile(User::factory()->create(), 'any');

    $this->actingAs($manager->refresh())
        ->get(route('export.upload-queue.raw', ['record' => $record]))
        ->assertOk();
});

test('a guest downloads the raw file of a guest upload only with its token', function () {
    $record = createUploadQueueWithFile(null, 'guest upload', ['guest_token' => 'guest-secret-token']);

    $this->get(route('export.upload-queue.raw', ['record' => $record, 'guest_token' => 'guest-secret-token']))
        ->assertOk();

    $this->get(route('export.upload-queue.raw', ['record' => $record, 'guest_token' => 'wrong-token']))
        ->assertUnauthorized();

    $this->get(route('export.upload-queue.raw', ['record' => $record]))
        ->assertUnauthorized();
});

test('a stranger cannot tell whether the raw file of an upload still exists', function () {
    $record = createUploadQueueWithFile(User::factory()->create(), 'deleted later');
    Storage::disk('public')->delete($record->file->path);

    $this->get(route('export.upload-queue.raw', ['record' => $record]))->assertUnauthorized();
});

test('the uploader gets not found when the raw file is gone', function () {
    $owner = User::factory()->create();
    $record = createUploadQueueWithFile($owner, 'deleted later');
    Storage::disk('public')->delete($record->file->path);

    $this->actingAs($owner)
        ->get(route('export.upload-queue.raw', ['record' => $record]))
        ->assertNotFound();
});

test('the parsed upload queue export requires signing in and is not implemented yet', function () {
    $owner = User::factory()->create();
    $record = createUploadQueueWithFile($owner, 'parsed');

    $this->getJson(route('export.upload-queue', ['record' => $record]))->assertUnauthorized();

    $this->actingAs($owner)
        ->get(route('export.upload-queue', ['record' => $record]))
        ->assertNotFound();
});
