<?php

require_once __DIR__.'/upload_pipeline_helpers.php';

use App\Enums\PermissionEnums;
use App\Enums\UploadQueueLogContextEnums;
use App\Jobs\ProcessUploadQueueRecord;
use App\Jobs\SendUploadQueueStatusUpdate;
use App\Models\Identifier;
use App\Models\InteractionPassive;
use App\Models\Permission;
use App\Models\UploadQueue;
use App\Models\User;
use App\ValueObjects\UploadQueueConfig;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/*
 * The upload queue record itself: its state predicates, the admin review
 * decisions, deleting the uploaded file and the legacy start, cancel and
 * revert actions of the administration.
 */

beforeEach(function () {
    fakePublicStorageForTests();
});

function uploadInState(int $state, array $config = [], array $attributes = []): UploadQueue
{
    return createConfiguredUpload("SMILES,Gpen\nCCO,-1\n", ['smiles', 'g_pen'], config: $config, attributes: ['state' => $state, ...$attributes]);
}

/**
 * The payload of the record's last log entry as it is stored in the database.
 *
 * @return array<string, mixed>|null
 */
function storedLastLogPayload(UploadQueue $record): ?array
{
    $logs = json_decode((string) $record->getRawOriginal('logs'), true);

    return end($logs)['payload'] ?? null;
}

/**
 * The message the exception reports for the given key.
 */
function validationMessage(ValidationException $exception, string $key = 'record'): ?string
{
    return $exception->errors()[$key][0] ?? null;
}

test('types, states and type folders translate to their labels', function () {
    expect(UploadQueue::enumType())->toHaveKeys([UploadQueue::TYPE_PASSIVE_DATASET, UploadQueue::TYPE_ACTIVE_DATASET])
        ->and(UploadQueue::enumType(UploadQueue::TYPE_ACTIVE_DATASET))->toBe('Active interactions')
        ->and(UploadQueue::enumType(999))->toBeNull()
        ->and(UploadQueue::enumState())->toBe(UploadQueue::$enum_states)
        ->and(UploadQueue::enumState(UploadQueue::STATE_REVIEW_REQUIRED))->toBe('Review required')
        ->and(UploadQueue::enumState(999))->toBeNull()
        ->and(UploadQueue::typeFolder(UploadQueue::TYPE_PASSIVE_DATASET))->toBe('upload_queue/passive')
        ->and(UploadQueue::typeFolder(UploadQueue::TYPE_ACTIVE_DATASET))->toBe('upload_queue/active')
        ->and(UploadQueue::typeFolder(999))->toBeNull();
});

test('each state allows only its own actions', function (int $state, array $allowed) {
    $record = new UploadQueue(['state' => $state]);

    $actual = [
        'revertible' => $record->isRevertible(),
        'deletable' => $record->isDeletable(),
        'finished' => $record->isFinished(),
        'cancelable' => $record->isCancelable(),
        'editable config' => $record->isEditableConfig(),
        'reuploadable' => $record->canBeReuploaded(),
        'configurable' => $record->canBeConfigured(),
        'revertible to config' => $record->canBeRevertedToConfigState(),
        'cancelable by user' => $record->canBeCanceled(),
        'reviewable by admin' => $record->canBeReviewedByAdmin(),
    ];

    expect(array_keys(array_filter($actual)))->toBe($allowed);
})->with([
    'uploaded' => [UploadQueue::STATE_UPLOADED, ['deletable', 'editable config', 'configurable', 'cancelable by user']],
    'configured' => [UploadQueue::STATE_CONFIGURED, ['deletable', 'editable config', 'configurable', 'cancelable by user', 'reviewable by admin']],
    'review required' => [UploadQueue::STATE_REVIEW_REQUIRED, ['deletable', 'cancelable', 'revertible to config', 'reviewable by admin']],
    'pending' => [UploadQueue::STATE_PENDING, ['cancelable', 'revertible to config', 'cancelable by user', 'reviewable by admin']],
    'running' => [UploadQueue::STATE_RUNNING, ['cancelable', 'reviewable by admin']],
    'done' => [UploadQueue::STATE_DONE, ['revertible', 'finished', 'reviewable by admin']],
    'error' => [UploadQueue::STATE_ERROR, ['reuploadable', 'configurable', 'cancelable by user', 'reviewable by admin']],
    'canceled' => [UploadQueue::STATE_CANCELED, ['reviewable by admin']],
]);

