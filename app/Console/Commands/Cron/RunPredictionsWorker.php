<?php

namespace App\Console\Commands\Cron;

use App\Enums\PermissionEnums;
use App\Jobs\CheckPredictionStatus;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\PredictionSubmissionStructureValidator;
use App\Services\SystemActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\PredictionWorkers\Enums\RemotePredictionStatus;
use Modules\PredictionWorkers\Exceptions\RemotePredictionDisabledException;
use Modules\PredictionWorkers\Exceptions\RemotePredictionException;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionDataset;
use Modules\PredictionWorkers\Models\PredictionStat;
use Modules\PredictionWorkers\Services\RemotePrediction\RemotePredictionClient;
use Throwable;

class RunPredictionsWorker extends Command
{
    private int $statisticsErrors = 0;

    private int $downloadErrors = 0;

    private const MAX_VALIDATION_FAILURES_PER_RUN = 3;

    /**
     * How many times in a row the remote service may refuse a prediction as
     * such (not an outage) before it is marked as failed. Until then it keeps
     * its place among the predictions to activate, and with it one of the
     * few remote slots.
     */
    public const MAX_REJECTED_SUBMISSION_ATTEMPTS = 5;

    private const REJECTED_SUBMISSION_LOG_TYPE = 'REMOTE SUBMIT';

    /**
     * No more predictions than this are given up in one day. A fault of the
     * service or of its settings can look like a refusal of every prediction;
     * the limit keeps it from failing the whole backlog before anybody looks.
     */
    public const MAX_GIVEN_UP_SUBMISSIONS_PER_DAY = 50;

    private int $givenUpSubmissions = 0;

    private bool $giveUpLimitReached = false;

    private int $submissionErrors = 0;

    private int $rejectedStructures = 0;

    private int $reconciliationErrors = 0;

    /**
     * The first error of the run, recorded with the run so that the health
     * report can say what went wrong, not only how many times. A structure
     * the service refuses gives way to a later error of the service itself,
     * which tells more.
     */
    private ?string $firstError = null;

    private bool $firstErrorIsRejection = false;

    protected $signature = 'cron:predictions-worker
        {--max-results= : Maximum completed prediction results to download}
        {--max-submit= : Maximum new or paused predictions to activate}
        {--skip-stats : Do not fetch remote server statistics}
        {--skip-results : Do not download completed prediction results}
        {--skip-submit : Do not submit prepared predictions}
        {--skip-reconcile : Do not reconcile the local priority window with the remote queue}
        {--skip-dispatch : Do not dispatch queue jobs for running predictions}';

    protected $description = 'Fetch remote statistics, submit prepared predictions, download completed results, and dispatch queue jobs for running status checks.';

