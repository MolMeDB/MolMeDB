<?php

namespace App\Models;

use App\Notifications\TemplatedNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserNotification extends Model
{
    use SoftDeletes;

    public const STATE_NEW = 'new';

    public const STATE_READ = 'read';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'emailed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'notification_template_id');
    }

    /**
     * The page the notification is about (a dataset, a prediction job, the
     * administration), if any.
     */
    public function relatedUrl(): ?string
    {
        return TemplatedNotification::relatedUrl($this->data ?? []);
    }

    /**
     * Its own page in the frontend, where the whole message can be read.
     */
    public function frontendUrl(): string
    {
        return self::frontendUrlFor($this->id);
    }

    public static function frontendUrlFor(int $id): string
    {
        return rtrim((string) config('app.frontend_url', config('app.url')), '/').'/account/notifications/'.$id;
    }

    public function markAsRead(): void
    {
        if ($this->state === self::STATE_READ) {
            return;
        }

        $this->forceFill([
            'state' => self::STATE_READ,
            'read_at' => now(),
        ])->save();
    }
}