test('only a configured record with a valid configuration and passed quick validation can be enqueued', function () {
    $ready = new UploadQueue(['state' => UploadQueue::STATE_CONFIGURED, 'config' => [
        UploadQueueConfig::SEPARATOR => ',',
        UploadQueueConfig::SKIP_FIRST_ROW => 1,
        UploadQueueConfig::ATTRIBUTES => ['smiles', 'g_pen'],
        UploadQueueConfig::QUICK_VALIDATION_OK => true,
    ]]);
    $withoutQuickValidation = new UploadQueue(['state' => UploadQueue::STATE_CONFIGURED, 'config' => [
        UploadQueueConfig::SEPARATOR => ',',
        UploadQueueConfig::SKIP_FIRST_ROW => 1,
        UploadQueueConfig::ATTRIBUTES => ['smiles', 'g_pen'],
    ]]);
    $withoutColumns = new UploadQueue(['state' => UploadQueue::STATE_CONFIGURED, 'config' => [
        UploadQueueConfig::SEPARATOR => ',',
        UploadQueueConfig::SKIP_FIRST_ROW => 1,
        UploadQueueConfig::ATTRIBUTES => [null, null],
        UploadQueueConfig::QUICK_VALIDATION_OK => true,
    ]]);
    $pending = new UploadQueue(['state' => UploadQueue::STATE_PENDING, 'config' => $ready->config->toArray()]);

    expect($ready->canBeEnqueued())->toBeTrue()
        ->and($ready->isReadyToStart())->toBeTrue()
        ->and($withoutQuickValidation->canBeEnqueued())->toBeFalse()
        ->and($withoutColumns->hasValidConfig())->toBeFalse()
        ->and($withoutColumns->canBeEnqueued())->toBeFalse()
        ->and($pending->canBeEnqueued())->toBeFalse();
});

test('new records can be added only while RDKit is reachable', function (int $status, bool $expected) {
    config()->set('services.rdkit.url', 'https://rdkit.test');
    resetApiRouteRdkitState();
    Http::fake(['https://rdkit.test/test' => Http::response([], $status)]);

    expect(UploadQueue::canBeAddedNewRecords())->toBe($expected);

    resetApiRouteRdkitState();
})->with([
    'reachable' => [200, true],
    'unreachable' => [503, false],
]);

test('only a passed detailed validation waiting for review asks the administrators to decide', function () {
    $passed = [UploadQueueConfig::DETAILED_VALIDATION_OK => true];

    expect((new UploadQueue(['state' => UploadQueue::STATE_REVIEW_REQUIRED, 'config' => $passed]))->shouldBeDecidedByAdmin())->toBeTrue()
        ->and((new UploadQueue(['state' => UploadQueue::STATE_REVIEW_REQUIRED, 'config' => []]))->shouldBeDecidedByAdmin())->toBeFalse()
        ->and((new UploadQueue(['state' => UploadQueue::STATE_PENDING, 'config' => $passed]))->shouldBeDecidedByAdmin())->toBeFalse();
});

test('approving the review returns the record to the processing queue', function () {
    Queue::fake();
    $admin = User::factory()->create();
    $record = uploadInState(UploadQueue::STATE_REVIEW_REQUIRED, [
        UploadQueueConfig::DETAILED_VALIDATION_OK => true,
        UploadQueueConfig::ADMIN_REVIEW_REJECTED_AT => '2026-01-01T00:00:00Z',
        UploadQueueConfig::ADMIN_REVIEW_REJECTED_REASON => 'Older rejection.',
    ]);

    $record->approveAdminReview($admin->id);
    $record->refresh();
    $log = $record->logs->last();

    expect($record->state)->toBe(UploadQueue::STATE_PENDING)
        ->and($record->config->adminReviewApproved())->toBeTrue()
        ->and($record->config->adminReviewApprovedAt())->not->toBeNull()
        ->and($record->config->adminReviewRejectedAt())->toBeNull()
        ->and($record->config->adminReviewRejectedReason())->toBeNull()
        ->and($log->context)->toBe(UploadQueueLogContextEnums::SUCCESS)
        ->and($log->state)->toBe(UploadQueue::STATE_PENDING)
        ->and($log->user_id)->toBe((string) $admin->id)
        ->and(storedLastLogPayload($record))->toBe(['admin_review_approved' => true]);

    Queue::assertPushed(ProcessUploadQueueRecord::class);
    Queue::assertPushed(SendUploadQueueStatusUpdate::class);
});

