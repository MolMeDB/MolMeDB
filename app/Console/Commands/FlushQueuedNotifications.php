<?php

namespace App\Console\Commands;

use App\Enums\NotificationDeliveryMode;
use App\Enums\NotificationType;
use App\Models\QueuedNotification;
use App\Models\User;
use App\Services\NotificationBatcher;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Drains batched NotificationType queues (see NotificationBatcher::queue()):
 * for every recipient with pending events of a batched type whose oldest
 * event is older than that type's batch window, sends one notification —
 * the original single-event payload when there's only one, or an aggregated
 * "N events" version with a generic per-item list when there are several.
 */
class FlushQueuedNotifications extends Command
{
    protected $signature = 'notifications:flush-queued';

    protected $description = 'Sends batched notifications whose window has elapsed, clustering same-type events for the same recipient into one message.';

    public function handle(NotificationService $notificationService): int
    {
        $batchedTypes = collect(NotificationType::cases())
            ->filter(fn (NotificationType $type): bool => $type->deliveryMode() === NotificationDeliveryMode::BATCHED);

        foreach ($batchedTypes as $type) {
            $this->flushType($type, $notificationService);
        }

        return self::SUCCESS;
    }

    private function flushType(NotificationType $type, NotificationService $notificationService): void
    {
        $groupKey = NotificationBatcher::groupKey($type);
        $userMorphClass = (new User)->getMorphClass();
        $cutoff = now()->subMinutes($type->batchWindowMinutes());

        $recipientIds = QueuedNotification::query()
            ->forGroup($groupKey)
            ->pending()
            ->where('notifiable_type', $userMorphClass)
            ->where('created_at', '<=', $cutoff)
            ->distinct()
            ->pluck('notifiable_id');

        foreach ($recipientIds as $recipientId) {
            $this->flushRecipient($type, $groupKey, (int) $recipientId, $userMorphClass, $notificationService);
        }
    }

    private function flushRecipient(NotificationType $type, string $groupKey, int $recipientId, string $userMorphClass, NotificationService $notificationService): void
    {
        $items = QueuedNotification::query()
            ->forGroup($groupKey)
            ->pending()
            ->where('notifiable_type', $userMorphClass)
            ->where('notifiable_id', $recipientId)
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $user = User::find($recipientId);

        if ($user) {
            // Always add count/items alongside the latest item's own fields —
            // even for a single queued event — so a template can rely on
            // either the per-item fields (older, singular-only templates)
            // or {{ count }}/{{ items }} (templates written to summarize a
            // cluster) regardless of how many events ended up batched.
            $data = [
                ...$items->last()->data ?? [],
                'count' => $items->count(),
                'items' => self::formatItemsList($items),
            ];

            $notificationService->send($user, $type->value, $data, skipBatching: true);
        }

        QueuedNotification::query()->whereIn('id', $items->pluck('id'))->update(['notified_at' => now()]);
    }

    /**
     * Generic one-line-per-event summary for the aggregated case, since a
     * batched type can be triggered by many different kinds of records.
     *
     * @param  Collection<int, QueuedNotification>  $items
     */
    public static function formatItemsList(Collection $items): HtmlString
    {
        $rows = $items
            ->map(function (QueuedNotification $item): string {
                $data = $item->data ?? [];
                // notifiable_id is the recipient, so it cannot label the event.
                $label = collect([$data['dataset_name'] ?? null, $data['comment'] ?? null, $data['label'] ?? null])->first(fn (mixed $value): bool => filled($value))
                    ?? (isset($data['record_id']) ? '#'.$data['record_id'] : (NotificationType::tryFrom((string) $item->event)?->label() ?? (string) $item->event));
                $url = $data['manage_url'] ?? $data['dataset_url'] ?? $data['admin_url'] ?? null;

                $content = $url ? sprintf('<a href="%s">%s</a>', e($url), e($label)) : e($label);

                return "<li>{$content}</li>";
            })
            ->implode('');

        return new HtmlString('<ul style="padding-left:20px;margin:8px 0;">'.$rows.'</ul>');
    }
}
