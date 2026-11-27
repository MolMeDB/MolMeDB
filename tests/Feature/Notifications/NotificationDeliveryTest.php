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
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
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

test('a push opens the page of the stored notification, which holds the whole message', function () {
    config()->set('app.frontend_url', 'https://molmedb.test');
    useNotificationTemplate(NotificationTemplate::KEY_ACCOUNT_ROLE_CHANGED);
    $user = User::factory()->create();
    NotificationPreference::query()->create([
        'user_id' => $user->id,
        'notification_key' => NotificationTemplate::KEY_ACCOUNT_ROLE_CHANGED,
        'email_enabled' => false,
        'push_enabled' => true,
    ]);

    $record = app(NotificationService::class)->send($user, NotificationTemplate::KEY_ACCOUNT_ROLE_CHANGED, [
        'role' => 'Viewer',
        'dataset_url' => 'https://molmedb.test/lab/datasets/7',
    ]);

    Notification::assertSentTo($user, TemplatedNotification::class, fn (TemplatedNotification $notification): bool => $notification->toWebPush($user)->url === "https://molmedb.test/account/notifications/{$record->id}");
});

test('without a stored copy a push opens the page the notification is about, if it is a web address', function (array $data, ?string $url) {
    $notification = new TemplatedNotification(notificationTitle: 'Title', notificationBody: 'Body', data: $data);

    expect($notification->toWebPush(User::factory()->make())->url)->toBe($url);
})->with([
    'dataset' => [['dataset_url' => 'https://molmedb.test/lab/datasets/7'], 'https://molmedb.test/lab/datasets/7'],
    'manage before dataset' => [['dataset_url' => 'https://molmedb.test/a', 'manage_url' => 'https://molmedb.test/b'], 'https://molmedb.test/b'],
    'not a web address' => [['admin_url' => 'javascript:alert(1)'], null],
    'none' => [[], null],
]);

test('a user without the permission gets no admin notification, even with a channel turned on before', function () {
    useNotificationTemplate(NotificationTemplate::KEY_SYSTEM_ADMIN_JOB_FAILED, [
        'notification_title' => 'Background job failure',
        'notification_body' => '{{ error }}',
        'email_message' => '<p>{{ error }}</p>',
    ]);
    // E.g. a former administrator whose role was taken away.
    $user = User::factory()->create();
    NotificationPreference::query()->create([
        'user_id' => $user->id,
        'notification_key' => NotificationTemplate::KEY_SYSTEM_ADMIN_JOB_FAILED,
        'email_enabled' => true,
        'push_enabled' => true,
    ]);

    expect(app(NotificationService::class)->send($user, NotificationTemplate::KEY_SYSTEM_ADMIN_JOB_FAILED, ['error' => 'The cron failed.'], skipBatching: true))->toBeNull()
        ->and(UserNotification::query()->whereBelongsTo($user)->exists())->toBeFalse();
    Notification::assertNothingSent();
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
        ->and($html)->not->toContain('<li>'.$user->id.'</li>');
});

test('a template that counts the events itself gets no list in the e-mail', function () {
    useNotificationTemplate(NotificationTemplate::KEY_PREDICTION_JOB_FINISHED, [
        'notification_title' => 'Jobs finished',
        'notification_body' => 'Jobs finished.',
        'email_message' => '<p>{{ count }} jobs finished.</p>',
    ]);
    $user = User::factory()->create();
    $service = app(NotificationService::class);
    $service->send($user, NotificationTemplate::KEY_PREDICTION_JOB_FINISHED, finishedPredictionData('Ethanol run'));
    $service->send($user, NotificationTemplate::KEY_PREDICTION_JOB_FINISHED, finishedPredictionData('Caffeine run'));

    $this->travel(11)->minutes();
    $this->artisan(FlushQueuedNotifications::class)->assertSuccessful();

    Notification::assertSentTo($user, TemplatedNotification::class, fn (TemplatedNotification $notification): bool => (string) $notification->emailMessage === '<p>2 jobs finished.</p>');
});

test('a batched label cannot inject markup, Markdown or an unsafe link into the e-mail', function () {
    // A template that does not start with a block tag is parsed as Markdown.
    useNotificationTemplate(NotificationTemplate::KEY_PREDICTION_JOB_FINISHED, [
        'notification_title' => 'Job finished',
        'notification_body' => 'Job finished.',
        'email_message' => 'Job finished.',
    ]);
    $user = User::factory()->create();
    $service = app(NotificationService::class);
    $service->send($user, NotificationTemplate::KEY_PREDICTION_JOB_FINISHED, [
        ...finishedPredictionData('first'),
        'comment' => "x\n\n[click me](https://evil.example) ![i](https://evil.example/p.png) <b>bold</b>",
        'dataset_url' => 'javascript:alert(1)',
    ]);
    $service->send($user, NotificationTemplate::KEY_PREDICTION_JOB_FINISHED, [
        ...finishedPredictionData('second'),
        'comment' => '[inline](https://evil.example/inline) '.str_repeat('a', 500),
    ]);

    $this->travel(11)->minutes();
    $this->artisan(FlushQueuedNotifications::class)->assertSuccessful();

    Notification::assertSentTo($user, TemplatedNotification::class, function (TemplatedNotification $notification) use ($user): bool {
        $html = (string) $notification->toMail($user)->render();

        expect($html)->toContain('This message summarizes 2 notifications')
            ->and($html)->toContain('href="https://molmedb.test/lab/running-predictions?token=second"')
            ->and($html)->not->toContain('evil.example"')
            ->and($html)->not->toContain('<img src="https://evil.example')
            ->and($html)->not->toContain('<b>bold</b>')
            ->and($html)->not->toContain('javascript:')
            ->and($html)->not->toContain(str_repeat('a', 200));

        return true;
    });
});

test('batched items with an empty name fall back to the record number', function () {
    $items = collect([
        new QueuedNotification(['event' => 'upload.status_update', 'notifiable_id' => 1, 'data' => ['dataset_name' => '', 'record_id' => 7]]),
    ]);

    expect(FlushQueuedNotifications::formatItemsList($items)->toHtml())->toContain('<li>#7</li>');
});

test('the push channel hands the library a logger, so a missing optional PHP extension does not stop the push', function () {
    $keys = VAPID::createVapidKeys();
    config([
        'webpush.vapid.subject' => 'mailto:push@example.test',
        'webpush.vapid.public_key' => $keys['publicKey'],
        'webpush.vapid.private_key' => $keys['privateKey'],
    ]);

    $webPush = (new WebPushChannel)->webPush();

    // Without one, the library reports a missing GMP/BCMath extension with
    // trigger_error(), which Laravel turns into an exception.
    expect((new ReflectionProperty(WebPush::class, 'logger'))->getValue($webPush))
        ->toBeInstanceOf(LoggerInterface::class);
});
