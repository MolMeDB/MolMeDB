<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;
use NotificationChannels\WebPush\WebPushChannel;

class DatasetCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $datasetId,
        public string $datasetName,
    ) {
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        $preference = $notifiable->notificationPreferences()
            ->where('notification_type', 'dataset_created')
            ->first();

        if (!$preference) {
            return $channels;
        }

        if ($preference->email) {
            $channels[] = 'mail';
        }

        if ($preference->web_push) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Nový dataset')
            ->greeting('Dobrý den, ' . $notifiable->name . ',')
            ->line('Byl vytvořen nový dataset: ' . $this->datasetName)
            ->action('Zobrazit dataset', url('/'))
            ->salutation('S pozdravem, tým MolMeDB.');
    }

    /**
     * Get the database representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'notification_type' => 'dataset_created',
            'dataset_id' => $this->datasetId,
            'dataset_name' => $this->datasetName,
        ];
    }

    /**
     * Get the Web Push representation of the notification.
     */
    public function toWebPush(
        object $notifiable,
        Notification $notification
    ): WebPushMessage {
        return (new WebPushMessage)
            ->title('Nový dataset')
            ->body('Byl vytvořen nový dataset: ' . $this->datasetName)
            ->icon('/assets/icons/web-app-manifest-192x192.png')
            ->data([
            'url' => '/dataset/' . $this->datasetId,
            'dataset_id' => $this->datasetId,
            ])
            ->options(['TTL' => 1000]);
    }
}