    public function handle(
        RemotePredictionClient $client,
        PredictionSubmissionStructureValidator $structureValidator,
        SystemActivityLogger $activityLogger,
        NotificationService $notificationService,
    ): int {
        if (! $client->isEnabled()) {
            $this->warn('Remote prediction service is disabled.');

            return Command::SUCCESS;
        }

        $lock = Cache::lock('remote-prediction:worker', 600);

        if (! $lock->get()) {
            $this->warn('Another remote prediction worker is already running.');

            return Command::SUCCESS;
        }

        try {
            try {
                $client->ensureValidToken();
            } catch (Throwable $throwable) {
                $this->error('Failed to ensure valid token: '.$throwable->getMessage());

                $activityLogger->logThrottled(
                    event: 'prediction_worker_authentication_failed',
                    description: 'Remote prediction worker could not authenticate.',
                    properties: [
                        'exception' => $throwable::class,
                        'error' => $throwable->getMessage(),
                    ],
                    throttleKey: 'remote-prediction-authentication',
                );

                // This runs every minute (see routes/console.php); the type
                // is Immediate (never batched) so an ongoing outage needs
                // its own throttle here, or admins would get one alert per
                // minute for as long as authentication keeps failing.
                if (Cache::add('notification-throttle:prediction-remote-service-down', true, now()->addMinutes(30))) {
                    $notificationService->sendToPermission(
                        PermissionEnums::PREDICTION_DATASET_MANAGE_ALL->value,
                        NotificationTemplate::KEY_PREDICTION_ADMIN_REMOTE_SERVICE_DOWN,
                        ['error' => $throwable->getMessage()],
                    );
                }

                return Command::FAILURE;
            }

            $maxActive = max(1, (int) config('prediction-workers.remote.worker.max_active', 100));
            $activationLimit = $this->option('skip-submit')
                ? 0
                : $this->integerOption(
                    'max-submit',
                    (int) config('prediction-workers.remote.worker.max_submissions', 5),
                );
            $reconciliation = $this->option('skip-reconcile')
                ? $this->workingSetWithoutReconciliation($maxActive)
                : $this->reconcileRemoteWorkingSet(
                    $client,
                    $activityLogger,
                    $maxActive,
                );
            $activation = $this->activateDesiredPredictions(
                $client,
                $structureValidator,
                $reconciliation['desired_ids'],
                $activationLimit,
                $maxActive,
            );

            $results = [
                'stats' => $this->option('skip-stats') ? null : $this->refreshStatistics($client),
                'reconciliation' => $reconciliation,
                'activation' => $activation,
                'downloads' => $this->option('skip-results') ? null : $this->downloadCompletedResults($client),
                'submissions' => $this->option('skip-submit') ? null : $activation['submitted'],
                'dispatched' => $this->option('skip-dispatch')
                    ? null
                    : $this->dispatchStatusChecks(),
            ];

            $this->info(sprintf(
                'Remote prediction worker finished. Stats: %s, desired: %d, active: %d, activation candidates: %d, available slots: %d, submissions: %s, resumed: %d, downloads: %s, dispatched: %s.',
                $results['stats'] === null ? 'skipped' : 'stored',
                count($reconciliation['desired_ids']),
                $activation['active_before'],
                $activation['candidates'],
                $activation['available_slots'],
                $results['submissions'] === null ? 'skipped' : (string) $results['submissions'],
                $activation['resumed'],
                $results['downloads'] === null ? 'skipped' : (string) $results['downloads'],
                $results['dispatched'] === null ? 'skipped' : (string) $results['dispatched'],
            ));

            $errorCount = $this->statisticsErrors
                + $this->downloadErrors
                + $this->submissionErrors
                + $this->reconciliationErrors
                + $this->rejectedStructures;
            $processedCount = ($results['submissions'] ?? 0)
                + $activation['resumed']
                + ($results['downloads'] ?? 0);

            if (
                ! $this->option('skip-submit')
                && $activation['available_slots'] > 0
                && $activation['candidates'] > 0
                && $activation['submitted'] + $activation['resumed'] === 0
            ) {
                $activityLogger->logThrottled(
                    event: 'prediction_worker_activation_stalled',
                    description: 'Prediction worker has free remote capacity but activated no selected prediction.',
                    properties: [
                        'desired' => count($reconciliation['desired_ids']),
                        ...$activation,
                    ],
                    throttleKey: 'prediction-worker-activation-stalled',
                );
            }

            if ($this->giveUpLimitReached) {
                $activityLogger->logThrottled(
                    event: 'prediction_worker_give_up_limit_reached',
                    description: 'The remote prediction service keeps refusing predictions and the daily limit of those marked as failed is used up; the rest waits. Check the service and its settings.',
                    properties: ['limit' => self::MAX_GIVEN_UP_SUBMISSIONS_PER_DAY],
                    throttleKey: 'prediction-worker-give-up-limit',
                    seconds: 6 * 3600,
                );
            }

            if ($processedCount > 0 || $errorCount > 0) {
                $description = sprintf(
                    'Prediction worker submitted %d, resumed %d and downloaded %d prediction(s); encountered %d error(s).',
                    $results['submissions'] ?? 0,
                    $activation['resumed'],
                    $results['downloads'] ?? 0,
                    $errorCount,
                );
                $properties = [
                    'statistics_stored' => $results['stats'] instanceof PredictionStat,
                    'submissions' => $results['submissions'],
                    'resumed' => $activation['resumed'],
                    'activation_candidates' => $activation['candidates'],
                    'available_slots' => $activation['available_slots'],
                    'downloads' => $results['downloads'],
                    'dispatched' => $results['dispatched'],
                    'statistics_errors' => $this->statisticsErrors,
                    'download_errors' => $this->downloadErrors,
                    'submission_errors' => $this->submissionErrors,
                    'reconciliation_errors' => $this->reconciliationErrors,
                    'rejected_structures' => $this->rejectedStructures,
                    'given_up_submissions' => $this->givenUpSubmissions,
                    'error' => $this->firstError,
                ];

                if ($errorCount > 0) {
                    $activityLogger->logThrottled(
                        event: 'prediction_worker_completed_with_errors',
                        description: $description,
                        properties: $properties,
                        throttleKey: 'prediction-worker-errors',
                    );
                } else {
                    $activityLogger->log(
                        event: 'prediction_worker_completed',
                        description: $description,
                        properties: $properties,
                    );
                }
            }

            return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    private function refreshStatistics(RemotePredictionClient $client): ?PredictionStat
    {
        try {
            return PredictionStat::storeDailySnapshot(
                $client->baseUrl(),
                now(),
                $client->pipelineStatistics()->toArray(),
            );
        } catch (RemotePredictionDisabledException $throwable) {
            throw $throwable;
        } catch (Throwable $throwable) {
            $this->statisticsErrors++;
            $this->warnError('Remote statistics refresh failed: '.$throwable->getMessage());

            return null;
        }
    }

    /**
     * Dispatch status checks for every active remote prediction, regardless
     * of whether it is currently inside the priority execution window.
     * Priority controls execution, never monitoring. Oldest checks go first
     * so low-priority jobs cannot starve behind repeatedly checked rows.
     */
    private function dispatchStatusChecks(): int
    {
        $limit = max(1, (int) config('prediction-workers.remote.worker.max_active', 100));
        $statusIntervalSeconds = max(30, (int) config('prediction-workers.remote.worker.status_interval_seconds', 300));
        $dispatched = 0;

        Prediction::query()
            ->whereNotNull('remote_calculation_id')
            ->whereNull('remote_paused_at')
            ->whereNull('result_id')
            ->whereNotIn('state', Prediction::failedStates())
            ->where(function ($query): void {
                $query
                    ->whereNull('remote_status')
                    ->orWhereIn('remote_status', Prediction::activeRemoteStatuses());
            })
            ->where(function ($query) use ($statusIntervalSeconds): void {
                $query
                    ->whereNull('remote_last_status_at')
                    ->orWhere('remote_last_status_at', '<=', now()->subSeconds($statusIntervalSeconds));
            })
            ->orderByRaw('CASE WHEN remote_last_status_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('remote_last_status_at')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (Prediction $prediction) use (&$dispatched): void {
                CheckPredictionStatus::dispatch($prediction->getKey());
                $dispatched++;
            });

        return $dispatched;
    }

    private function downloadCompletedResults(RemotePredictionClient $client): int
    {
        $limit = $this->integerOption(
            'max-results',
            (int) config('prediction-workers.remote.worker.max_result_downloads', 5),
        );
        $downloaded = 0;
        $finishedDatasetIds = [];

        Prediction::query()
            ->whereNotNull('remote_calculation_id')
            ->whereNull('result_id')
            ->where('remote_status', RemotePredictionStatus::COMPLETED->value)
            ->whereNotIn('state', Prediction::failedStates())
            ->orderByDesc('priority')
            ->orderBy('remote_finished_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (Prediction $prediction) use ($client, &$downloaded, &$finishedDatasetIds): void {
                try {
                    $prediction->storeRemotePredictionResult($client);
                    $downloaded++;

                    // A failed COSMO download does not invalidate the stored result, the prediction is only left out of the COSMO export.
                    try {
                        $prediction->storeRemotePredictionCosmoFiles($client);
                    } catch (Throwable $throwable) {
                        $this->warn("Prediction {$prediction->getKey()} COSMO files download failed: {$throwable->getMessage()}");
                    }

                    // Track which datasets may now be complete
                    foreach ($prediction->predictionDatasets()->pluck('datasets.id') as $datasetId) {
                        $finishedDatasetIds[(int) $datasetId] = true;
                    }
                } catch (Throwable $throwable) {
                    $this->downloadErrors++;
                    $prediction->forceFill([
                        'remote_last_status_at' => now(),
                        'remote_error_message' => $throwable->getMessage(),
                    ])->save();

                    $this->warnError("Prediction {$prediction->getKey()} result download failed: {$throwable->getMessage()}");
                }
            });

        // Send job-finished notification for datasets where all predictions are now done
        if ($finishedDatasetIds) {
            $this->notifyFinishedDatasets(array_keys($finishedDatasetIds));
        }

        return $downloaded;
    }

