<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationTemplate extends Model
{
    public const KEY_FEEDBACK_ACCEPTED = 'feedback.accepted';

    public const KEY_UPLOAD_JOB_FINISHED = 'upload.job.finished';

    public const KEY_UPLOAD_RECEIVED = 'upload.received';

    public const KEY_UPLOAD_STATUS_UPDATE = 'upload.status_update';

    public const KEY_UPLOAD_ADMIN_NEW_SUBMISSION = 'upload.admin.new_submission';

    public const KEY_UPLOAD_ADMIN_REVIEW_REQUIRED = 'upload.admin.review_required';

    public const KEY_UPLOAD_ADMIN_PROCESSING_ERROR = 'upload.admin.processing_error';

    public const KEY_UPLOAD_ADMIN_DIGEST = 'upload.admin.digest';

    public const KEY_PREDICTION_JOB_SUBMITTED = 'prediction.job.submitted';

    public const KEY_PREDICTION_JOB_FINISHED = 'prediction.job.finished';

    public const KEY_PREDICTION_JOB_FINISHED_WITH_ERRORS = 'prediction.job.finished_with_errors';

    public const KEY_PREDICTION_JOB_DAILY_PROGRESS = 'prediction.job.daily_progress';

    public const KEY_PREDICTION_ADMIN_NEW_SUBMISSION = 'prediction.admin.new_submission';

    public const KEY_PREDICTION_ADMIN_STATS_REPORT = 'prediction.admin.stats_report';

    public const KEY_EXPORT_READY = 'export.ready';

    public const KEY_EXPORT_FAILED = 'export.failed';

    public const KEY_ACCOUNT_ROLE_CHANGED = 'account.role_changed';

    public const KEY_FEEDBACK_ADMIN_NEW_SUBMISSION = 'feedback.admin.new_submission';

    public const KEY_SYSTEM_ADMIN_BACKUP_FAILED = 'system.admin.backup_failed';

    public const KEY_SYSTEM_ADMIN_JOB_FAILED = 'system.admin.job_failed';

    public const KEY_SYSTEM_ADMIN_NOTIFICATION_FAILURE = 'system.admin.notification_failure';

    public const KEY_SYSTEM_ADMIN_COSMO_EXPORT_FINISHED = 'system.admin.cosmo_export_finished';

    public const KEY_SYSTEM_ADMIN_COSMO_EXPORT_FAILED = 'system.admin.cosmo_export_failed';

    public const KEY_PREDICTION_ADMIN_REMOTE_SERVICE_DOWN = 'prediction.admin.remote_service_down';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    public static function keyOptions(): array
    {
        return [
            self::KEY_FEEDBACK_ACCEPTED => 'Feedback accepted',
            self::KEY_UPLOAD_JOB_FINISHED => 'Upload job finished',
            self::KEY_UPLOAD_RECEIVED => 'Upload received (sent to uploader)',
            self::KEY_UPLOAD_STATUS_UPDATE => 'Upload status update (sent to uploader)',
            self::KEY_UPLOAD_ADMIN_NEW_SUBMISSION => 'Upload admin alert: new submission',
            self::KEY_UPLOAD_ADMIN_REVIEW_REQUIRED => 'Upload admin alert: review required',
            self::KEY_UPLOAD_ADMIN_PROCESSING_ERROR => 'Upload admin alert: processing error',
            self::KEY_UPLOAD_ADMIN_DIGEST => 'Upload admin digest (throttled summary, max once per 2 hours)',
            self::KEY_PREDICTION_JOB_SUBMITTED => 'Prediction job submitted',
            self::KEY_PREDICTION_JOB_FINISHED => 'Prediction job finished',
            self::KEY_PREDICTION_JOB_FINISHED_WITH_ERRORS => 'Prediction job finished with errors',
            self::KEY_PREDICTION_JOB_DAILY_PROGRESS => 'Prediction job daily progress',
            self::KEY_PREDICTION_ADMIN_NEW_SUBMISSION => 'Prediction admin: new submission',
            self::KEY_PREDICTION_ADMIN_STATS_REPORT => 'Prediction admin: statistics report',
            self::KEY_EXPORT_READY => 'Export ready for download',
            self::KEY_EXPORT_FAILED => 'Export failed',
            self::KEY_ACCOUNT_ROLE_CHANGED => 'Your role was changed',
            self::KEY_FEEDBACK_ADMIN_NEW_SUBMISSION => 'Feedback admin: new submission',
            self::KEY_SYSTEM_ADMIN_BACKUP_FAILED => 'System admin: backup failed',
            self::KEY_SYSTEM_ADMIN_JOB_FAILED => 'System admin: queue job or scheduled task failed',
            self::KEY_SYSTEM_ADMIN_NOTIFICATION_FAILURE => 'System admin: notification delivery failure',
            self::KEY_SYSTEM_ADMIN_COSMO_EXPORT_FINISHED => 'System admin: weekly COSMO export finished',
            self::KEY_SYSTEM_ADMIN_COSMO_EXPORT_FAILED => 'System admin: weekly COSMO export failed',
            self::KEY_PREDICTION_ADMIN_REMOTE_SERVICE_DOWN => 'Prediction admin: remote service unavailable',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function availableKeyOptions(?self $currentTemplate = null): array
    {
        $usedKeys = self::query()
            ->when($currentTemplate, fn ($query) => $query->whereKeyNot($currentTemplate->getKey()))
            ->pluck('key')
            ->all();

        return collect(self::keyOptions())
            ->reject(fn (string $_label, string $key): bool => in_array($key, $usedKeys, true))
            ->all();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }
}
