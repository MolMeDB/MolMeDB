<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::get('/register', function () {
    return redirect()->intended(
        config('app.frontend_url').'/register'
    );
})
    ->middleware('guest')
    ->name('frontend.register');

Route::post('/register', [RegisteredUserController::class, 'store'])
    ->middleware('guest');
// ->name('register');

Route::post('/login', [AuthenticatedSessionController::class, 'store']);
// ->name('login');

Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware('guest');
// ->name('password.email');

Route::post('/reset-password', [NewPasswordController::class, 'store'])
    ->middleware('guest');
// ->name('password.store');

Route::get('/reset-password/{token}', [ResetPasswordController::class, '__invoke'])
    ->name('frontend.password.reset');

Route::put('/api/user/password', [PasswordController::class, 'update'])
    ->middleware(['auth', 'throttle:6,1'])
    ->name('user-password.update');

Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
    ->middleware('throttle:6,1')
    ->name('verification.verify');

Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
    ->middleware(['auth', 'throttle:6,1'])
    ->name('verification.send');

Route::post('/email/verification-notification/guest', [EmailVerificationNotificationController::class, 'storeGuest'])
    ->middleware(['guest', 'throttle:resend-email-verification'])
    ->name('verification.send.guest');

// Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
//     ->middleware('auth');
// ->name('logout');
