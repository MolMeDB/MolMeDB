<?php

namespace App\Jobs;

use App\Enums\UploadQueueLogContextEnums;
use App\Enums\UploadQueueLogTypeEnums;
use App\Models\UploadQueue;
use App\Rules\UploadFile\Identifiers\ColumnChebi;
use App\Rules\UploadFile\Identifiers\ColumnChembl;
use App\Rules\UploadFile\Identifiers\ColumnPdb;
use App\Rules\UploadFile\Identifiers\ColumnPubchem;
use App\Services\External\Chemical\Unichem\Unichem;
use App\Services\LabUploadAdminDigestQueue;
use App\Services\SystemActivityLogger;
use App\Services\UploadQueueDetailedValidator;
use App\Services\UploadQueueImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use PDOException;
use RuntimeException;
use Throwable;

class ProcessUploadQueueRecord implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const UNICHEM_RETRY_DELAY_SECONDS = 1800;

    private const DEADLINE_RELEASE_BUFFER_SECONDS = 180;

    private const DEADLINE_RETRY_DELAY_SECONDS = 10;

    public int $tries = 48;

    public int $timeout = 1800;

    public int $uniqueFor = 3600;

    private const OVERLAP_RELEASE_SECONDS = 900;

    private const OVERLAP_LOCK_SECONDS = 1900;

    /**
     * Do not queue twice the same UploadQueue record
     */
    public function uniqueId(): string
    {
        return (string) $this->recordId;
    }

    public function middleware(): array
    {
        return [
            new WithoutOverlapping($this->recordId)
                ->releaseAfter(self::OVERLAP_RELEASE_SECONDS)
                ->expireAfter(self::OVERLAP_LOCK_SECONDS),
        ];
    }

    public function __construct(protected int $recordId)
    {
        $this->onQueue('upload');
        $this->delay(2);
    }

    /**
     * Handle the event.
     */
    public function handle(
        UploadQueueDetailedValidator $validator,
        UploadQueueImporter $importer,
        SystemActivityLogger $activityLogger,
    ): void {
        $record = UploadQueue::query()
            ->with(['file', 'dataset', 'user'])
            ->find($this->recordId);

        if (! $record) {
            Log::channel('upload')
                ->error('Upload queue record not found', [
                    'record_id' => $this->recordId,
                ]);

            return;
        }

        if (! in_array((int) $record->state, [UploadQueue::STATE_PENDING, UploadQueue::STATE_RUNNING], true)) {
            Log::channel('upload')
                ->warning('Upload queue record has invalid state. Stopping...', [
                    'record_id' => $this->recordId,
                    'state' => $record->enumState($record->state),
                ]);

            return;
        }

        try {
            if ((int) $record->state === UploadQueue::STATE_PENDING) {
                $record->transitionToState(
                    UploadQueue::STATE_RUNNING,
                    'Upload processing has started.',
                    UploadQueueLogContextEnums::INFO,
                    UploadQueueLogTypeEnums::UPLOAD_RUN,
                    null,
                    null
                );
            }

            Log::channel('upload')
                ->info('Starting upload processing', [
                    'record_id' => $this->recordId,
                    'file_path' => $record->file?->path,
                ]);

            if (! $record->config->detailedValidationPassed()) {
                if ($this->shouldWaitForUnichem($record)) {
                    $this->waitForUnichem($record);

                    return;
                }

                if ($this->runDetailedValidation($record, $validator)) {
                    return;
                }

                $record->refresh()->load(['file', 'dataset', 'user']);
            }

            if (! $record->config->adminReviewApproved()) {
                $summary = $importer->summarize($record);

                $record->transitionToState(
                    UploadQueue::STATE_REVIEW_REQUIRED,
                    'Automatic validation passed. Upload data is waiting for administrator review.',
                    UploadQueueLogContextEnums::SUCCESS,
                    UploadQueueLogTypeEnums::UPLOAD_RUN,
                    $summary,
                    null
                );

                Log::channel('upload')
                    ->info('Upload waiting for administrator review', $summary);

                $this->notifyAdmins(LabUploadAdminDigestQueue::EVENT_REVIEW_REQUIRED, $record);
                $this->logCompletedActivity($activityLogger, $record, 'review_required', $summary);

                return;
            }

            $summary = $importer->import(
                $record,
                now()->addSeconds(max(60, $this->timeout - self::DEADLINE_RELEASE_BUFFER_SECONDS)),
            );

            if (($summary['deferred'] ?? false) === true) {
                $record->refresh();
                $record->addStructuredLog(
                    'Import checkpoint saved. Upload processing will continue shortly.',
                    UploadQueueLogContextEnums::INFO,
                    UploadQueueLogTypeEnums::UPLOAD_RUN,
                    $record->state,
                    [
                        'processed_rows' => $summary['prepared_rows'] ?? null,
                        'created_rows' => $summary['created_rows'] ?? null,
                        'skipped_rows' => $summary['skipped_rows'] ?? null,
                        'next_line' => $summary['next_line'] ?? null,
                        'retry_delay_seconds' => self::DEADLINE_RETRY_DELAY_SECONDS,
                        'retry_at' => now()->addSeconds(self::DEADLINE_RETRY_DELAY_SECONDS)->toISOString(),
                    ],
                    null
                );

                $this->release(self::DEADLINE_RETRY_DELAY_SECONDS);

                return;
            }

            $record->transitionToState(
                UploadQueue::STATE_DONE,
                'All interactions successfully imported.',
                UploadQueueLogContextEnums::SUCCESS,
                UploadQueueLogTypeEnums::UPLOAD_RUN,
                $summary,
                null
            );

            Log::channel('upload')
                ->info('Upload import preparation finished', $summary);

            $this->logCompletedActivity($activityLogger, $record, 'imported', $summary);
        } catch (Throwable $throwable) {
            $record->refresh();

            Log::channel('upload')->error('Upload processing failed.', [
                'record_id' => $this->recordId,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            if ((int) $record->state !== UploadQueue::STATE_ERROR) {
                $record->transitionToState(
                    UploadQueue::STATE_ERROR,
                    $this->uploaderFailureMessage($throwable),
                    UploadQueueLogContextEnums::ERROR,
                    UploadQueueLogTypeEnums::UPLOAD_RUN,
                    null,
                    null
                );

                $this->notifyAdmins(LabUploadAdminDigestQueue::EVENT_PROCESSING_ERROR, $record, $throwable->getMessage());
            }

            // The record is already marked as an error, so the state guard at the top
            // of handle() would turn every further retry into a no-op. Fail the job
            // immediately instead of burning through all $tries attempts pointlessly.
            $this->fail($throwable);
        }
    }

    /**
     * The message stored in the upload's log, which its uploader reads (also
     * through a tracking link and in the status e-mail). A database error
     * carries the SQL and its values, so it is replaced; the administrators
     * still get the original in their digest and in the upload log channel.
     */
    private function uploaderFailureMessage(?Throwable $throwable): string
    {
        if ($throwable === null || trim($throwable->getMessage()) === '') {
            return 'Upload processing failed.';
        }

        if ($throwable instanceof PDOException) {
            return 'Upload processing failed because of a database error. The administrators have been notified.';
        }

        return $throwable->getMessage();
    }

    public function failed(?Throwable $throwable): void
    {
        $record = UploadQueue::query()->find($this->recordId);

        if ($record && in_array((int) $record->state, [UploadQueue::STATE_PENDING, UploadQueue::STATE_RUNNING], true)) {
            $record->transitionToState(
                UploadQueue::STATE_ERROR,
                $this->uploaderFailureMessage($throwable),
                UploadQueueLogContextEnums::ERROR,
                UploadQueueLogTypeEnums::UPLOAD_RUN,
                null,
                null
            );

            $this->notifyAdmins(
                LabUploadAdminDigestQueue::EVENT_PROCESSING_ERROR,
                $record,
                $throwable?->getMessage() ?? 'Upload processing failed.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function logCompletedActivity(
        SystemActivityLogger $activityLogger,
        UploadQueue $record,
        string $outcome,
        array $summary,
    ): void {
        $activityLogger->logThrottled(
            event: 'upload_processing_completed',
            description: 'Upload queue worker completed a record successfully.',
            properties: [
                'record_id' => $record->getKey(),
                'outcome' => $outcome,
                'prepared_rows' => $summary['prepared_rows'] ?? null,
                'created_rows' => $summary['created_rows'] ?? null,
                'skipped_rows' => $summary['skipped_rows'] ?? null,
            ],
            severity: SystemActivityLogger::SEVERITY_INFO,
            throttleKey: 'upload-processing-completed',
            seconds: 300,
        );
    }

    private function notifyAdmins(string $event, UploadQueue $record, ?string $message = null): void
    {
        app(LabUploadAdminDigestQueue::class)->push($event, $record, $message);
    }

    private function runDetailedValidation(
        UploadQueue $record,
        UploadQueueDetailedValidator $validator,
    ): bool {
        $record->addStructuredLog(
            'Detailed validation started.',
            UploadQueueLogContextEnums::INFO,
            UploadQueueLogTypeEnums::VALIDATION_RUN,
            $record->state,
            null,
            null
        );

        $deadline = now()->addSeconds(max(60, $this->timeout - self::DEADLINE_RELEASE_BUFFER_SECONDS));
        $result = $validator->validate($record, $deadline);
        if (($result['deferred'] ?? false) === true) {
            $record->refresh();
            $record->addStructuredLog(
                'Detailed validation checkpoint saved. Upload processing will continue shortly.',
                UploadQueueLogContextEnums::INFO,
                UploadQueueLogTypeEnums::VALIDATION_RUN,
                $record->state,
                [
                    'validated_rows' => $result['validated_rows'] ?? null,
                    'processed_rows' => $result['processed_rows'] ?? null,
                    'next_line' => $result['next_line'] ?? null,
                    'retry_delay_seconds' => self::DEADLINE_RETRY_DELAY_SECONDS,
                    'retry_at' => now()->addSeconds(self::DEADLINE_RETRY_DELAY_SECONDS)->toISOString(),
                ],
                null
            );

            $this->release(self::DEADLINE_RETRY_DELAY_SECONDS);

            return true;
        }

        if (! ($result['ok'] ?? false)) {
            $errors = $result['errors'] ?? [];
            $message = $this->detailedValidationFailureMessage($errors);

            $record->transitionToState(
                UploadQueue::STATE_ERROR,
                $message,
                UploadQueueLogContextEnums::ERROR,
                UploadQueueLogTypeEnums::VALIDATION_RUN,
                [
                    'errors' => array_values(array_merge($errors, $result['row_errors'] ?? [])),
                    'row_errors' => $result['row_errors'] ?? [],
                    'warnings' => $result['warnings'] ?? [],
                ],
                null
            );

            throw new RuntimeException($message);
        }

        $record->config = $record->config
            ->merge($result['config'] ?? [])
            ->withDetailedValidation(
                true,
                $result['config']['validated_rows'] ?? null,
                $result['config']['validated_at'] ?? null,
                now()->toISOString(),
            );
        $record->save();

        if (! empty($result['row_errors'] ?? [])) {
            $record->addStructuredLog(
                'Some upload rows contain duplicate interactions with different values and will be skipped during import.',
                UploadQueueLogContextEnums::ERROR,
                UploadQueueLogTypeEnums::VALIDATION_RUN,
                $record->state,
                [
                    'errors' => $result['row_errors'],
                    'row_errors' => $result['row_errors'],
                ],
                null
            );
        }

        $record->addStructuredLog(
            'Detailed validation passed. Record is ready for import.',
            empty($result['warnings'] ?? []) && empty($result['row_errors'] ?? [])
                ? UploadQueueLogContextEnums::SUCCESS
                : UploadQueueLogContextEnums::WARNING,
            UploadQueueLogTypeEnums::VALIDATION_RUN,
            $record->state,
            [
                'validated_rows' => $result['config']['validated_rows'] ?? null,
                'errors' => $result['row_errors'] ?? [],
                'row_errors' => $result['row_errors'] ?? [],
                'warnings' => $result['warnings'] ?? [],
            ],
            null
        );

        return false;
    }

    private function shouldWaitForUnichem(UploadQueue $record): bool
    {
        if (! $this->usesUnichemValidatedIdentifier($record)) {
            return false;
        }

        return ! (new Unichem)->is_reachable();
    }

    private function usesUnichemValidatedIdentifier(UploadQueue $record): bool
    {
        $unichemColumnKeys = [
            ColumnChebi::$key,
            ColumnChembl::$key,
            ColumnPdb::$key,
            ColumnPubchem::$key,
        ];

        foreach ($record->config->attributes() as $columnKey) {
            if (is_string($columnKey) && in_array($columnKey, $unichemColumnKeys, true)) {
                return true;
            }
        }

        return false;
    }

    private function waitForUnichem(UploadQueue $record): void
    {
        $record->addStructuredLog(
            'UniChem API is not reachable. Upload processing is waiting for UniChem availability and will be retried in 30 minutes.',
            UploadQueueLogContextEnums::WARNING,
            UploadQueueLogTypeEnums::VALIDATION_RUN,
            UploadQueue::STATE_RUNNING,
            [
                'service' => 'unichem',
                'retry_delay_seconds' => self::UNICHEM_RETRY_DELAY_SECONDS,
                'retry_at' => now()->addSeconds(self::UNICHEM_RETRY_DELAY_SECONDS)->toISOString(),
            ],
            null
        );

        $this->release(self::UNICHEM_RETRY_DELAY_SECONDS);
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function detailedValidationFailureMessage(array $errors): string
    {
        if ($errors === []) {
            return 'Detailed validation failed.';
        }

        $visibleErrors = array_slice($errors, 0, 10);
        $message = "Detailed validation failed:\n- ".implode("\n- ", $visibleErrors);

        $remainingErrors = count($errors) - count($visibleErrors);
        if ($remainingErrors > 0) {
            $message .= "\n- ...and {$remainingErrors} more errors.";
        }

        return $message;
    }
}
