<?php

use App\Enums\NotificationType;
use App\Enums\PermissionEnums;
use App\Models\NotificationPreference;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use Spatie\Permission\PermissionRegistrar;

/*
 * The signed-in user's notifications in the web (one in full, marking one as
 * read) and which notification types the preferences let a user turn on.
 */

beforeEach(function () {
    collect(PermissionEnums::cases())->each(fn (PermissionEnums $permission) => Permission::query()->firstOrCreate(
        ['name' => $permission->value, 'guard_name' => 'web'],
        ['description' => $permission->description()],
    ));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function storedNotificationOf(User $user, array $attributes = []): UserNotification
{
    return UserNotification::query()->create([
        'user_id' => $user->id,
        'state' => UserNotification::STATE_NEW,
        'title' => 'Background job failure',
        'body' => '2 background job(s) failed recently.<ul><li>backup</li></ul>',
        'email_subject' => 'MolMeDB: background job failure',
        'email_message' => '<p>2 background job(s) failed recently.</p><ul><li>backup: disk full</li><li>export: timeout</li></ul>',
        'data' => ['admin_url' => 'https://molmedb.test/admin/activity'],
        ...$attributes,
    ]);
}

test('a notification is read in full on its own page', function () {
    $user = User::factory()->create();
    $notification = storedNotificationOf($user);

    $this->actingAs($user)->getJson("/api/notifications/{$notification->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $notification->id)
        ->assertJsonPath('data.message', '<p>2 background job(s) failed recently.</p><ul><li>backup: disk full</li><li>export: timeout</li></ul>')
        ->assertJsonPath('data.url', 'https://molmedb.test/admin/activity')
        ->assertJsonMissingPath('data.data');

    // Loading the page (possibly in advance) leaves it unread.
    expect($notification->refresh()->state)->toBe(UserNotification::STATE_NEW);
});

test('a notification without an e-mail shows its body as the message', function () {
    $user = User::factory()->create();
    $notification = storedNotificationOf($user, ['email_subject' => null, 'email_message' => null, 'data' => []]);

    $this->actingAs($user)->getJson("/api/notifications/{$notification->id}")
        ->assertOk()
        ->assertJsonPath('data.message', $notification->body)
        ->assertJsonPath('data.url', null);
});

test('a notification of someone else is not found', function (string $method, string $suffix) {
    $notification = storedNotificationOf(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->json($method, "/api/notifications/{$notification->id}{$suffix}")
        ->assertNotFound();

    expect($notification->refresh()->state)->toBe(UserNotification::STATE_NEW);
})->with([
    'show' => ['GET', ''],
    'mark as read' => ['POST', '/read'],
]);

test('an identifier too long for a notification is not found', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/notifications/99999999999999999999')
        ->assertNotFound();
});

test('a guest gets no notification', function () {
    $notification = storedNotificationOf(User::factory()->create());

    $this->getJson("/api/notifications/{$notification->id}")->assertUnauthorized();
});

test('opening a notification marks only that one as read', function () {
    $user = User::factory()->create();
    $opened = storedNotificationOf($user);
    $other = storedNotificationOf($user);

    $this->actingAs($user)->postJson("/api/notifications/{$opened->id}/read")->assertOk();

    expect($opened->refresh()->state)->toBe(UserNotification::STATE_READ)
        ->and($opened->read_at)->not->toBeNull()
        ->and($other->refresh()->state)->toBe(UserNotification::STATE_NEW);
});

test('the list takes a limit of at most a hundred', function () {
    $user = User::factory()->create();
    foreach (range(1, 3) as $index) {
        storedNotificationOf($user, ['title' => "Notification {$index}"]);
    }

    $this->actingAs($user)->getJson('/api/notifications?limit=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.title', 'Notification 3');

    $this->actingAs($user)->getJson('/api/notifications?limit=101')->assertUnprocessable();
});

test('a user without administrator permissions is not offered admin notifications', function () {
    $this->actingAs(User::factory()->create())->getJson('/api/notifications/preferences')
        ->assertOk()
        ->assertJsonMissing(['key' => NotificationType::SYSTEM_ADMIN_JOB_FAILED->value])
        ->assertJsonMissing(['key' => NotificationType::SYSTEM_ADMIN_HEALTH_REPORT->value])
        ->assertJsonFragment(['key' => NotificationType::PREDICTION_JOB_FINISHED->value]);
});

test('a user without administrator permissions cannot turn admin notifications on', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/notifications/preferences', [
        'preferences' => [
            ['key' => NotificationType::SYSTEM_ADMIN_JOB_FAILED->value, 'email_enabled' => true, 'push_enabled' => true],
            ['key' => NotificationType::SYSTEM_ADMIN_HEALTH_REPORT->value, 'email_enabled' => true, 'push_enabled' => true],
            ['key' => NotificationType::PREDICTION_JOB_FINISHED->value, 'email_enabled' => false, 'push_enabled' => true],
        ],
    ])->assertOk();

    expect(NotificationPreference::query()->whereBelongsTo($user)->pluck('notification_key')->all())
        ->toBe([NotificationType::PREDICTION_JOB_FINISHED->value]);
});

test('a holder of the system monitor permission is offered the system notifications', function () {
    $role = Role::query()->create(['name' => 'Monitor', 'guard_name' => 'web']);
    $role->givePermissionTo(PermissionEnums::SYSTEM_MONITOR->value);
    $user = User::factory()->create();
    $user->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user->refresh())->getJson('/api/notifications/preferences')
        ->assertOk()
        ->assertJsonFragment(['key' => NotificationType::SYSTEM_ADMIN_JOB_FAILED->value]);
});
