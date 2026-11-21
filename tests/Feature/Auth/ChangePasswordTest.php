<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/*
 * The frontend's account settings change the password with
 * PUT /api/user/password: the current password, the new one and its
 * confirmation. It answers 200 on success and 422 with errors otherwise.
 */

test('a signed-in user changes their password with the current one', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/user/password', [
            'current_password' => 'password',
            'password' => 'New-secret-password-42',
            'password_confirmation' => 'New-secret-password-42',
        ])
        ->assertOk();

    expect(Hash::check('New-secret-password-42', $user->refresh()->password))->toBeTrue();
});

test('a wrong current password or an unconfirmed new one is refused', function (array $payload, string $field) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/user/password', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
})->with([
    'wrong current password' => [['current_password' => 'not-my-password', 'password' => 'New-secret-password-42', 'password_confirmation' => 'New-secret-password-42'], 'current_password'],
    'missing current password' => [['password' => 'New-secret-password-42', 'password_confirmation' => 'New-secret-password-42'], 'current_password'],
    'confirmation differs' => [['current_password' => 'password', 'password' => 'New-secret-password-42', 'password_confirmation' => 'Other-password-42'], 'password'],
]);

test('a guest cannot change a password', function () {
    $this->putJson('/api/user/password', [
        'current_password' => 'password',
        'password' => 'New-secret-password-42',
        'password_confirmation' => 'New-secret-password-42',
    ])->assertUnauthorized();
});

test('guessing the current password is throttled', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (range(1, 6) as $attempt) {
        $this->putJson('/api/user/password', ['current_password' => "guess-{$attempt}", 'password' => 'New-secret-password-42', 'password_confirmation' => 'New-secret-password-42']);
    }

    $this->putJson('/api/user/password', ['current_password' => 'password', 'password' => 'New-secret-password-42', 'password_confirmation' => 'New-secret-password-42'])
        ->assertTooManyRequests();
});