    /**
     * Activate selected predictions in their exact priority order. A paused
     * prediction and a new prepared prediction consume the same activation
     * budget, so an older paused row can never jump ahead of a higher-priority
     * new submission.
     *
     * @param  int[]  $desiredIds
     * @return array{active_before: int, available_slots: int, candidates: int, attempts: int, submitted: int, resumed: int}
     */
    private function activateDesiredPredictions(
        RemotePredictionClient $client,
        PredictionSubmissionStructureValidator $structureValidator,
        array $desiredIds,
        int $requestedLimit,
        int $maxActive,
    ): array {
        $active = Prediction::query()
            ->whereNotNull('remote_calculation_id')
            ->whereNull('remote_paused_at')
            ->whereNull('result_id')
            ->whereNotIn('state', Prediction::failedStates())
            ->where(function ($query): void {
                $query
                    ->whereNull('remote_status')
                    ->orWhereIn('remote_status', Prediction::activeRemoteStatuses());
            })
            ->count();
        $availableSlots = max(0, $maxActive - $active);
        $limit = min($requestedLimit, $availableSlots);
        $candidates = $this->workingSetCandidates()
            ->whereIn('id', $desiredIds)
            ->get()
            ->filter(fn (Prediction $prediction): bool => $prediction->remote_paused_at !== null
                || ($prediction->remote_calculation_id === null && $prediction->state === Prediction::STATE_PREPARED))
            ->values();
        $attempts = 0;
        $submitted = 0;
        $resumed = 0;
        $validationFailures = 0;

        foreach ($candidates as $prediction) {
            if ($attempts >= $limit || $submitted + $resumed >= $availableSlots) {
                break;
            }

            if ($prediction->remote_paused_at !== null) {
                $attempts++;

                try {
                    $prediction->submitAndStoreRemotePrediction(client: $client);
                    $prediction->forceFill([
                        'remote_paused_at' => null,
                        'remote_pause_reason' => null,
                        'logs' => $prediction->logsWithWorkerEvent(
                            'Remote prediction resumed after returning to the active priority window.',
                            ['priority' => $prediction->priority],
                            'REMOTE RESUME',
                        ),
                    ])->save();
                    CheckPredictionStatus::dispatch($prediction->getKey());
                    $resumed++;
                } catch (Throwable $throwable) {
                    $this->reconciliationErrors++;
                    $prediction->forceFill([
                        'remote_error_message' => $throwable->getMessage(),
                    ])->save();

                    $this->warnError("Prediction {$prediction->getKey()} resume failed: {$throwable->getMessage()}");
                }

                continue;
            }

            // After a few failed validations in one run the service is taken
            // as down: the remaining new predictions wait for the next run,
            // paused ones (which need no validation) are still resumed.
            if ($validationFailures >= self::MAX_VALIDATION_FAILURES_PER_RUN) {
                continue;
            }

            try {
                $structureIsValid = $structureValidator->passes($prediction);
            } catch (Throwable $throwable) {
                // The validation service failed for this structure (an outage,
                // or an error on this one SMILES): leave the prediction
                // prepared for the next run and go on with the others, instead
                // of aborting the whole run, which would also stop result
                // downloads and status checks.
                $validationFailures++;
                $this->submissionErrors++;
                $this->warnError("Prediction {$prediction->getKey()} was not submitted, structure validation failed: {$throwable->getMessage()}");

                continue;
            }

            if (! $structureIsValid) {
                $this->rejectedStructures++;
                $this->warnError("Prediction {$prediction->getKey()} was not submitted: {$prediction->remote_error_message}", isRejection: true);

                continue;
            }

            $attempts++;

            try {
                $prediction->submitAndStoreRemotePrediction(client: $client);
                CheckPredictionStatus::dispatch($prediction->getKey());
                $submitted++;
            } catch (Throwable $throwable) {
                $this->submissionErrors++;
                $prediction->forceFill([
                    'remote_method' => Prediction::hasRemotePredictionMethod((string) $prediction->method_type)
                        ? $prediction->remotePredictionMethod()
                        : null,
                    'remote_last_status_at' => now(),
                    'remote_error_message' => $throwable->getMessage(),
                    ...($this->isRejectedByRemoteService($throwable)
                        ? $this->rejectedSubmissionAttributes($prediction, $throwable)
                        : []),
                ])->save();

                $this->warnError("Prediction {$prediction->getKey()} submit failed: {$throwable->getMessage()}");
            }
        }

        return [
            'active_before' => $active,
            'available_slots' => $availableSlots,
            'candidates' => $candidates->count(),
            'attempts' => $attempts,
            'submitted' => $submitted,
            'resumed' => $resumed,
        ];
    }

