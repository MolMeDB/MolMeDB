<?php

namespace App\Console\Commands;

use App\Enums\UploadQueueLogContextEnums;
use App\Enums\UploadQueueLogTypeEnums;
use App\Jobs\ProcessUploadQueueRecord;
use App\Models\UploadQueue;
use App\Services\SystemActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessFrontendUploads extends Command
{
    protected $signature = 'uploads:process-frontend {--limit=25}';

    protected $description = 'Dispatches pending frontend upload records to the upload queue.';

    public function handle(SystemActivityLogger $activityLogger): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $queuedCount = 0;
        $errorCount = 0;

        $pendingRecords = UploadQueue::query()
            ->with('user')
            ->where('state', UploadQueue::STATE_PENDING)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($pendingRecords as $record) {
            try {
                Log::channel('upload')->info('Dispatching pending upload processing job from command.', [
                    'record_id' => $record->id,
                ]);

                ProcessUploadQueueRecord::dispatch($record->id);
                $queuedCount++;
            } catch (Throwable $throwable) {
                // The uploader reads this log; a queue connection error names
                // hosts and ports, so the detail goes to the log channel only.
                Log::channel('upload')->error('Dispatching the upload processing job failed.', [
                    'record_id' => $record->id,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ]);

                $record->transitionToState(
                    UploadQueue::STATE_ERROR,
                    'The upload could not be queued for processing. The administrators have been notified.',
                    UploadQueueLogContextEnums::ERROR,
                    UploadQueueLogTypeEnums::STATE_CHANGE,
                    null,
                    $record->user_id
                );
                $errorCount++;
            }
        }

        $this->info("Queued: {$queuedCount}; errors: {$errorCount}");

        if ($queuedCount > 0 || $errorCount > 0) {
            $activityLogger->log(
                event: $errorCount > 0 ? 'frontend_upload_dispatch_completed_with_errors' : 'frontend_uploads_dispatched',
                description: "Frontend upload dispatcher queued {$queuedCount} job(s) with {$errorCount} error(s).",
                properties: [
                    'queued' => $queuedCount,
                    'errors' => $errorCount,
                ],
                severity: $errorCount > 0
                    ? SystemActivityLogger::SEVERITY_ERROR
                    : SystemActivityLogger::SEVERITY_INFO,
            );
        }

        return self::SUCCESS;
    }
}