test('rejecting the review fails the record with the reason', function () {
    Queue::fake();
    $admin = User::factory()->create();
    $record = uploadInState(UploadQueue::STATE_REVIEW_REQUIRED, [UploadQueueConfig::DETAILED_VALIDATION_OK => true]);

    $record->rejectAdminReview('Values are in the wrong unit.', $admin->id);
    $record->refresh();
    $log = $record->logs->last();

    expect($record->state)->toBe(UploadQueue::STATE_ERROR)
        ->and($record->config->adminReviewApproved())->toBeFalse()
        ->and($record->config->adminReviewRejectedReason())->toBe('Values are in the wrong unit.')
        ->and($record->config->adminReviewRejectedAt())->not->toBeNull()
        ->and($log->context)->toBe(UploadQueueLogContextEnums::ERROR)
        ->and($log->message)->toContain('Reason: Values are in the wrong unit.')
        ->and(storedLastLogPayload($record))->toBe(['reason' => 'Values are in the wrong unit.']);

    Queue::assertPushed(ProcessUploadQueueRecord::class, 0);
});

test('a record that was never configured cannot be approved or rejected', function () {
    Queue::fake();
    $record = uploadInState(UploadQueue::STATE_UPLOADED);
    $logCount = $record->logs->count();

    $record->approveAdminReview();
    $record->rejectAdminReview('No.');
    $record->refresh();

    expect($record->state)->toBe(UploadQueue::STATE_UPLOADED)
        ->and($record->config->adminReviewApproved())->toBeFalse()
        ->and($record->config->adminReviewRejectedReason())->toBeNull()
        ->and($record->logs)->toHaveCount($logCount);

    Queue::assertNothingPushed();
});

test('deleting the upload removes the stored file and cancels the record', function () {
    Queue::fake();
    $record = uploadInState(UploadQueue::STATE_CONFIGURED);
    $file = $record->file;

    $result = $record->deleteUploadedFileAndCancel($record->user);
    $record->refresh();

    expect($result)->toBe(['file_id' => $file->id, 'file_name' => $file->name, 'file_deleted' => true])
        ->and(Storage::disk('public')->exists($file->path))->toBeFalse()
        ->and($record->state)->toBe(UploadQueue::STATE_CANCELED)
        ->and($record->config[UploadQueueConfig::UPLOADED_FILE_DELETED])->toBeTrue()
        ->and($record->config[UploadQueueConfig::UPLOADED_FILE_DELETED_AT])->not->toBeNull()
        ->and($record->logs->last()->context)->toBe(UploadQueueLogContextEnums::WARNING)
        ->and(storedLastLogPayload($record))->toBe($result)
        ->and($record->logs->last()->user_id)->toBe((string) $record->user_id);
});

test('deleting an upload whose file is already gone still cancels it', function () {
    Queue::fake();
    $record = uploadInState(UploadQueue::STATE_ERROR);
    Storage::disk('public')->delete($record->file->path);

    $result = $record->deleteUploadedFileAndCancel($record->user);

    expect($result['file_deleted'])->toBeTrue()
        ->and($record->refresh()->state)->toBe(UploadQueue::STATE_CANCELED);
});

test('a guest deletes the upload with its own token only', function () {
    Queue::fake();
    $record = uploadInState(UploadQueue::STATE_CONFIGURED, attributes: ['user_id' => null, 'guest_token' => 'guest-secret']);

    expect(fn () => $record->deleteUploadedFileAndCancel(null, 'other-token'))->toThrow(AuthorizationException::class)
        ->and(fn () => $record->deleteUploadedFileAndCancel(null))->toThrow(AuthorizationException::class);

    $record->deleteUploadedFileAndCancel(null, 'guest-secret');

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_CANCELED)
        ->and($record->logs->last()->user_id)->toBeNull();
});

test('another user cannot delete the upload unless allowed to manage all uploads', function () {
    Queue::fake();
    $record = uploadInState(UploadQueue::STATE_CONFIGURED);
    $stranger = User::factory()->create();

    expect(fn () => $record->deleteUploadedFileAndCancel($stranger))->toThrow(AuthorizationException::class)
        ->and($record->refresh()->state)->toBe(UploadQueue::STATE_CONFIGURED)
        ->and(Storage::disk('public')->exists($record->file->path))->toBeTrue();

    $permission = Permission::query()->firstOrCreate(
        ['name' => PermissionEnums::UPLOAD_QUEUE_MANAGE_ALL->value, 'guard_name' => 'web'],
        ['description' => 'Manage all uploads'],
    );
    $stranger->givePermissionTo($permission);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $record->deleteUploadedFileAndCancel($stranger->refresh());

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_CANCELED);
});