    /**
     * Whether the remote service refused this very prediction: its structure
     * or its membrane. Sending it again gives the same answer, unlike an
     * outage, a refused login or a full queue, which pass by themselves.
     */
    private function isRejectedByRemoteService(Throwable $throwable): bool
    {
        if (! $throwable instanceof RemotePredictionException) {
            return false;
        }

        // Which status the service gives its refusals is not known for all
        // of them, so the two it is known to answer are told by their text:
        // a structure it cannot read, and a membrane it does not know while
        // there is no definition file to upload
        // (Prediction::submitToRemotePrediction()).
        $message = strtolower($throwable->getMessage());

        return in_array($throwable->statusCode, [400, 422], true)
            || str_contains($message, 'unknown membrane key')
            || str_contains($message, 'invalid smiles');
    }

    /**
     * Takes one of the day's given-up predictions, if any is left.
     */
    private function mayGiveUpSubmission(): bool
    {
        $key = 'prediction-worker:given-up-submissions:'.now()->toDateString();

        Cache::add($key, 0, now()->addDay());

        if ((int) Cache::get($key, 0) >= self::MAX_GIVEN_UP_SUBMISSIONS_PER_DAY) {
            $this->giveUpLimitReached = true;

            return false;
        }

        Cache::increment($key);

        return true;
    }

