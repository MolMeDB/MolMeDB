<?php

use App\Console\Commands\FlushQueuedNotifications;
use App\Enums\NotificationType;
use App\Enums\PermissionEnums;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\QueuedNotification;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\TemplatedNotification;
use App\Services\NotificationBatcher;
use App\Services\NotificationService;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/*
 * NotificationService: the in-app record, the e-mail and push channels by
 * the user's preferences, permission-gated types, template rendering and
 * batching of frequent notification types.
 */

beforeEach(function () {
    Notification::fake();

    collect(PermissionEnums::cases())->each(fn (PermissionEnums $permission) => Permission::query()->firstOrCreate(
        ['name' => $permission->value, 'guard_name' => 'web'],
        ['description' => $permission->description()],
    ));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * Makes the template of the given key active with known content.
 *
 * @param  array<string, mixed>  $attributes
 */
function useNotificationTemplate(string $key, array $attributes = []): NotificationTemplate
{
    $template = NotificationTemplate::query()->updateOrCreate(['key' => $key], [
        'name' => $key,
        'notification_title' => 'Role changed to {{ role }}',
        'notification_body' => 'Your role is now {{ role }}.',
        'email_subject' => 'Your MolMeDB role changed',
        'email_message' => '<p>Your role is now {{ role }}.</p>',
        'is_active' => true,
        ...$attributes,
    ]);

    return $template->refresh();
}

function createUserWithPermission(PermissionEnums $permission): User
{
    $role = Role::query()->create(['name' => 'Role '.fake()->unique()->numberBetween(1, 1_000_000), 'guard_name' => 'web']);
    $role->givePermissionTo($permission->value);
    $user = User::factory()->create();
    $user->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

test('a notification is stored in the app and e-mailed by default', function () {
    useNotificationTemplate(NotificationTemplate::KEY_ACCOUNT_ROLE_CHANGED);
    $user = User::factory()->create();

    $record = app(NotificationService::class)->send($user, NotificationTemplate::KEY_ACCOUNT_ROLE_CHANGED, ['role' => 'Contributor']);

    expect($record)->toBeInstanceOf(UserNotification::class)
        ->and($record->title)->toBe('Role changed to Contributor')
        ->and($record->emailed_at)->not->toBeNull();

    Notification::assertSentTo($user, TemplatedNotification::class, fn (TemplatedNotification $notification, array $channels): bool => $channels === ['mail']);
});

test('the user preferences decide between e-mail and push', function () {
    useNotificationTemplate(NotificationTemplate::KEY_ACCOUNT_ROLE_CHANGED);
    $user = User::factory()->create();
    NotificationPreference::query()->create([
        'user_id' => $user->id,
        'notification_key' => NotificationTemplate::KEY_ACCOUNT_ROLE_CHANGED,
        'email_enabled' => false,
        'push_enabled' => true,
    ]);

    $record = app(NotificationService::class)->send($user, NotificationTemplate::KEY_ACCOUNT_ROLE_CHANGED, ['role' => 'Viewer']);

    expect($record->emailed_at)->toBeNull();
    Notification::assertSentTo($user, TemplatedNotification::class, fn (TemplatedNotification $notification, array $channels): bool => $channels === [WebPushChannel::class]);
});

test('a user who turned off every channel gets only the in-app notification', function () {
    useNotificationTemplate(NotificationTemplate::KEY_ACCOUNT_ROLE_CHANGED);
    $user = User::factory()->create();
    NotificationPreference::query()->create([
        'user_id' => $user->id,
        'notification_key' => NotificationTemplate::KEY_ACCOUNT_ROLE_CHANGED,
        'email_enabled' => false,
        'push_enabled' => false,
    ]);

    $record = app(NotificationService::class)->send($user, NotificationTemplate::KEY_ACCOUNT_ROLE_CHANGED, ['role' => 'Viewer']);

    expect($record)->not->toBeNull();
    Notification::assertNothingSent();
});

test('permission-gated notifications reach only holders of the permission', function () {
    useNotificationTemplate(NotificationTemplate::KEY_SYSTEM_ADMIN_BACKUP_FAILED, [
        'notification_title' => 'Backup {{ label }} failed',
        'notification_body' => '{{ error }}',
        'email_message' => '<p>{{ error }}</p>',
    ]);
    $monitor = createUserWithPermission(PermissionEnums::SYSTEM_MONITOR);
    $bystander = User::factory()->create();

    $service = app(NotificationService::class);
    $service->sendToPermission(PermissionEnums::SYSTEM_MONITOR->value, NotificationTemplate::KEY_SYSTEM_ADMIN_BACKUP_FAILED, ['label' => 'full', 'error' => 'Disk full.']);

    expect($service->send($bystander, NotificationTemplate::KEY_SYSTEM_ADMIN_BACKUP_FAILED, ['label' => 'full', 'error' => 'Disk full.']))->toBeNull();
    Notification::assertSentTo($monitor, TemplatedNotification::class);
    Notification::assertNotSentTo($bystander, TemplatedNotification::class);
});

test('a permission without holders falls back to the given e-mail address', function () {
    useNotificationTemplate(NotificationTemplate::KEY_SYSTEM_ADMIN_BACKUP_FAILED, [
        'notification_title' => 'Backup failed',
        'notification_body' => 'Backup failed.',
        'email_message' => '<p>Backup failed.</p>',
    ]);

    app(NotificationService::class)->sendToPermission(PermissionEnums::SYSTEM_MONITOR->value, NotificationTemplate::KEY_SYSTEM_ADMIN_BACKUP_FAILED, [], 'ops@example.org');

    Notification::assertSentOnDemand(
        TemplatedNotification::class,
        fn (TemplatedNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'ops@example.org',
    );
});

test('template values are escaped in the e-mail', function () {
    useNotificationTemplate(NotificationTemplate::KEY_ACCOUNT_ROLE_CHANGED);
    $user = User::factory()->create();

    app(NotificationService::class)->send($user, NotificationTemplate::KEY_ACCOUNT_ROLE_CHANGED, ['role' => '<script>alert(1)</script>']);

    Notification::assertSentTo($user, TemplatedNotification::class, fn (TemplatedNotification $notification): bool => ! str_contains((string) $notification->emailMessage, '<script>')
        && str_contains((string) $notification->emailMessage, '&lt;script&gt;'));
});

test('a template with a missing variable sends nothing and alerts system monitors', function () {
    useNotificationTemplate(NotificationTemplate::KEY_ACCOUNT_ROLE_CHANGED);
    useNotificationTemplate(NotificationTemplate::KEY_SYSTEM_ADMIN_NOTIFICATION_FAILURE, [
        'notification_title' => 'Notification {{ template_key }} failed',
        'notification_body' => '{{ error }}',
        'email_message' => '<p>{{ error }}</p>',
    ]);
    $user = User::factory()->create();
    $monitor = createUserWithPermission(PermissionEnums::SYSTEM_MONITOR);

    $record = app(NotificationService::class)->send($user, NotificationTemplate::KEY_ACCOUNT_ROLE_CHANGED, []);

    expect($record)->toBeNull()
        ->and(Activity::query()->where('event', NotificationService::EVENT_TEMPLATE_RENDER_FAILED)->exists())->toBeTrue();
    Notification::assertNotSentTo($user, TemplatedNotification::class);
    Notification::assertSentTo($monitor, TemplatedNotification::class);
});

test('inactive templates send nothing', function () {
    useNotificationTemplate(NotificationTemplate::KEY_ACCOUNT_ROLE_CHANGED, ['is_active' => false]);

    expect(app(NotificationService::class)->send(User::factory()->create(), NotificationTemplate::KEY_ACCOUNT_ROLE_CHANGED, ['role' => 'Viewer']))->toBeNull();
    Notification::assertNothingSent();
});

test('batched notification types are collected and flushed as one after their window', function () {
    useNotificationTemplate(NotificationTemplate::KEY_PREDICTION_JOB_FINISHED, [
        'notification_title' => '{{ count }} prediction jobs finished',
        'notification_body' => '{{ count }} jobs finished, the last one {{ comment }}.',
        'email_subject' => 'Prediction jobs finished',
        'email_message' => '<p>{{ items }}</p>',
    ]);
    $user = User::factory()->create();
    $service = app(NotificationService::class);

    foreach (['Ethanol', 'Caffeine', 'Aspirin'] as $comment) {
        expect($service->send($user, NotificationTemplate::KEY_PREDICTION_JOB_FINISHED, ['comment' => $comment, 'dataset_url' => 'https://molmedb.test/lab']))->toBeNull();
    }

    $this->artisan(FlushQueuedNotifications::class)->assertSuccessful();
    Notification::assertNothingSent();

    $this->travel(11)->minutes();
    $this->artisan(FlushQueuedNotifications::class)->assertSuccessful();
    $this->artisan(FlushQueuedNotifications::class)->assertSuccessful();

    Notification::assertSentToTimes($user, TemplatedNotification::class, 1);
    Notification::assertSentTo($user, TemplatedNotification::class, fn (TemplatedNotification $notification): bool => $notification->notificationTitle === '3 prediction jobs finished'
        && str_contains((string) $notification->emailMessage, 'Caffeine'));

    expect(QueuedNotification::query()
        ->where('group_key', NotificationBatcher::groupKey(NotificationType::PREDICTION_JOB_FINISHED))
        ->whereNull('notified_at')
        ->count())->toBe(0);
});

/**
 * @param  array<string, mixed>  $data
 */
function finishedPredictionData(string $comment): array
{
    return [
        'comment' => $comment,
        'total' => 3,
        'done' => 3,
        'failed' => 0,
        'membrane' => 'DOPC',
        'method' => 'COSMOperm',
        'dataset_url' => 'https://molmedb.test/lab/running-predictions?token='.Str::slug($comment),
    ];
}

test('a batch of events for a single-event template lists every event in the e-mail', function () {
    // The template seeded by the migration names one dataset ({{ comment }}).
    $user = User::factory()->create();
    $service = app(NotificationService::class);
    $service->send($user, NotificationTemplate::KEY_PREDICTION_JOB_FINISHED, finishedPredictionData('Ethanol run'));
    $service->send($user, NotificationTemplate::KEY_PREDICTION_JOB_FINISHED, finishedPredictionData('Caffeine run'));

    $this->travel(11)->minutes();
    $this->artisan(FlushQueuedNotifications::class)->assertSuccessful();

    Notification::assertSentTo($user, TemplatedNotification::class, fn (TemplatedNotification $notification): bool => str_contains((string) $notification->emailMessage, 'This message summarizes 2 notifications')
        && str_contains((string) $notification->emailMessage, 'Ethanol run')
        && str_contains((string) $notification->emailMessage, 'Caffeine run')
        && str_ends_with($notification->notificationBody, '(+1 more)'));
});

test('a single batched event is sent without a summary list', function () {
    $user = User::factory()->create();
    app(NotificationService::class)->send($user, NotificationTemplate::KEY_PREDICTION_JOB_FINISHED, finishedPredictionData('Ethanol run'));

    $this->travel(11)->minutes();
    $this->artisan(FlushQueuedNotifications::class)->assertSuccessful();

    Notification::assertSentTo($user, TemplatedNotification::class, fn (TemplatedNotification $notification): bool => ! str_contains((string) $notification->emailMessage, 'summarizes')
        && ! str_contains($notification->notificationBody, 'more)'));
});

test('a template that lists the items itself gets no second list', function () {
    useNotificationTemplate(NotificationTemplate::KEY_PREDICTION_JOB_FINISHED, [
        'notification_title' => '{{ count }} jobs finished',
        'notification_body' => '{{ count }} jobs finished.',
        'email_message' => '<div>{{ items }}</div>',
    ]);
    $user = User::factory()->create();
    $service = app(NotificationService::class);
    $service->send($user, NotificationTemplate::KEY_PREDICTION_JOB_FINISHED, finishedPredictionData('Ethanol run'));
    $service->send($user, NotificationTemplate::KEY_PREDICTION_JOB_FINISHED, finishedPredictionData('Caffeine run'));

    $this->travel(11)->minutes();
    $this->artisan(FlushQueuedNotifications::class)->assertSuccessful();

    Notification::assertSentTo($user, TemplatedNotification::class, fn (TemplatedNotification $notification): bool => ! str_contains((string) $notification->emailMessage, 'summarizes')
        && $notification->notificationBody === '2 jobs finished.');
});

test('batched items without a name are labelled by their record, not by the recipient', function () {
    $user = User::factory()->create();
    $items = collect([
        new QueuedNotification(['event' => 'upload.status_update', 'notifiable_id' => $user->id, 'data' => ['record_id' => 42]]),
        new QueuedNotification(['event' => 'export.failed', 'notifiable_id' => $user->id, 'data' => []]),
    ]);

    $html = FlushQueuedNotifications::formatItemsList($items)->toHtml();

    expect($html)->toContain('#42')
        ->and($html)->toContain('Export failed')
        ->and($html)->not->toContain('#'.$user->id.'<');
});
