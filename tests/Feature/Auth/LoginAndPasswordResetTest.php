<?php

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/*
 * Signing in is limited to five failed attempts per address and IP, needs a
 * verified address, and password reset links are sent only after the
 * Turnstile check.
 */

test('five failed sign-ins lock the address out, even with the right password after', function () {
    Event::fake([Lockout::class]);
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertUnprocessable();
    }

    $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', fn (string $message): bool => str_contains($message, 'seconds'));

    $this->assertGuest();
    Event::assertDispatched(Lockout::class);
});

test('the lock-out ends after its time is up', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $this->travel(61)->seconds();

    $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    $this->assertAuthenticatedAs($user);
});

test('a successful sign-in resets the count of failed attempts', function () {
    $user = User::factory()->create();

    foreach (range(1, 4) as $attempt) {
        $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    auth()->logout();

    foreach (range(1, 4) as $attempt) {
        $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
});

test('an unverified address cannot sign in', function () {
    $user = User::factory()->unverified()->create();

    $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email_verification');

    $this->assertGuest();
});

test('a password reset link needs a passed Turnstile check', function () {
    Notification::fake();
    config()->set('services.turnstile.enabled', true);
    config()->set('services.turnstile.secret_key', 'turnstile-secret');
    config()->set('services.turnstile.verify_url', 'https://turnstile.test/siteverify');
    $user = User::factory()->create();

    $captchaPassed = false;
    Http::fake(function () use (&$captchaPassed) {
        return Http::response(['success' => $captchaPassed]);
    });

    $this->postJson('/forgot-password', ['email' => $user->email, 'turnstile_token' => 'bad-token'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('turnstile_token');

    $this->postJson('/forgot-password', ['email' => $user->email])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('turnstile_token');

    Notification::assertNothingSent();

    $captchaPassed = true;
    $this->postJson('/forgot-password', ['email' => $user->email, 'turnstile_token' => 'good-token'])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class);
    Http::assertSent(fn (Request $request): bool => $request['secret'] === 'turnstile-secret' && $request['response'] === 'good-token');
});

test('Turnstile without a secret key refuses instead of letting requests through', function () {
    config()->set('services.turnstile.enabled', true);
    config()->set('services.turnstile.secret_key', null);

    $this->postJson('/forgot-password', ['email' => 'someone@example.org', 'turnstile_token' => 'token'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.turnstile_token.0', 'Captcha verification is not configured.');
});

test('a failing mailer answers with an error instead of claiming the link was sent', function () {
    config()->set('services.turnstile.enabled', false);
    $user = User::factory()->create();
    Password::shouldReceive('sendResetLink')->andThrow(new RuntimeException('SMTP connection refused.'));

    $this->postJson('/forgot-password', ['email' => $user->email, 'turnstile_token' => 'token'])
        ->assertStatus(500)
        ->assertJsonPath('message', 'Password reset email could not be sent. Please try again later.');
});