    /**
     * A refused prediction stays prepared, so it is among the first ones on
     * every run. Left there for good, a handful of them takes all the remote
     * slots and nothing else is ever sent; so after a few refusals in a row
     * it is marked as failed, with the reason kept.
     *
     * @return array{logs?: array<int, mixed>, state?: int}
     */
    private function rejectedSubmissionAttributes(Prediction $prediction, Throwable $throwable): array
    {
        $attempts = 1;

        foreach (array_reverse(is_array($prediction->logs) ? $prediction->logs : []) as $entry) {
            if (! is_array($entry)
                || ($entry['type'] ?? null) !== self::REJECTED_SUBMISSION_LOG_TYPE
                || ($entry['context'] ?? null) !== 'error') {
                break;
            }

            $attempts++;
        }

        if ($attempts >= self::MAX_REJECTED_SUBMISSION_ATTEMPTS && ! $this->mayGiveUpSubmission()) {
            // Waits for tomorrow as it is; another line in its log each
            // minute would only push the older ones out.
            return [];
        }

        $givenUp = $attempts >= self::MAX_REJECTED_SUBMISSION_ATTEMPTS;
        $this->givenUpSubmissions += $givenUp ? 1 : 0;
        $message = 'The remote prediction service refused the prediction: '.$throwable->getMessage();

        return [
            'logs' => $prediction->logsWithWorkerEvent(
                $givenUp ? $message.' Given up after repeated attempts; the prediction is marked as failed.' : $message,
                [
                    'attempt' => $attempts,
                    'status_code' => $throwable instanceof RemotePredictionException ? $throwable->statusCode : null,
                ],
                self::REJECTED_SUBMISSION_LOG_TYPE,
                'error',
            ),
            ...($givenUp ? ['state' => Prediction::STATE_ERROR] : []),
        ];
    }

