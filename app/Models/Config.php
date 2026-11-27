<?php

namespace App\Models;

use Database\Factories\ConfigFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Config extends Model
{
    /** @use HasFactory<ConfigFactory> */
    use HasFactory;

    public const KEY_FEEDBACK_EMAIL_FALLBACK = 'feedback:email_fallback';

    public const KEY_LAB_UPLOAD_ADMIN_EMAIL_FALLBACK = 'lab_upload:admin_email_fallback';

    public const KEY_LAB_UPLOAD_ADMIN_DIGEST_LAST_SENT_AT = 'lab_upload:admin_digest_last_sent_at';

    /** State of the prediction server alert (CheckPredictionServerHealth), JSON; kept here as cache:clear runs on every start. */
    public const KEY_PREDICTION_SERVER_HEALTH_ALERT = 'prediction_server:health_alert';

    public const KEY_PREDICTION_ADMIN_EMAIL_FALLBACK = 'prediction_admin:email_fallback';

    public const KEY_REMOTE_PREDICTION_ENABLED = 'remote_prediction:enabled';

    public const KEY_REMOTE_PREDICTION_URL = 'remote_prediction:url';

    public const KEY_REMOTE_PREDICTION_TOKEN = 'remote_prediction:token';

    public const KEY_REMOTE_PREDICTION_TOKEN_ID = 'remote_prediction:token_id';

    public const KEY_REMOTE_PREDICTION_TOKEN_EXPIRES_AT = 'remote_prediction:token_expires_at';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key' => 'string',
            'value' => 'string',
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (! Schema::hasTable('configs')) {
            return $default;
        }

        return static::where('key', $key)->value('value') ?? $default;
    }

    public static function boolean(string $key, bool $default = false): bool
    {
        $value = static::get($key, $default ? '1' : '0');

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    public static function set(string $key, ?string $value): bool
    {
        if (! Schema::hasTable('configs')) {
            return false;
        }

        return (bool) static::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    public static function isSensitiveKey(string $key): bool
    {
        return in_array($key, [self::KEY_REMOTE_PREDICTION_TOKEN], true);
    }
}
