<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
 * Signing in, registration and password resets go through the application's
 * own routes (routes/auth.php). Fortify's duplicate routes under /api, among
 * them a registration without Turnstile, must not exist.
 */

test('no Fortify route is registered', function () {
    $fortifyRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getActionName(), 'Laravel\\Fortify\\'))
        ->map(fn ($route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($fortifyRoutes)->toBe([]);
});

test('the former Fortify endpoints answer not found', function (string $method, string $uri) {
    $this->json($method, $uri, ['name' => 'Bot', 'email' => 'bot@example.org', 'password' => 'secret-password', 'password_confirmation' => 'secret-password'])
        ->assertNotFound();

    expect(User::query()->where('email', 'bot@example.org')->exists())->toBeFalse();
})->with([
    'registration' => ['POST', '/api/register'],
    'login' => ['POST', '/api/login'],
    'forgot password' => ['POST', '/api/forgot-password'],
    'reset password' => ['POST', '/api/reset-password'],
    'two-factor challenge' => ['POST', '/api/two-factor-challenge'],
]);

test('the former Fortify account endpoints answer not found to signed-in users', function (string $method, string $uri) {
    $this->actingAs(User::factory()->create())
        ->json($method, $uri)
        ->assertNotFound();
})->with([
    'profile' => ['PUT', '/api/user/profile-information'],
    'password' => ['PUT', '/api/user/password'],
    'enable two-factor' => ['POST', '/api/user/two-factor-authentication'],
    'two-factor QR code' => ['GET', '/api/user/two-factor-qr-code'],
]);

test('a guest opening a signed-in web page is sent to the frontend sign-in', function () {
    $this->get('/export/upload-queue/1')
        ->assertRedirect(rtrim((string) config('app.frontend_url'), '/').'/login');
});
