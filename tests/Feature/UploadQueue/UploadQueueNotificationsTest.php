<?php

require_once __DIR__.'/upload_pipeline_helpers.php';

use App\Console\Commands\SendUploadQueueAdminDigest;
use App\Console\Commands\SendUploadQueueNotifications;
use App\Enums\NotificationType;
use App\Enums\PermissionEnums;
use App\Enums\UploadQueueLogContextEnums;
use App\Enums\UploadQueueLogTypeEnums;
use App\Jobs\ProcessUploadQueueRecord;
use App\Jobs\SendUploadQueueStatusUpdate;
use App\Models\Permission;
use App\Models\QueuedNotification;
use App\Models\Role;
use App\Models\UploadQueue;
use App\Models\User;
use App\Notifications\TemplatedNotification;
use App\Services\LabUploadAdminDigestQueue;
use App\Services\NotificationBatcher;
use App\Services\NotificationService;
use App\Services\UploadQueueLogEmailFormatter;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    fakePublicStorageForTests();
    fakeUploadChemistryServices();
    Notification::fake();
});

function createUploadForNotifications(int $state = UploadQueue::STATE_RUNNING, array $attributes = []): UploadQueue
{
    Queue::fake();

    return createConfiguredUpload("SMILES,Gpen\nCCO,-1\n", ['smiles', 'g_pen'], attributes: ['state' => $state, ...$attributes]);
}

function runStatusUpdate(UploadQueue $record): void
{
    (new SendUploadQueueStatusUpdate($record->id))->handle(app(NotificationService::class), app(UploadQueueLogEmailFormatter::class));
}