    /**
     * @return array{desired_ids: int[], paused: int, shared_molecule_skips: int}
     */
    private function reconcileRemoteWorkingSet(
        RemotePredictionClient $client,
        SystemActivityLogger $activityLogger,
        int $maxActive,
    ): array {
        $submitted = $this->submittedWorkingSetCandidates();
        $terminalPredictionIds = $this->remoteTerminalPredictionIds($submitted, $client);

        if ($terminalPredictionIds === null) {
            return $this->workingSetWithoutReconciliation($maxActive);
        }

        $desired = $this->workingSetCandidates()
            ->when(
                $terminalPredictionIds !== [],
                fn (Builder $query): Builder => $query->whereNotIn('id', $terminalPredictionIds),
            )
            ->limit($maxActive)
            ->get();
        $desiredIds = $desired->modelKeys();
        $desiredStructureIds = $desired->pluck('structure_id')->unique()->all();
        $active = $submitted
            ->whereNull('remote_paused_at')
            ->reject(fn (Prediction $prediction): bool => in_array($prediction->getKey(), $terminalPredictionIds, true));
        $outsideWindow = $active->whereNotIn('id', $desiredIds);
        $pausable = $outsideWindow->whereNotIn('structure_id', $desiredStructureIds);
        $sharedMoleculeSkips = $outsideWindow->count() - $pausable->count();
        $paused = $this->pausePredictions($pausable, $client);

        if ($paused > 0 || $sharedMoleculeSkips > 0 || $this->reconciliationErrors > 0) {
            $activityLogger->log(
                event: 'prediction_remote_working_set_reconciled',
                description: 'Remote prediction queue reconciled with the current priority window.',
                properties: [
                    'desired' => count($desiredIds),
                    'active_before' => $active->count(),
                    'paused' => $paused,
                    'shared_molecule_skips' => $sharedMoleculeSkips,
                    'errors' => $this->reconciliationErrors,
                ],
            );
        }

        return [
            'desired_ids' => $desiredIds,
            'paused' => $paused,
            'shared_molecule_skips' => $sharedMoleculeSkips,
        ];
    }

    /**
     * @return array{desired_ids: int[], paused: int, shared_molecule_skips: int}
     */
    private function workingSetWithoutReconciliation(int $maxActive): array
    {
        return [
            'desired_ids' => $this->workingSetCandidates()->limit($maxActive)->pluck('id')->all(),
            'paused' => 0,
            'shared_molecule_skips' => 0,
        ];
    }

