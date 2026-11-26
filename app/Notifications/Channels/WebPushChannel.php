<?php

namespace App\Notifications\Channels;

use App\Models\PushSubscription;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Laravel notification channel sending to every browser/device a user has
 * subscribed for web push, over minishlink/web-push directly (not the
 * laravel-notification-channels/webpush wrapper — see NotificationService
 * for why). Expired/invalid subscriptions reported back by the push
 * services are pruned automatically.
 */
class WebPushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWebPush')) {
            return;
        }

        $message = $notification->toWebPush($notifiable);

        if (! $message) {
            return;
        }

        $subscriptions = $notifiable->pushSubscriptions()->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $webPush = $this->webPush();

        $payload = json_encode($message->toArray());

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->public_key,
                    'authToken' => $subscription->auth_token,
                    'contentEncoding' => $subscription->content_encoding ?: 'aes128gcm',
                ]),
                $payload,
            );
        }

        foreach ($webPush->flush() as $report) {
            if (! $report->isSuccess() && $report->isSubscriptionExpired()) {
                PushSubscription::query()->where('endpoint', $report->getEndpoint())->delete();
            }
        }
    }

    /**
     * The library checks its requirements in the constructor. Without a
     * logger it reports them with trigger_error(), which Laravel turns into
     * an exception, so a merely recommended extension that is missing (GMP
     * or BCMath) stopped every push. With the application's logger they are
     * only logged.
     */
    public function webPush(): WebPush
    {
        return new WebPush(
            auth: [
                'VAPID' => [
                    'subject' => config('webpush.vapid.subject'),
                    'publicKey' => config('webpush.vapid.public_key'),
                    'privateKey' => config('webpush.vapid.private_key'),
                ],
            ],
            logger: Log::channel(),
        );
    }
}
