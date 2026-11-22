<?php

namespace App\Models;

use App\Casts\UploadQueueConfigCasts;
use App\Casts\UploadQueueLogCasts;
use App\Enums\PermissionEnums;
use App\Enums\UploadQueueLogContextEnums;
use App\Enums\UploadQueueLogTypeEnums;
use App\Jobs\SendUploadQueueStatusUpdate;
use App\Observers\UploadQueueObserver;
use App\ValueObjects\UploadQueueLog;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Rdkit\Rdkit;

#[ObservedBy([UploadQueueObserver::class])]
class UploadQueue extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $table = 'upload_queue';

    protected static $disk = null;

    protected function casts(): array
    {
        return [
            'config' => UploadQueueConfigCasts::class,
            'logs' => UploadQueueLogCasts::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    const TYPE_PASSIVE_DATASET = Dataset::TYPE_PASSIVE;

    const TYPE_ACTIVE_DATASET = Dataset::TYPE_ACTIVE;
    // const TYPE_ENERGY = 3;

    private static $enum_types =
        [
            self::TYPE_PASSIVE_DATASET => 'Passive interactions',
            self::TYPE_ACTIVE_DATASET => 'Active interactions',
            // self::TYPE_ENERGY => 'Energy',
        ];

    public static function enumType($type = null)
    {
        if ($type === null) {
            return self::$enum_types;
        }

        if (array_key_exists($type, self::$enum_types)) {
            return self::$enum_types[$type];
        }

        return null;
    }

    public static function disk(): ?string
    {
        if (! self::$disk) {
            self::$disk = Filesystem::where('type', Filesystem::TYPE_UPLOAD_STORAGE)->first()?->systemName;
        }

        return self::$disk;
    }

    public static function typeFolder($type): ?string
    {
        return match ($type) {
            self::TYPE_PASSIVE_DATASET => 'upload_queue/passive',
            self::TYPE_ACTIVE_DATASET => 'upload_queue/active',
            default => null,
        };
    }

    /** STATES */
    const STATE_UPLOADED = -2;

    const STATE_CONFIGURED = -1;

    const STATE_REVIEW_REQUIRED = 50;

    const STATE_PENDING = 0;

    const STATE_RUNNING = 1;

    const STATE_DONE = 2;

    const STATE_ERROR = 3;

    const STATE_CANCELED = 4;

    /**
     * Enum states
     */
    public static $enum_states =
        [
            self::STATE_UPLOADED => 'Uploaded',
            self::STATE_CONFIGURED => 'Configured',
            self::STATE_REVIEW_REQUIRED => 'Review required',
            self::STATE_PENDING => 'Pending',
            self::STATE_RUNNING => 'Running',
            self::STATE_DONE => 'Done',
            self::STATE_ERROR => 'Error',
            self::STATE_CANCELED => 'Canceled',
        ];

    public static $ui_enum_states =
        [
            self::STATE_UPLOADED => 'Configuration required',
            self::STATE_CONFIGURED => 'Ready to start upload',
            self::STATE_REVIEW_REQUIRED => 'Waiting for validation',
            self::STATE_PENDING => 'Pending upload',
            self::STATE_RUNNING => 'Running',
            self::STATE_DONE => 'Finished',
            self::STATE_ERROR => 'Validation error',
            self::STATE_CANCELED => 'Canceled',
        ];

    public static function enumState($state = null)
    {
        if ($state === null) {
            return self::$enum_states;
        }

        if (array_key_exists($state, self::$enum_states)) {
            return self::$enum_states[$state];
        }

        return null;
    }

    public static function canBeAddedNewRecords(): bool
    {
        $rdkit = new Rdkit;

        return $rdkit->is_connected();
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function interactionsPassive(): HasMany
    {
        return $this->hasMany(InteractionPassive::class, 'dataset_id', 'dataset_id');
    }

    public function interactionsActive(): HasMany
    {
        return $this->hasMany(InteractionActive::class, 'dataset_id', 'dataset_id');
    }

    public function isRevertible(): bool
    {
        return $this->state === self::STATE_DONE;
    }

    public function isDeletable(): bool
    {
        return $this->state === self::STATE_UPLOADED ||
            $this->state == self::STATE_CONFIGURED ||
            $this->state === self::STATE_REVIEW_REQUIRED;
    }

    public function isFinished(): bool
    {
        return $this->state === self::STATE_DONE;
    }

    public function isCancelable(): bool
    {
        return $this->state === self::STATE_PENDING ||
            $this->state === self::STATE_RUNNING ||
            $this->state === self::STATE_REVIEW_REQUIRED;
    }

    public function isEditableConfig(): bool
    {
        return $this->state === self::STATE_UPLOADED ||
            $this->state === self::STATE_CONFIGURED;
    }

    public function isReadyToStart(): bool
    {
        return $this->canBeEnqueued();
    }

    public function canBeReuploaded(): bool
    {
        return $this->state === self::STATE_ERROR;
    }

    public function canBeConfigured(): bool
    {
        return in_array(
            $this->state,
            [
                self::STATE_UPLOADED,
                self::STATE_ERROR,
                self::STATE_CONFIGURED,
            ],
            true
        );
    }

    public function canBeEnqueued(): bool
    {
        return $this->state === self::STATE_CONFIGURED &&
            $this->hasValidConfig() &&
            $this->config->quickValidationPassed();
    }

    public function canBeRevertedToConfigState(): bool
    {
        return $this->state === self::STATE_PENDING ||
            $this->state === self::STATE_REVIEW_REQUIRED;
    }

    public function canBeCanceled(): bool
    {
        return in_array($this->state, [
            self::STATE_UPLOADED,
            self::STATE_ERROR,
            self::STATE_CONFIGURED,
            self::STATE_PENDING,
        ], true);
    }

    public function isAccessibleBy(?User $user, ?string $guestToken): bool
    {
        if ($user) {
            return $user->hasAdminRole()
                || $this->user_id === $user->id
                || $user->hasPermissionTo(PermissionEnums::UPLOAD_QUEUE_MANAGE_ALL);
        }

        return $guestToken !== null
            && $this->guest_token !== null
            && hash_equals($this->guest_token, $guestToken);
    }

    public function trackingUrl(): string
    {
        $url = rtrim((string) config('app.frontend_url'), '/').'/lab/upload';

        return $this->guest_token ? $url.'?token='.$this->guest_token : $url;
    }

    public function lastNotifiedAt(): ?string
    {
        return $this->logs
            ->filter(fn (UploadQueueLog $log) => $log->type === UploadQueueLogTypeEnums::NOTIFICATION)
            ->last()
            ?->timestamp;
    }

    /**
     * @return Collection<int, UploadQueueLog>
     */
    public function unnotifiedLogs(): Collection
    {
        $lastNotifiedAt = $this->lastNotifiedAt();

        return $this->logs
            ->filter(function (UploadQueueLog $log) use ($lastNotifiedAt): bool {
                if ($log->type === UploadQueueLogTypeEnums::NOTIFICATION) {
                    return false;
                }

                if ($lastNotifiedAt === null) {
                    return true;
                }

                return $log->timestamp !== null
                    && Carbon::parse($log->timestamp)->gt(Carbon::parse($lastNotifiedAt));
            })
            ->values();
    }

    public function canDeleteUploadedFile(?User $user, ?string $guestToken = null): bool
    {
        return $this->isAccessibleBy($user, $guestToken) && $this->canBeCanceled();
    }

    /**
     * @return array{file_id: int|null, file_name: string|null, file_deleted: bool}
     *
     * @throws AuthorizationException
     */
    public function deleteUploadedFileAndCancel(?User $user, ?string $guestToken = null): array
    {
        if (! $this->isAccessibleBy($user, $guestToken)) {
            throw new AuthorizationException('Only the upload owner can delete this upload.');
        }

        if ((int) $this->state === self::STATE_CANCELED) {
            throw ValidationException::withMessages([
                'record' => 'Record is already marked for deletion.',
            ]);
        }

        if ((int) $this->state === self::STATE_RUNNING) {
            throw ValidationException::withMessages([
                'record' => 'Running records cannot be canceled right now.',
            ]);
        }

        if (! $this->canBeCanceled()) {
            throw ValidationException::withMessages([
                'record' => 'This record cannot be canceled in current state.',
            ]);
        }

        $file = $this->file;
        $fileDeleted = false;

        if ($file) {
            $disk = is_string($file->storage) && trim($file->storage) !== '' ? $file->storage : null;
            if (! $disk) {
                throw ValidationException::withMessages([
                    'record' => 'Uploaded file storage is not configured.',
                ]);
            }

            if (Storage::disk($disk)->exists($file->path)) {
                $fileDeleted = Storage::disk($disk)->delete($file->path);
                if (! $fileDeleted) {
                    throw ValidationException::withMessages([
                        'record' => 'Uploaded file could not be deleted. Please try again.',
                    ]);
                }
            } else {
                $fileDeleted = true;
            }
        }

        $this->config = $this->config->markUploadedFileDeleted($fileDeleted, now()->toISOString());
        $this->save();

        $payload = [
            'file_id' => $file?->id,
            'file_name' => $file?->name,
            'file_deleted' => $fileDeleted,
        ];

        $this->transitionToState(
            self::STATE_CANCELED,
            'Record was canceled by user and uploaded file was deleted.',
            UploadQueueLogContextEnums::WARNING,
            UploadQueueLogTypeEnums::STATE_CHANGE,
            $payload,
            $user?->id
        );

        return $payload;
    }

    public function canBeReviewedByAdmin(): bool
    {
        return $this->state >= self::STATE_CONFIGURED;
    }

    public function shouldBeDecidedByAdmin(): bool
    {
        return $this->state == self::STATE_REVIEW_REQUIRED &&
            $this->config->detailedValidationPassed();
    }

    /**
     * Approves the data the administrator has seen: a record that has left
     * the review since (taken back, reconfigured, imported) is not approved.
     */
    public function approveAdminReview(?int $userId = null): void
    {
        if (! $this->shouldBeDecidedByAdmin()) {
            return;
        }

        $this->config = $this->config->markAdminReviewApproved(now()->toISOString());
        $this->save();

        $this->transitionToState(
            self::STATE_PENDING,
            'Upload data was approved by administrator and returned to processing queue.',
            UploadQueueLogContextEnums::SUCCESS,
            UploadQueueLogTypeEnums::STATE_CHANGE,
            ['admin_review_approved' => true],
            $userId,
        );
    }

    public function rejectAdminReview(string $reason, ?int $userId = null): void
    {
        if (! $this->canBeReviewedByAdmin()) {
            return;
        }

        $this->config = $this->config->markAdminReviewRejected($reason, now()->toISOString());
        $this->save();

        $this->transitionToState(
            self::STATE_ERROR,
            "Upload data was rejected by administrator.\nReason: {$reason}",
            UploadQueueLogContextEnums::ERROR,
            UploadQueueLogTypeEnums::STATE_CHANGE,
            ['reason' => $reason],
            $userId,
        );
    }

    public function addLog(UploadQueueLog $log): void
    {
        $this->logs->push($log);
        $this->save();
    }

    public function addStructuredLog(
        string $message,
        UploadQueueLogContextEnums $context = UploadQueueLogContextEnums::INFO,
        UploadQueueLogTypeEnums $type = UploadQueueLogTypeEnums::STATE_CHANGE,
        ?int $state = null,
        ?array $payload = null,
        ?int $userId = null,
    ): void {
        $this->addLog(new UploadQueueLog(
            $message,
            $context,
            now()->toISOString(),
            $userId ? (string) $userId : (Auth::id() ? (string) Auth::id() : null),
            $type,
            $state ?? $this->state,
            $payload,
        ));

        if ($type !== UploadQueueLogTypeEnums::NOTIFICATION && $context === UploadQueueLogContextEnums::ERROR) {
            $this->queueStatusUpdate(true);
        }
    }

    public function transitionToState(
        int $state,
        string $message,
        UploadQueueLogContextEnums $context = UploadQueueLogContextEnums::INFO,
        UploadQueueLogTypeEnums $type = UploadQueueLogTypeEnums::STATE_CHANGE,
        ?array $payload = null,
        ?int $userId = null,
    ): void {
        $this->state = $state;
        $this->save();

        $this->addStructuredLog(
            $message,
            $context,
            $type,
            $state,
            $payload,
            $userId,
        );

        if ($context !== UploadQueueLogContextEnums::ERROR) {
            $sendImmediately = $this->shouldSendStatusUpdateImmediately($state, $context);
            $this->queueStatusUpdate($sendImmediately);
        }
    }

    private function shouldSendStatusUpdateImmediately(int $state, UploadQueueLogContextEnums $context): bool
    {
        return $context === UploadQueueLogContextEnums::ERROR ||
            in_array($state, [self::STATE_DONE, self::STATE_REVIEW_REQUIRED], true);
    }

    private function queueStatusUpdate(bool $sendImmediately): void
    {
        SendUploadQueueStatusUpdate::dispatch($this->id, $sendImmediately)
            ->afterCommit()
            ->delay($sendImmediately ? 0 : 600);
    }

    public function hasValidConfig(): bool
    {
        return $this->config->isConfigured();
    }

    public function start(): void
    {
        if (! $this->hasValidConfig()) {
            Notification::make()
                ->title('Upload job cannot be started')
                ->body('Invalid configuration for the job. Please, reconfigure the upload job.')
                ->danger()
                ->send();

            $this->state = self::STATE_UPLOADED;
            $this->save();

            return;
        }

        // Just label as pending and add to the queue to process
        $this->state = self::STATE_PENDING;
        $this->save();

        Notification::make()
            ->title('Upload job added to queue')
            ->body('The file will be automatically processed. We will notify you by email about the progress.')
            ->success()
            ->persistent()
            ->send();
    }

    public function cancel(): void
    {
        if (! $this->isCancelable()) {
            $this->addLog(
                new UploadQueueLog(
                    'Could not be canceled. State: ['.$this->enumState($this->state).']',
                    UploadQueueLogContextEnums::ERROR,
                    now(),
                    Auth::user()->id
                )
            );

            Notification::make()
                ->title('Upload job cannot be canceled')
                ->body('Only running jobs can be canceled.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Not implemented')
            ->body('Cancel process is not implemented yet.')
            ->warning()
            ->send();

        return;

        $this->state = self::STATE_CANCELED;
        $this->save();
    }

    public function revert(): void
    {
        if (! $this->isRevertible()) {
            $this->addLog(
                new UploadQueueLog(
                    'Could not be reverted. State: ['.$this->enumState($this->state).']',
                    UploadQueueLogContextEnums::ERROR,
                    now(),
                    Auth::user()->id
                )
            );

            Notification::make()
                ->title('Upload job is not revertible')
                ->body('Only finished and not started jobs can be reverted.')
                ->danger()
                ->send();

            return;
        }

        if ($this->state == self::STATE_PENDING) {
            $this->state = self::STATE_CONFIGURED;
            $this->save();

            $this->addLog(
                new UploadQueueLog(
                    'Reverted from state: "'.$this->enumState($this->state).'".',
                    UploadQueueLogContextEnums::WARNING,
                    now(),
                    Auth::user()->id
                )
            );

            Notification::make()
                ->title('Upload job reverted')
                ->success()
                ->send();

            return;
        }

        $dataset = $this->dataset;

        // Remove all passive interactions
        foreach ($dataset->interactionsPassive as $interaction) {
            $interaction->forceDelete();
        }

        // Remove all active interactions
        foreach ($dataset->interactionsActive as $interaction) {
            $interaction->forceDelete();
        }

        // Remove all added identifiers
        foreach ($dataset->identifiers as $identifier) {
            $identifier->forceDelete();
        }

        Notification::make()
            ->title('Upload job reverted.')
            ->body('All uploaded data was removed and the job is ready to be reconfigured.')
            ->success()
            ->send();

        $this->state = self::STATE_CONFIGURED;
        $this->save();
    }
}
