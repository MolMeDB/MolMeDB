<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;
use NotificationChannels\WebPush\WebPushChannel;

class TestWebPushNotification extends Notification
{
    public function via(object $notifiable)
    {
        return [WebPushChannel::class];
    }

    public function toWebPush($notifiable, $notification)
    {
        return (new WebPushMessage)
            ->title('MolMeDB test')
            ->body('Web Push funguje!')
            ->icon('/assets/icons/web-app-manifest-192x192.png')
            ->action('Test', 'test')
            ->options(['TTL' => 1000]);
    }
}