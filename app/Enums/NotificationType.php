<?php

namespace App\Enums;

use App\Models\NotificationTemplate;
use App\Models\User;

/**
 * Preference/gating metadata for every notification a user can receive.
 *
 * Values match `NotificationTemplate::KEY_*` — this enum does not duplicate
 * template content, it only adds who is eligible, which channels are on by
 * default, and how the notification is delivered (right away, or batched to
 * avoid flooding a recipient with many similar events at once).
 */
enum NotificationType: string
{
    // User-facing
    case UPLOAD_RECEIVED = 'upload.received';
    case UPLOAD_STATUS_UPDATE = 'upload.status_update';
    case UPLOAD_JOB_FINISHED = 'upload.job.finished';
    case PREDICTION_JOB_SUBMITTED = 'prediction.job.submitted';
    case PREDICTION_JOB_FINISHED = 'prediction.job.finished';
    case PREDICTION_JOB_FINISHED_WITH_ERRORS = 'prediction.job.finished_with_errors';
    case PREDICTION_JOB_DAILY_PROGRESS = 'prediction.job.daily_progress';
    case FEEDBACK_ACCEPTED = 'feedback.accepted';
    case EXPORT_READY = 'export.ready';
    case EXPORT_FAILED = 'export.failed';
    case ACCOUNT_ROLE_CHANGED = 'account.role_changed';

    // Admin-facing
    case UPLOAD_ADMIN_NEW_SUBMISSION = 'upload.admin.new_submission';
    case UPLOAD_ADMIN_REVIEW_REQUIRED = 'upload.admin.review_required';
    case UPLOAD_ADMIN_PROCESSING_ERROR = 'upload.admin.processing_error';
    case UPLOAD_ADMIN_DIGEST = 'upload.admin.digest';
    case PREDICTION_ADMIN_NEW_SUBMISSION = 'prediction.admin.new_submission';
    case PREDICTION_ADMIN_STATS_REPORT = 'prediction.admin.stats_report';
    case PREDICTION_ADMIN_REMOTE_SERVICE_DOWN = 'prediction.admin.remote_service_down';
    case FEEDBACK_ADMIN_NEW_SUBMISSION = 'feedback.admin.new_submission';
    case SYSTEM_ADMIN_BACKUP_FAILED = 'system.admin.backup_failed';
    case SYSTEM_ADMIN_JOB_FAILED = 'system.admin.job_failed';
    case SYSTEM_ADMIN_NOTIFICATION_FAILURE = 'system.admin.notification_failure';
    case SYSTEM_ADMIN_COSMO_EXPORT_FINISHED = 'system.admin.cosmo_export_finished';
    case SYSTEM_ADMIN_COSMO_EXPORT_FAILED = 'system.admin.cosmo_export_failed';

