<?php

namespace App\Services;

use App\Enums\NotificationDeliveryMode;
use App\Enums\NotificationType;
use App\Enums\PermissionEnums;
use App\Models\BaseModel;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\TemplatedNotification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class NotificationService
{
    public const EVENT_TEMPLATE_RENDER_FAILED = 'notification_template_render_failed';

    public const EVENT_EMAIL_FAILED = 'notification_email_failed';

    /** A template variable: {{ name }}. */
    public const TEMPLATE_VARIABLE_PATTERN = '/{{\s*([A-Za-z0-9_.-]+)\s*}}/';

    public function __construct(
        private readonly NotificationPreferenceResolver $preferences,
        private readonly NotificationBatcher $batcher,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function send(User $user, string|NotificationTemplate $template, array $data = [], bool $skipBatching = false, ?string $replyToEmail = null, ?string $replyToName = null): ?UserNotification
    {
        $template = $this->resolveTemplate($template);

        if (! $template) {
            return null;
        }

        if (! $this->preferences->isEligible($user, $template->key)) {
            return null;
        }

        if (! $skipBatching) {
            $type = NotificationType::tryFrom($template->key);

            if ($type && $type->deliveryMode() === NotificationDeliveryMode::BATCHED) {
                $this->batcher->queue($user, $type, $data);

                return null;
            }
        }

        $emailAllowed = $this->preferences->emailEnabled($user, $template->key);
        $pushAllowed = $this->preferences->pushEnabled($user, $template->key);

        $notification = $this->buildNotification($template, $data, $this->preferencesUrl(), $emailAllowed, $pushAllowed, $replyToEmail, $replyToName);

        if (! $notification) {
            return null;
        }

        return DB::transaction(function () use ($user, $template, $notification, $emailAllowed, $pushAllowed): UserNotification {
            $payload = $notification->toUserNotificationData();

            $record = UserNotification::query()->create([
                'user_id' => $user->id,
                'notification_template_id' => $template->id,
                'state' => UserNotification::STATE_NEW,
                'title' => $payload['title'],
                'body' => $payload['body'],
                'email_subject' => $payload['email_subject'],
                'email_message' => $payload['email_message'],
                'data' => $payload['data'],
            ]);

            $willEmail = $emailAllowed && $payload['email_subject'] && $payload['email_message'];

            if ($willEmail || $pushAllowed) {
                $notification->userNotificationId = $record->id;

                try {
                    $user->notify($notification);

                    if ($willEmail) {
                        $record->forceFill([
                            'emailed_at' => now(),
                        ])->save();
                    }
                } catch (Throwable $exception) {
                    $this->logEmailFailure(
                        description: $exception->getMessage(),
                        templateKey: $template->key,
                        properties: [
                            'user_id' => $user->id,
                            'user_notification_id' => $record->id,
                        ],
                    );
                }
            }

            return $record;
        });
    }

    private function preferencesUrl(): string
    {
        return rtrim((string) config('app.frontend_url', config('app.url')), '/').'/account/settings#notifications';
    }

    /**
     * Sends to every user holding the given permission, falling back to a
     * single email address (e.g. from Config) when no such user exists yet.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendToPermission(string $permission, string|NotificationTemplate $template, array $data = [], ?string $fallbackEmail = null): void
    {
        $recipients = User::query()->permission($permission)->get();

        if ($recipients->isEmpty()) {
            if (filled($fallbackEmail)) {
                $this->sendEmailOnly($fallbackEmail, $template, $data);
            }

            return;
        }

        foreach ($recipients as $recipient) {
            $this->send($recipient, $template, $data);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function sendByName(User $user, string $templateName, array $data = []): ?UserNotification
    {
        $template = NotificationTemplate::query()
            ->where('name', $templateName)
            ->where('is_active', true)
            ->first();

        if (! $template) {
            return null;
        }

        return $this->send($user, $template, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function sendByAction(User $user, string $action, array $data = []): ?UserNotification
    {
        return $this->send($user, $action, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function sendEmailOnly(string $email, string|NotificationTemplate $template, array $data = [], ?string $replyToEmail = null, ?string $replyToName = null): bool
    {
        $template = $this->resolveTemplate($template);

        if (! $template) {
            return false;
        }

        $notification = $this->buildNotification($template, $data, replyToEmail: $replyToEmail, replyToName: $replyToName);

        if (! $notification) {
            return false;
        }

        $payload = $notification->toUserNotificationData();

        if (! $payload['email_subject'] || ! $payload['email_message']) {
            return false;
        }

        try {
            Notification::route('mail', $email)->notify($notification);

            return true;
        } catch (Throwable $exception) {
            $this->logEmailFailure(
                description: $exception->getMessage(),
                templateKey: $template->key,
                properties: [
                    'email' => $email,
                ],
            );

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function sendEmailByAction(string $email, string $action, array $data = []): bool
    {
        return $this->sendEmailOnly($email, $action, $data);
    }

    private function resolveTemplate(string|NotificationTemplate $template): ?NotificationTemplate
    {
        if ($template instanceof NotificationTemplate) {
            return $template->is_active ? $template : null;
        }

        return NotificationTemplate::query()
            ->where('key', $template)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildNotification(NotificationTemplate $template, array $data, ?string $preferencesUrl = null, bool $emailAllowed = true, bool $pushAllowed = false, ?string $replyToEmail = null, ?string $replyToName = null): ?TemplatedNotification
    {
        try {
            $batchedCount = $this->batchedCount($data);

            return new TemplatedNotification(
                notificationTitle: $this->renderString($template->notification_title, $data),
                notificationBody: $this->withBatchedBodySuffix(
                    $this->renderString($template->notification_body, $data),
                    $template->notification_body,
                    $batchedCount,
                ),
                emailSubject: filled($template->email_subject)
                    ? $this->renderString($template->email_subject, $data)
                    : null,
                emailMessage: filled($template->email_message)
                    ? $this->withBatchedItemsList(
                        $this->renderString($template->email_message, $data),
                        $template->email_message,
                        $batchedCount,
                        $data,
                    )
                    : null,
                data: $data,
                preferencesUrl: $preferencesUrl,
                emailAllowed: $emailAllowed,
                pushAllowed: $pushAllowed,
                replyToEmail: $replyToEmail,
                replyToName: $replyToName,
            );
        } catch (Throwable $exception) {
            $this->logRenderFailure(
                description: $exception->getMessage(),
                templateKey: $template->key,
                data: $data,
            );

            return null;
        }
    }

    /**
     * Number of events a FlushQueuedNotifications summary stands for (it adds
     * count and the Htmlable items list), 0 for an ordinary notification.
     *
     * @param  array<string, mixed>  $data
     */
    private function batchedCount(array $data): int
    {
        return ($data['items'] ?? null) instanceof Htmlable ? (int) ($data['count'] ?? 0) : 0;
    }

    /**
     * A template written for a single event names only the last of several
     * batched ones, so the list of all of them is appended to the e-mail.
     *
     * @param  array<string, mixed>  $data
     */
    private function withBatchedItemsList(string $rendered, string $template, int $batchedCount, array $data): string
    {
        if ($batchedCount < 2 || $this->templateUses($template, 'items') || $this->templateUses($template, 'count')) {
            return $rendered;
        }

        // The blank line makes the list an HTML block of its own in the
        // Markdown mail, whatever the template ends with.
        return $rendered
            ."\n\n<p>This message summarizes ".$batchedCount." notifications:</p>\n"
            .$data['items']->toHtml();
    }

    private function withBatchedBodySuffix(string $rendered, string $template, int $batchedCount): string
    {
        if ($batchedCount < 2 || $this->templateUses($template, 'items') || $this->templateUses($template, 'count')) {
            return $rendered;
        }

        return $rendered.' (+'.($batchedCount - 1).' more)';
    }

    private function templateUses(string $template, string $variable): bool
    {
        return preg_match('/{{\s*'.preg_quote($variable, '/').'\s*}}/', $template) === 1;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderString(string $template, array $data): string
    {
        $rendered = preg_replace_callback(self::TEMPLATE_VARIABLE_PATTERN, function (array $matches) use ($data): string {
            $key = $matches[1];

            if (! Arr::has($data, $key)) {
                throw new RuntimeException("Missing notification template variable [{$key}].");
            }

            $value = Arr::get($data, $key);

            if ($value instanceof Htmlable) {
                return $value->toHtml();
            }

            if (is_array($value) || is_object($value)) {
                throw new RuntimeException("Notification template variable [{$key}] is not scalar.");
            }

            return e((string) $value);
        }, $template) ?? '';

        if (preg_match('/{{.*?}}/', $rendered, $matches) === 1) {
            throw new RuntimeException("Unresolved notification template variable [{$matches[0]}].");
        }

        return $rendered;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function logRenderFailure(string $description, ?string $templateKey, array $data): void
    {
        activity(BaseModel::ACTIVITY_LOG_SYSTEM)
            ->event(self::EVENT_TEMPLATE_RENDER_FAILED)
            ->withProperties([
                'template_key' => $templateKey,
                'data_keys' => array_keys(Arr::dot($data)),
            ])
            ->log(Str::limit($description, 1000, '...'));

        $this->alertNotificationFailure($description, $templateKey);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logEmailFailure(string $description, ?string $templateKey, array $properties = []): void
    {
        activity(BaseModel::ACTIVITY_LOG_SYSTEM)
            ->event(self::EVENT_EMAIL_FAILED)
            ->withProperties([
                ...$properties,
                'template_key' => $templateKey,
            ])
            ->log(Str::limit($description, 1000, '...'));

        $this->alertNotificationFailure($description, $templateKey);
    }

    /**
     * Best-effort meta-alert for admins when the notification system itself
     * is failing. Guards against recursion (a failure of this very alert
     * must never re-trigger itself) and never lets a secondary failure here
     * propagate — the original failure is already recorded in the activity
     * log above regardless of whether this alert gets through.
     */
    private function alertNotificationFailure(string $description, ?string $templateKey): void
    {
        if ($templateKey === NotificationType::SYSTEM_ADMIN_NOTIFICATION_FAILURE->value) {
            return;
        }

        try {
            $this->sendToPermission(
                PermissionEnums::SYSTEM_MONITOR->value,
                NotificationType::SYSTEM_ADMIN_NOTIFICATION_FAILURE->value,
                [
                    'template_key' => $templateKey ?? 'unknown',
                    'error' => $description,
                ],
            );
        } catch (Throwable) {
            // Swallowed deliberately — see docblock above.
        }
    }
}