    private function workingSetCandidates(): Builder
    {
        return Prediction::query()
            ->with('predictionStructure')
            ->whereNull('result_id')
            ->whereNotIn('state', [...Prediction::failedStates(), Prediction::STATE_FINISHED])
            ->whereIn('method_type', array_keys(Prediction::remotePredictionMethodOptions()))
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $query): void {
                        $query
                            ->whereNull('remote_calculation_id')
                            ->where('state', Prediction::STATE_PREPARED);
                    })
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->whereNotNull('remote_calculation_id')
                            ->where(function (Builder $query): void {
                                $query
                                    ->whereNull('remote_status')
                                    ->orWhereIn('remote_status', Prediction::activeRemoteStatuses());
                            });
                    });
            })
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    private function submittedWorkingSetCandidates(): Collection
    {
        return Prediction::query()
            ->with('predictionStructure')
            ->whereNotNull('remote_calculation_id')
            ->whereNull('result_id')
            ->whereNotIn('state', [...Prediction::failedStates(), Prediction::STATE_FINISHED])
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('remote_status')
                    ->orWhereIn('remote_status', Prediction::activeRemoteStatuses());
            })
            ->get();
    }

    /**
     * @return int[]|null
     */
    private function remoteTerminalPredictionIds(Collection $submitted, RemotePredictionClient $client): ?array
    {
        $predictionsByCalculationId = $submitted->keyBy('remote_calculation_id');
        $terminalPredictionIds = [];

        foreach ($predictionsByCalculationId->keys()->chunk(500) as $calculationIds) {
            try {
                $client->calculationStatuses($calculationIds->all())
                    ->each(function ($calculation) use ($predictionsByCalculationId, &$terminalPredictionIds): void {
                        $status = $calculation->status instanceof RemotePredictionStatus
                            ? $calculation->status->value
                            : (string) $calculation->status;

                        if (! in_array($status, [
                            RemotePredictionStatus::COMPLETED->value,
                            RemotePredictionStatus::FAILED->value,
                        ], true)) {
                            return;
                        }

                        $prediction = $predictionsByCalculationId->get($calculation->id);

                        if ($prediction) {
                            $prediction->forceFill([
                                'remote_status' => $status,
                                'remote_last_status_at' => now(),
                                'remote_finished_at' => $calculation->finishedAt ?? now(),
                                'remote_paused_at' => null,
                                'remote_pause_reason' => null,
                                'remote_error_message' => $status === RemotePredictionStatus::FAILED->value
                                    ? $calculation->message
                                    : null,
                                'state' => $status === RemotePredictionStatus::FAILED->value
                                    ? Prediction::STATE_ERROR
                                    : Prediction::STATE_RUNNING,
                                'step' => $status === RemotePredictionStatus::COMPLETED->value
                                    ? Prediction::STEP_RESULT_DOWNLOAD
                                    : $prediction->step,
                            ])->save();
                            $terminalPredictionIds[] = $prediction->getKey();
                        }
                    });
            } catch (Throwable $throwable) {
                $this->reconciliationErrors++;
                $this->warnError('Remote calculation status reconciliation failed: '.$throwable->getMessage());

                return null;
            }
        }

        return array_values(array_unique($terminalPredictionIds));
    }

    private function pausePredictions(Collection $predictions, RemotePredictionClient $client): int
    {
        $groups = $predictions->groupBy('structure_id');
        $paused = 0;
        $reason = 'Prediction priority changed; deferred outside the active priority window.';

        foreach ($groups->chunk(1000) as $groupChunk) {
            $representatives = $groupChunk->map->first()->filter();
            $structureIds = $representatives->pluck('structure_id')->all();
            $smilesByStructureId = $representatives->mapWithKeys(
                fn (Prediction $prediction): array => [
                    $prediction->structure_id => $prediction->predictionStructure->remotePredictionSmiles(),
                ],
            );

            $predictionIdsToPause = Prediction::query()
                ->whereIn('structure_id', $structureIds)
                ->whereNotNull('remote_calculation_id')
                ->whereNull('remote_paused_at')
                ->whereNull('result_id')
                ->whereNotIn('state', [...Prediction::failedStates(), Prediction::STATE_FINISHED])
                ->where(function (Builder $query): void {
                    $query
                        ->whereNull('remote_status')
                        ->orWhereIn('remote_status', Prediction::activeRemoteStatuses());
                })
                ->pluck('id');

            Prediction::query()
                ->whereIn('id', $predictionIdsToPause)
                ->update([
                    'remote_paused_at' => now(),
                    'remote_pause_reason' => $reason,
                ]);

            try {
                $response = $client->pauseJobs($smilesByStructureId->values()->all(), $reason, true);
                $successfulSmiles = collect($response['results'] ?? [])
                    ->filter(fn (mixed $result): bool => is_array($result)
                        && in_array($result['status'] ?? null, ['paused', 'already_paused'], true))
                    ->pluck('smiles')
                    ->all();
                $successfulStructureIds = $smilesByStructureId
                    ->filter(fn (string $smiles): bool => in_array($smiles, $successfulSmiles, true))
                    ->keys()
                    ->all();
                $failedStructureIds = array_values(array_diff($structureIds, $successfulStructureIds));

                if ($failedStructureIds !== []) {
                    Prediction::query()
                        ->whereIn('id', $predictionIdsToPause)
                        ->whereIn('structure_id', $failedStructureIds)
                        ->update([
                            'remote_paused_at' => null,
                            'remote_pause_reason' => null,
                        ]);
                    $this->reconciliationErrors += count($failedStructureIds);
                    $this->warnError('The remote service did not pause '.count($failedStructureIds).' structure(s).');
                }

                $successfullyPaused = Prediction::query()
                    ->whereIn('id', $predictionIdsToPause)
                    ->whereIn('structure_id', $successfulStructureIds)
                    ->get();

                $successfullyPaused->each(function (Prediction $prediction) use ($reason): void {
                    $prediction->forceFill([
                        'logs' => $prediction->logsWithWorkerEvent(
                            $reason,
                            ['priority' => $prediction->priority],
                            'REMOTE PAUSE',
                        ),
                    ])->save();
                });
                $paused += $successfullyPaused->count();
            } catch (Throwable $throwable) {
                Prediction::query()
                    ->whereIn('id', $predictionIdsToPause)
                    ->update([
                        'remote_paused_at' => null,
                        'remote_pause_reason' => null,
                    ]);
                $this->reconciliationErrors += count($structureIds);
                $this->warnError('Remote prediction pause failed: '.$throwable->getMessage());
            }
        }

        return $paused;
    }

    /**
     * @param  int[]  $datasetIds
     */
    private function notifyFinishedDatasets(array $datasetIds): void
    {
        $notificationService = app(NotificationService::class);
        $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        PredictionDataset::query()
            ->whereIn('id', $datasetIds)
            ->whereNotNull('user_id')
            ->whereNull('finished_notification_sent_at')
            ->get()
            ->each(function (PredictionDataset $dataset) use ($notificationService, $frontendUrl): void {
                $stats = $dataset->calculateProgressStats();
                $state = $stats['state'];

                // Only notify when all predictions are done (finished or failed)
                if (! in_array($state, [PredictionDataset::STATE_FINISHED, PredictionDataset::STATE_FINISHED_WITH_ERRORS, PredictionDataset::STATE_FAILED], true)) {
                    return;
                }

                $user = User::find($dataset->user_id);
                if (! $user) {
                    return;
                }

                $membrane = $dataset->predictionMembrane?->name ?? 'N/A';
                $method = Prediction::enumMethod($dataset->method_type);
                $s = $stats['stats'];

                $templateKey = $state === PredictionDataset::STATE_FINISHED_WITH_ERRORS
                    ? NotificationTemplate::KEY_PREDICTION_JOB_FINISHED_WITH_ERRORS
                    : NotificationTemplate::KEY_PREDICTION_JOB_FINISHED;

                $notificationService->send($user, $templateKey, [
                    'comment' => $dataset->comment ?: "Dataset #{$dataset->id}",
                    'total' => $s['total'],
                    'done' => $s['done'],
                    'failed' => $s['failed'],
                    'membrane' => $membrane,
                    'method' => $method,
                    'dataset_url' => "{$frontendUrl}/lab/running-predictions?token={$dataset->token}",
                ]);

                $dataset->forceFill(['finished_notification_sent_at' => now()])->save();
            });
    }

    private function integerOption(string $name, int $default): int
    {
        $value = $this->option($name);

        return max(0, $value === null ? $default : (int) $value);
    }

    /**
     * Writes the error to the output and keeps it when it is the first error
     * of the run (see $firstError).
     */
    private function warnError(string $message, bool $isRejection = false): void
    {
        if ($this->firstError === null || ($this->firstErrorIsRejection && ! $isRejection)) {
            $this->firstError = $message;
            $this->firstErrorIsRejection = $isRejection;
        }

        $this->warn($message);
    }
}