    /**
     * @return array<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * Types the given user is allowed to see/receive: user-audience types
     * always qualify, admin-audience types require the gating permission.
     *
     * @return array<self>
     */
    public static function eligibleFor(User $user): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $type): bool => $type->isEligible($user),
        ));
    }

    public function isEligible(User $user): bool
    {
        $permission = $this->permission();

        return $permission === null || $user->hasPermissionTo($permission->value);
    }

    public function audience(): NotificationAudience
    {
        return match ($this) {
            self::UPLOAD_RECEIVED,
            self::UPLOAD_STATUS_UPDATE,
            self::UPLOAD_JOB_FINISHED,
            self::PREDICTION_JOB_SUBMITTED,
            self::PREDICTION_JOB_FINISHED,
            self::PREDICTION_JOB_FINISHED_WITH_ERRORS,
            self::PREDICTION_JOB_DAILY_PROGRESS,
            self::FEEDBACK_ACCEPTED,
            self::EXPORT_READY,
            self::EXPORT_FAILED,
            self::ACCOUNT_ROLE_CHANGED => NotificationAudience::USER,

            self::UPLOAD_ADMIN_NEW_SUBMISSION,
            self::UPLOAD_ADMIN_REVIEW_REQUIRED,
            self::UPLOAD_ADMIN_PROCESSING_ERROR,
            self::UPLOAD_ADMIN_DIGEST,
            self::PREDICTION_ADMIN_NEW_SUBMISSION,
            self::PREDICTION_ADMIN_STATS_REPORT,
            self::PREDICTION_ADMIN_REMOTE_SERVICE_DOWN,
            self::FEEDBACK_ADMIN_NEW_SUBMISSION,
            self::SYSTEM_ADMIN_BACKUP_FAILED,
            self::SYSTEM_ADMIN_JOB_FAILED,
            self::SYSTEM_ADMIN_NOTIFICATION_FAILURE,
            self::SYSTEM_ADMIN_COSMO_EXPORT_FINISHED,
            self::SYSTEM_ADMIN_COSMO_EXPORT_FAILED => NotificationAudience::ADMIN,
        };
    }

    /**
     * Permission required to be eligible for this type. Null means every
     * authenticated user qualifies (still subject to their own preference).
     */
    public function permission(): ?PermissionEnums
    {
        return match ($this) {
            self::UPLOAD_ADMIN_NEW_SUBMISSION,
            self::UPLOAD_ADMIN_REVIEW_REQUIRED,
            self::UPLOAD_ADMIN_PROCESSING_ERROR,
            self::UPLOAD_ADMIN_DIGEST => PermissionEnums::UPLOAD_QUEUE_MANAGE_ALL,

            self::PREDICTION_ADMIN_NEW_SUBMISSION,
            self::PREDICTION_ADMIN_STATS_REPORT,
            self::PREDICTION_ADMIN_REMOTE_SERVICE_DOWN => PermissionEnums::PREDICTION_DATASET_MANAGE_ALL,

            self::FEEDBACK_ADMIN_NEW_SUBMISSION => PermissionEnums::ADMIN_PANEL,

            self::SYSTEM_ADMIN_BACKUP_FAILED,
            self::SYSTEM_ADMIN_JOB_FAILED,
            self::SYSTEM_ADMIN_NOTIFICATION_FAILURE,
            self::SYSTEM_ADMIN_COSMO_EXPORT_FINISHED,
            self::SYSTEM_ADMIN_COSMO_EXPORT_FAILED => PermissionEnums::SYSTEM_MONITOR,

            default => null,
        };
    }

    public function defaultEmail(): bool
    {
        return match ($this) {
            self::PREDICTION_JOB_SUBMITTED,
            self::PREDICTION_JOB_DAILY_PROGRESS,
            self::FEEDBACK_ACCEPTED,
            self::UPLOAD_ADMIN_NEW_SUBMISSION,
            self::PREDICTION_ADMIN_NEW_SUBMISSION,
            self::PREDICTION_ADMIN_STATS_REPORT,
            self::SYSTEM_ADMIN_JOB_FAILED => false,
            default => true,
        };
    }

    /**
     * Push is always opt-in: it requires an extra, explicit step (granting
     * the browser notification permission), so no type starts pushed.
     */
    public function defaultPush(): bool
    {
        return false;
    }

    public function deliveryMode(): NotificationDeliveryMode
    {
        return match ($this) {
            self::ACCOUNT_ROLE_CHANGED,
            self::UPLOAD_RECEIVED,
            self::SYSTEM_ADMIN_BACKUP_FAILED,
            self::SYSTEM_ADMIN_NOTIFICATION_FAILURE,
            self::SYSTEM_ADMIN_COSMO_EXPORT_FINISHED,
            self::SYSTEM_ADMIN_COSMO_EXPORT_FAILED,
            self::PREDICTION_ADMIN_REMOTE_SERVICE_DOWN,
            self::FEEDBACK_ADMIN_NEW_SUBMISSION => NotificationDeliveryMode::IMMEDIATE,
            default => NotificationDeliveryMode::BATCHED,
        };
    }

    /**
     * Only meaningful when deliveryMode() is BATCHED.
     */
    public function batchWindowMinutes(): int
    {
        return match ($this) {
            self::UPLOAD_ADMIN_NEW_SUBMISSION,
            self::UPLOAD_ADMIN_REVIEW_REQUIRED,
            self::UPLOAD_ADMIN_PROCESSING_ERROR => 120,
            default => 10,
        };
    }

    /**
     * Grouping label for the notification preferences UI.
     */
    public function group(): string
    {
        return match ($this) {
            self::UPLOAD_RECEIVED,
            self::UPLOAD_STATUS_UPDATE,
            self::UPLOAD_JOB_FINISHED => 'Lab uploads',

            self::PREDICTION_JOB_SUBMITTED,
            self::PREDICTION_JOB_FINISHED,
            self::PREDICTION_JOB_FINISHED_WITH_ERRORS,
            self::PREDICTION_JOB_DAILY_PROGRESS => 'Predictions',

            self::FEEDBACK_ACCEPTED => 'Feedback',
            self::EXPORT_READY,
            self::EXPORT_FAILED => 'Exports',
            self::ACCOUNT_ROLE_CHANGED => 'Account',

            self::UPLOAD_ADMIN_NEW_SUBMISSION,
            self::UPLOAD_ADMIN_REVIEW_REQUIRED,
            self::UPLOAD_ADMIN_PROCESSING_ERROR,
            self::UPLOAD_ADMIN_DIGEST => 'Administration – lab uploads',

            self::PREDICTION_ADMIN_NEW_SUBMISSION,
            self::PREDICTION_ADMIN_STATS_REPORT,
            self::PREDICTION_ADMIN_REMOTE_SERVICE_DOWN => 'Administration – predictions',

            self::FEEDBACK_ADMIN_NEW_SUBMISSION => 'Administration – feedback',

            self::SYSTEM_ADMIN_BACKUP_FAILED,
            self::SYSTEM_ADMIN_JOB_FAILED,
            self::SYSTEM_ADMIN_NOTIFICATION_FAILURE,
            self::SYSTEM_ADMIN_COSMO_EXPORT_FINISHED,
            self::SYSTEM_ADMIN_COSMO_EXPORT_FAILED => 'Administration – system health',
        };
    }

    /**
     * Human label for the preferences UI. Falls back to the matching
     * NotificationTemplate name when one exists.
     */
    public function label(): string
    {
        return NotificationTemplate::keyOptions()[$this->value] ?? match ($this) {
            self::PREDICTION_JOB_FINISHED_WITH_ERRORS => 'Prediction job finished with errors',
            self::EXPORT_READY => 'Export ready for download',
            self::EXPORT_FAILED => 'Export failed',
            self::ACCOUNT_ROLE_CHANGED => 'Your role was changed',
            self::PREDICTION_ADMIN_REMOTE_SERVICE_DOWN => 'Prediction admin: remote service unavailable',
            self::FEEDBACK_ADMIN_NEW_SUBMISSION => 'Feedback admin: new submission',
            self::SYSTEM_ADMIN_BACKUP_FAILED => 'System admin: backup failed',
            self::SYSTEM_ADMIN_JOB_FAILED => 'System admin: queue job or scheduled task failed',
            self::SYSTEM_ADMIN_NOTIFICATION_FAILURE => 'System admin: notification delivery failure',
            default => $this->value,
        };
    }
}
