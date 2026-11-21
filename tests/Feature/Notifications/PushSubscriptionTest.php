<?php

use App\Models\PushSubscription;
use App\Models\User;

/*
 * The server sends every web push to the stored endpoint, so endpoints must
 * be public HTTPS push services, and one browser endpoint belongs to the
 * user who subscribed it last.
 */

test('a public HTTPS push endpoint is stored for the signed-in user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/notifications/push-subscriptions', [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-token'],
    ])->assertOk();

    expect($user->pushSubscriptions()->sole()->endpoint)->toBe('https://fcm.googleapis.com/fcm/send/abc123');
});

test('push endpoints that are not public HTTPS services are refused', function (string $endpoint) {
    $this->actingAs(User::factory()->create())->postJson('/api/notifications/push-subscriptions', [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-token'],
    ])->assertUnprocessable()->assertJsonValidationErrors('endpoint');

    expect(PushSubscription::query()->count())->toBe(0);
})->with([
    'plain HTTP' => 'http://fcm.googleapis.com/fcm/send/abc',
    'IPv4 address' => 'https://10.0.0.5/push',
    'IPv6 address' => 'https://[::1]/push',
    'localhost' => 'https://localhost/push',
    'single-label host' => 'https://redis/push',
    'internal domain' => 'https://metadata.internal/computeMetadata',
]);

test('subscribing an endpoint takes it over from another user of the same browser', function () {
    $previous = User::factory()->create();
    $previous->pushSubscriptions()->create([
        'endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/shared',
        'public_key' => 'old-key',
        'auth_token' => 'old-auth',
    ]);
    $current = User::factory()->create();

    $this->actingAs($current)->postJson('/api/notifications/push-subscriptions', [
        'endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/shared',
        'keys' => ['p256dh' => 'new-key', 'auth' => 'new-auth'],
    ])->assertOk();

    expect($previous->pushSubscriptions()->count())->toBe(0)
        ->and($current->pushSubscriptions()->count())->toBe(1);
});
