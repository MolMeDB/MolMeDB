<?php

namespace App\Filament\Pages\Auth;

use App\Notifications\EmailVerification as NotificationsEmailVerification;
use Exception;
use Filament\Auth\Pages\EmailVerification\EmailVerificationPrompt;
use Filament\Facades\Filament;
use Filament\Notifications\Auth\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;

class EmailVerification extends EmailVerificationPrompt
{
    public function mount(): void
    {
        if ($this->getVerifiable()->hasVerifiedEmail()) {
            $this->redirect(Filament::getUrl());
        }
    }

    protected function sendEmailVerificationNotification(MustVerifyEmail $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        if (! method_exists($user, 'notify')) {
            $userClass = $user::class;

            throw new Exception("Model [{$userClass}] does not have a [notify()] method.");
        }

        // $notification = app(VerifyEmail::class);
        $notification = new NotificationsEmailVerification;
        $notification->url = Filament::getVerifyEmailUrl($user);

        $user->notify($notification);
    }
}