test('the owner is told why an upload in a later state cannot be deleted', function (int $state, string $message) {
    Queue::fake();
    $record = uploadInState($state);

    try {
        $record->deleteUploadedFileAndCancel($record->user);
        $this->fail('The upload was deleted.');
    } catch (ValidationException $exception) {
        expect(validationMessage($exception))->toBe($message);
    }

    expect($record->refresh()->state)->toBe($state)
        ->and(Storage::disk('public')->exists($record->file->path))->toBeTrue();
})->with([
    'canceled' => [UploadQueue::STATE_CANCELED, 'Record is already marked for deletion.'],
    'running' => [UploadQueue::STATE_RUNNING, 'Running records cannot be canceled right now.'],
    'done' => [UploadQueue::STATE_DONE, 'This record cannot be canceled in current state.'],
    'review required' => [UploadQueue::STATE_REVIEW_REQUIRED, 'This record cannot be canceled in current state.'],
]);

test('an upload whose file has no storage cannot be deleted', function () {
    Queue::fake();
    $record = uploadInState(UploadQueue::STATE_CONFIGURED);
    $record->file->update(['storage' => ' ']);

    try {
        $record->refresh()->deleteUploadedFileAndCancel($record->user);
        $this->fail('The upload was deleted.');
    } catch (ValidationException $exception) {
        expect(validationMessage($exception))->toBe('Uploaded file storage is not configured.');
    }

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_CONFIGURED);
});

test('an upload stays as it was when its file cannot be deleted', function () {
    Queue::fake();
    $record = uploadInState(UploadQueue::STATE_CONFIGURED);
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->with($record->file->path)->andReturnTrue();
    $disk->shouldReceive('delete')->with($record->file->path)->andReturnFalse();
    Storage::shouldReceive('disk')->with('public')->andReturn($disk);

    try {
        $record->deleteUploadedFileAndCancel($record->user);
        $this->fail('The upload was deleted.');
    } catch (ValidationException $exception) {
        expect(validationMessage($exception))->toBe('Uploaded file could not be deleted. Please try again.');
    }

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_CONFIGURED)
        ->and($record->config[UploadQueueConfig::UPLOADED_FILE_DELETED] ?? null)->toBeNull();
});

test('starting a configured record enqueues it', function () {
    Queue::fake();
    $record = uploadInState(UploadQueue::STATE_CONFIGURED);

    $record->start();

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_PENDING);
    Notification::assertNotified('Upload job added to queue');
    Queue::assertPushed(ProcessUploadQueueRecord::class);
});

test('starting a record without a valid configuration sends it back to configuration', function () {
    Queue::fake();
    $record = uploadInState(UploadQueue::STATE_CONFIGURED, [UploadQueueConfig::ATTRIBUTES => [null, null]]);

    $record->start();

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_UPLOADED);
    Notification::assertNotified('Upload job cannot be started');
    Queue::assertNothingPushed();
});

test('canceling a record that is not in progress is refused and logged', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $record = uploadInState(UploadQueue::STATE_DONE);

    $record->cancel();
    $record->refresh();
    $log = $record->logs->last();

    expect($record->state)->toBe(UploadQueue::STATE_DONE)
        ->and($log->message)->toBe('Could not be canceled. State: [Done]')
        ->and($log->context)->toBe(UploadQueueLogContextEnums::ERROR)
        ->and($log->user_id)->toBe((string) $user->id)
        ->and($log->timestamp)->not->toBeNull();
    Notification::assertNotified('Upload job cannot be canceled');
});

test('canceling a record in progress is not implemented and keeps its state', function () {
    $this->actingAs(User::factory()->create());
    $record = uploadInState(UploadQueue::STATE_RUNNING);

    $record->cancel();

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_RUNNING);
    Notification::assertNotified('Not implemented');
});

test('reverting a record that is not finished is refused and logged', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $record = uploadInState(UploadQueue::STATE_PENDING);

    $record->revert();
    $record->refresh();

    expect($record->state)->toBe(UploadQueue::STATE_PENDING)
        ->and($record->logs->last()->message)->toBe('Could not be reverted. State: [Pending]')
        ->and($record->logs->last()->user_id)->toBe((string) $user->id);
    Notification::assertNotified('Upload job is not revertible');
});

test('reverting a finished record removes the interactions and identifiers of its dataset', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    $record = uploadInState(UploadQueue::STATE_DONE);
    $structure = createApiStructure();
    createApiPassiveInteraction(['dataset' => $record->dataset, 'structure' => $structure]);
    $identifier = $record->dataset->identifiers()->create([
        'structure_id' => $structure->id,
        'type' => Identifier::TYPE_NAME,
        'value' => 'ethanol',
        'state' => Identifier::STATE_ACTIVE,
    ]);

    $record->revert();

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_CONFIGURED)
        ->and(InteractionPassive::withTrashed()->where('dataset_id', $record->dataset_id)->count())->toBe(0)
        ->and(Identifier::withTrashed()->whereKey($identifier->id)->exists())->toBeFalse();
    Notification::assertNotified('Upload job reverted.');
});