test('an error is e-mailed to the uploader at once and only once', function () {
    $record = createUploadForNotifications();
    $record->addStructuredLog('Detailed validation failed.', UploadQueueLogContextEnums::ERROR, UploadQueueLogTypeEnums::VALIDATION_RUN);

    runStatusUpdate($record);
    runStatusUpdate($record);

    Notification::assertSentOnDemandTimes(TemplatedNotification::class, 1);
    Notification::assertSentOnDemand(
        TemplatedNotification::class,
        fn (TemplatedNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === $record->user->email,
    );

    expect($record->refresh()->logs->last()->type)->toBe(UploadQueueLogTypeEnums::NOTIFICATION);
});

test('a recent informational log waits for the digest instead of being e-mailed at once', function () {
    $record = createUploadForNotifications();
    $record->addStructuredLog('Detailed validation started.', UploadQueueLogContextEnums::INFO, UploadQueueLogTypeEnums::VALIDATION_RUN);

    runStatusUpdate($record);

    Notification::assertNothingSent();
});

test('finished uploads are e-mailed at once', function (int $state) {
    $record = createUploadForNotifications($state);
    $record->addStructuredLog('State changed.', UploadQueueLogContextEnums::SUCCESS, UploadQueueLogTypeEnums::UPLOAD_RUN);

    runStatusUpdate($record);

    Notification::assertSentOnDemandTimes(TemplatedNotification::class, 1);
})->with([
    'done' => UploadQueue::STATE_DONE,
    'waiting for review' => UploadQueue::STATE_REVIEW_REQUIRED,
]);

test('guest uploads are e-mailed to the guest address', function () {
    $record = createUploadForNotifications(attributes: ['user_id' => null, 'guest_email' => 'guest@example.org', 'guest_token' => 'guest-token']);
    $record->addStructuredLog('Detailed validation failed.', UploadQueueLogContextEnums::ERROR, UploadQueueLogTypeEnums::VALIDATION_RUN);

    runStatusUpdate($record);

    Notification::assertSentOnDemand(
        TemplatedNotification::class,
        fn (TemplatedNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'guest@example.org',
    );
});

test('an error log queues an immediate status update', function () {
    $record = createUploadForNotifications();

    $record->addStructuredLog('Import failed.', UploadQueueLogContextEnums::ERROR, UploadQueueLogTypeEnums::UPLOAD_RUN);

    Queue::assertPushed(SendUploadQueueStatusUpdate::class);
});

test('moving an upload to pending starts its processing and other states do not', function () {
    $record = createUploadForNotifications(UploadQueue::STATE_CONFIGURED);

    $record->update(['state' => UploadQueue::STATE_REVIEW_REQUIRED]);
    Queue::assertNotPushed(ProcessUploadQueueRecord::class);

    $record->update(['state' => UploadQueue::STATE_PENDING]);
    Queue::assertPushed(ProcessUploadQueueRecord::class, 1);
});

test('the notifier reminds uploaders of uploads left unconfigured for a day', function () {
    $stale = createUploadForNotifications(UploadQueue::STATE_UPLOADED);
    $fresh = createUploadForNotifications(UploadQueue::STATE_UPLOADED);

    $this->travel(-25)->hours();
    $stale->addStructuredLog('Upload request created.');
    $this->travelBack();
    $fresh->addStructuredLog('Upload request created.');

    $this->artisan(SendUploadQueueNotifications::class)->assertSuccessful();

    expect($stale->refresh()->logs->last()->message)->toBe('Your uploaded data is still waiting for configuration.')
        ->and($fresh->refresh()->logs->last()->message)->toBe('Upload request created.');
});

test('the notifier queues digests for errors and for logs older than ten minutes', function () {
    $error = createUploadForNotifications();
    $error->addStructuredLog('Import failed.', UploadQueueLogContextEnums::ERROR, UploadQueueLogTypeEnums::UPLOAD_RUN);

    $old = createUploadForNotifications();
    $this->travel(-11)->minutes();
    $old->addStructuredLog('Detailed validation started.');
    $this->travelBack();

    $recent = createUploadForNotifications();
    $recent->addStructuredLog('Detailed validation started.');

    Queue::fake();
    $this->artisan(SendUploadQueueNotifications::class)->assertSuccessful();

    $queuedFor = Queue::pushed(SendUploadQueueStatusUpdate::class)
        ->map(fn (SendUploadQueueStatusUpdate $job): int => (fn (): int => $this->recordId)->call($job))
        ->all();

    expect($queuedFor)->toContain($error->id, $old->id)->not->toContain($recent->id);
});

test('the admin digest goes to upload queue managers at most once every two hours', function () {
    collect(PermissionEnums::cases())->each(fn (PermissionEnums $permission) => Permission::query()->firstOrCreate(
        ['name' => $permission->value, 'guard_name' => 'web'],
        ['description' => $permission->description()],
    ));
    $managers = Role::query()->create(['name' => 'Upload managers', 'guard_name' => 'web']);
    $managers->givePermissionTo(PermissionEnums::UPLOAD_QUEUE_MANAGE_ALL->value);
    $manager = User::factory()->create();
    $manager->syncRoles([$managers]);
    $bystander = User::factory()->create();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $record = createUploadForNotifications();
    app(LabUploadAdminDigestQueue::class)->push(LabUploadAdminDigestQueue::EVENT_REVIEW_REQUIRED, $record);

    $this->artisan(SendUploadQueueAdminDigest::class)->assertSuccessful();

    // The digest is a summary already, so it is sent at once with its content.
    Notification::assertSentToTimes($manager, TemplatedNotification::class, 1);
    Notification::assertSentTo($manager, TemplatedNotification::class, fn (TemplatedNotification $notification): bool => filled($notification->emailMessage)
        && ! str_contains((string) $notification->emailMessage, '{{'));
    Notification::assertNotSentTo($bystander, TemplatedNotification::class);

    expect(queuedAdminDigestsFor($manager))->toBe(0)
        ->and(QueuedNotification::query()->forGroup(LabUploadAdminDigestQueue::GROUP)->pending()->count())->toBe(0);

    app(LabUploadAdminDigestQueue::class)->push(LabUploadAdminDigestQueue::EVENT_PROCESSING_ERROR, $record, 'Import failed.');
    $this->artisan(SendUploadQueueAdminDigest::class)->assertSuccessful();

    Notification::assertSentToTimes($manager, TemplatedNotification::class, 1);
    expect(QueuedNotification::query()->forGroup(LabUploadAdminDigestQueue::GROUP)->pending()->count())->toBe(1);

    $this->travel(121)->minutes();
    $this->artisan(SendUploadQueueAdminDigest::class)->assertSuccessful();

    Notification::assertSentToTimes($manager, TemplatedNotification::class, 2);
    expect(queuedAdminDigestsFor($manager))->toBe(0);
});

function queuedAdminDigestsFor(User $user): int
{
    return QueuedNotification::query()
        ->where('group_key', NotificationBatcher::groupKey(NotificationType::UPLOAD_ADMIN_DIGEST))
        ->where('notifiable_type', $user->getMorphClass())
        ->where('notifiable_id', $user->getKey())
        ->count();
}
