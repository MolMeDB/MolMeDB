<?php

namespace App\Notifications;

use App\Models\UserNotification;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Messages\WebPushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class TemplatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $notificationTitle,
        public readonly string $notificationBody,
        public readonly ?string $emailSubject = null,
        public readonly ?string $emailMessage = null,
        public readonly array $data = [],
        public readonly ?string $preferencesUrl = null,
        public readonly bool $emailAllowed = true,
        public readonly bool $pushAllowed = false,
        public readonly ?string $replyToEmail = null,
        public readonly ?string $replyToName = null,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        if ($this->emailAllowed && $this->hasEmail()) {
            $channels[] = 'mail';
        }

        if ($this->pushAllowed) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * The stored copy of this notification (set by NotificationService
     * before sending), whose page the push opens.
     */
    public ?int $userNotificationId = null;

    /**
     * A push opens the notification's own page, which holds the whole
     * message (a push shows only its first lines) and links the page it is
     * about. Without a stored copy it opens that page directly.
     */
    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: html_entity_decode($this->notificationTitle, ENT_QUOTES),
            body: html_entity_decode(strip_tags($this->notificationBody), ENT_QUOTES),
            url: $this->userNotificationId !== null
                ? UserNotification::frontendUrlFor($this->userNotificationId)
                : self::relatedUrl($this->data),
            icon: rtrim((string) config('app.frontend_url'), '/').'/icons/192.png',
        );
    }

    /**
     * The page a notification is about, from its template data. Only an
     * http(s) address counts: it ends up in a link.
     *
     * @param  array<string, mixed>  $data
     */
    public static function relatedUrl(array $data): ?string
    {
        foreach (['manage_url', 'dataset_url', 'admin_url'] as $key) {
            $url = $data[$key] ?? null;

            if (is_string($url) && preg_match('#^https?://#i', $url) === 1) {
                return $url;
            }
        }

        return null;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->emailSubject)
            ->greeting(' ')
            ->line(new HtmlString($this->emailMessage));

        if ($this->replyToEmail) {
            $mail->replyTo($this->replyToEmail, $this->replyToName);
        }

        if ($this->preferencesUrl) {
            $mail->line(new HtmlString(
                '<hr style="border:none;border-top:1px solid #e0e0e0;margin:24px 0 12px;">'
                .'<p style="font-size:12px;color:#888888;">'
                .'Don\'t want to receive this notification? '
                .'<a href="'.e($this->preferencesUrl).'">Manage your notification preferences</a>.'
                .'</p>'
            ));
        }

        return $mail->salutation(' ');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->toUserNotificationData();
    }

    /**
     * @return array{title: string, body: string, email_subject: ?string, email_message: ?string, data: array<string, mixed>}
     */
    public function toUserNotificationData(): array
    {
        return [
            'title' => $this->notificationTitle,
            'body' => $this->notificationBody,
            'email_subject' => $this->emailSubject,
            'email_message' => $this->emailMessage,
            'data' => $this->data,
        ];
    }

    private function hasEmail(): bool
    {
        return filled($this->emailSubject) && filled($this->emailMessage);
    }
}
