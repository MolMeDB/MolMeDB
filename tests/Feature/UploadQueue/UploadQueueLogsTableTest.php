<?php

require_once __DIR__.'/upload_pipeline_helpers.php';

use App\Enums\PermissionEnums;
use App\Enums\UploadQueueLogContextEnums;
use App\Enums\UploadQueueLogTypeEnums;
use App\Filament\Resources\UploadQueues\Pages\EditUploadQueue;
use App\Livewire\UploadQueueLogsTable;
use App\Models\Permission;
use App\Models\Role;
use App\Models\UploadQueue;
use App\Models\User;
use App\ValueObjects\UploadQueueLog;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    fakePublicStorageForTests();
    // A root of this test process only: the shared fake root is emptied
    // whenever another test process fakes the public disk.
    config()->set('filesystems.disks.public.root', storage_path('framework/testing/disks/public_'.getmypid()));
    Storage::forgetDisk('public');
});

afterEach(function () {
    (new Filesystem)->deleteDirectory(config('filesystems.disks.public.root'));
});

/**
 * An upload with three logs written by its owner, created in the order
 * "Upload created", "Validation failed", "File reuploaded".
 */
function createUploadWithLogs(): UploadQueue
{
    $record = createConfiguredUpload("SMILES,Gpen\nCCO,-1\n", ['smiles', 'g_pen']);
    $ownerId = (string) $record->user_id;

    $record->logs = [
        new UploadQueueLog('Validation failed', UploadQueueLogContextEnums::ERROR, '2026-03-02T10:00:00.000000Z', $ownerId, UploadQueueLogTypeEnums::VALIDATION_RUN, UploadQueue::STATE_ERROR),
        new UploadQueueLog('Upload created', UploadQueueLogContextEnums::INFO, '2026-03-01T10:00:00.000000Z', $ownerId, UploadQueueLogTypeEnums::STATE_CHANGE, UploadQueue::STATE_UPLOADED),
        new UploadQueueLog('File reuploaded', UploadQueueLogContextEnums::SUCCESS, '2026-03-03T10:00:00.000000Z', null, UploadQueueLogTypeEnums::STATE_CHANGE, UploadQueue::STATE_UPLOADED),
    ];
    $record->save();

    return $record->refresh();
}

test('the logs of an upload are listed newest first with their state and author', function () {
    $record = createUploadWithLogs();

    Livewire::test(UploadQueueLogsTable::class, ['record' => $record])
        ->assertOk()
        ->assertSee('Logs describing the history of the upload process.')
        ->assertSeeInOrder(['File reuploaded', 'Validation failed', 'Upload created'])
        ->assertSee(UploadQueue::enumState(UploadQueue::STATE_ERROR))
        ->assertSee($record->user->name);
});

test('the logs can be sorted by date in both directions', function () {
    $record = createUploadWithLogs();

    Livewire::test(UploadQueueLogsTable::class, ['record' => $record])
        ->call('sortTable', 'timestamp', 'asc')
        ->assertSeeInOrder(['Upload created', 'Validation failed', 'File reuploaded'])
        ->call('sortTable', 'timestamp', 'desc')
        ->assertSeeInOrder(['File reuploaded', 'Validation failed', 'Upload created']);
});

test('the logs can be sorted by severity', function () {
    $record = createUploadWithLogs();

    Livewire::test(UploadQueueLogsTable::class, ['record' => $record])
        ->call('sortTable', 'context', 'asc')
        ->assertSeeInOrder(['Validation failed', 'Upload created', 'File reuploaded'])
        ->call('sortTable', 'context', 'desc')
        ->assertSeeInOrder(['File reuploaded', 'Upload created', 'Validation failed']);
});

test('log messages keep their line breaks but never render their markup', function () {
    $record = createConfiguredUpload("SMILES,Gpen\nCCO,-1\n", ['smiles', 'g_pen']);
    $record->addStructuredLog("Line 2 failed\n<script>alert(1)</script>", UploadQueueLogContextEnums::ERROR);

    Livewire::test(UploadQueueLogsTable::class, ['record' => $record->refresh()])
        ->assertSeeHtml('Line 2 failed<br />')
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->assertDontSeeHtml('<script>alert(1)</script>');
});

test('an upload without logs shows the empty state', function () {
    $record = createConfiguredUpload("SMILES,Gpen\nCCO,-1\n", ['smiles', 'g_pen']);

    Livewire::test(UploadQueueLogsTable::class, ['record' => $record])
        ->assertSee('No logs found.');
});

test('the detail of an upload shows its logs to its owner', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    foreach (PermissionEnums::cases() as $permission) {
        Permission::query()->firstOrCreate(
            ['name' => $permission->value, 'guard_name' => 'web'],
            ['description' => $permission->description()],
        );
    }
    $role = Role::query()->create(['name' => 'Logs uploader', 'guard_name' => 'web']);
    $role->givePermissionTo([PermissionEnums::ADMIN_PANEL->value, PermissionEnums::UPLOAD_QUEUE_MANAGE_OWN->value]);

    $record = createUploadWithLogs();
    $owner = User::query()->findOrFail($record->user_id);
    $owner->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($owner->refresh());
    Livewire::test(EditUploadQueue::class, ['record' => $record->getRouteKey()])
        ->assertOk()
        ->assertSeeLivewire(UploadQueueLogsTable::class);
});
