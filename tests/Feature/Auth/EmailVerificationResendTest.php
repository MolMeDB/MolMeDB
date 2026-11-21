<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
});

test('a signed-in unverified user can ask for a new verification link', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->postJson(route('verification.send'))
        ->assertOk()
        ->assertJson(['status' => 'verification-link-sent']);

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('a signed-in verified user gets no new verification link', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect();

    Notification::assertNothingSent();
});

test('a guest cannot use the signed-in resend endpoint', function () {
    $this->postJson(route('verification.send'))->assertUnauthorized();

    Notification::assertNothingSent();
});

test('a guest resend sends a link to an unverified account', function () {
    $user = User::factory()->unverified()->create();

    $this->postJson(route('verification.send.guest'), ['email' => $user->email])
        ->assertOk()
        ->assertJson(['status' => 'verification-link-sent']);

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('a guest resend answers the same for verified and unknown addresses without sending anything', function (string $email) {
    User::factory()->create(['email' => 'verified@example.org']);

    $this->postJson(route('verification.send.guest'), ['email' => $email])
        ->assertOk()
        ->assertJson(['status' => 'verification-link-sent']);

    Notification::assertNothingSent();
})->with([
    'verified account' => 'verified@example.org',
    'unknown address' => 'nobody@example.org',
]);

test('a guest resend requires a valid email address', function () {
    $this->postJson(route('verification.send.guest'), ['email' => 'not-an-email'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('a guest resend is limited to one request per minute for the same address', function () {
    $user = User::factory()->unverified()->create();

    $this->postJson(route('verification.send.guest'), ['email' => $user->email])->assertOk();
    $this->postJson(route('verification.send.guest'), ['email' => strtoupper($user->email)])->assertTooManyRequests();

    Notification::assertSentToTimes($user, VerifyEmail::class, 1);
});

test('a guest resend is capped per IP address when the addresses rotate', function () {
    foreach (range(1, 10) as $attempt) {
        $this->postJson(route('verification.send.guest'), ['email' => "rotating{$attempt}@example.org"])->assertOk();
    }

    $this->postJson(route('verification.send.guest'), ['email' => 'rotating11@example.org'])->assertTooManyRequests();
});
