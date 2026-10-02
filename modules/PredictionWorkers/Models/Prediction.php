<?php

namespace Modules\PredictionWorkers\Models;

use App\Models\Filesystem;
use Carbon\CarbonInterface;
use EloquentFilter\Filterable;
use finfo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\PredictionWorkers\DTO\RemotePrediction\RemotePredictionCalculation;
use Modules\PredictionWorkers\DTO\RemotePrediction\RemotePredictionFile;
use Modules\PredictionWorkers\DTO\RemotePrediction\RemotePredictionJobSnapshot;
use Modules\PredictionWorkers\DTO\RemotePrediction\RemotePredictionJobSubmission;
use Modules\PredictionWorkers\Enums\RemotePredictionArtifact;
use Modules\PredictionWorkers\Enums\RemotePredictionStatus;
use Modules\PredictionWorkers\Enums\RemotePredictionStep;
use Modules\PredictionWorkers\Exceptions\RemotePredictionException;
use Modules\PredictionWorkers\Services\CosmoXmlParser;
use Modules\PredictionWorkers\Services\RemotePrediction\RemotePredictionClient;
use RuntimeException;
use Throwable;
use ZipArchive;

class Prediction extends PredictionBaseModel
{
    use Filterable;

    protected $attributes = [
        'priority' => self::PRIORITY_MEDIUM,
    ];

    const PRIORITY_LOW = 1;

    const PRIORITY_MEDIUM = 2;

    const PRIORITY_HIGH = 3;

    /** STATES */
    const STATE_STOPPED = 0;

    const STATE_PREPARED = 1;

    const STATE_ERROR = 2;

    const STATE_REMOVE = 3;

    const STATE_RUNNING = 4;

    const STATE_FINISHED = 5;

    /** COSMO STEPS */
    const STEP_PENDING = 0;

    const STEP_IONIZED = 1;

    const STEP_SDF_READY = 2;

    const STEP_OPTIMIZATION = 3;

    const STEP_COSMO = 4;

    const STEP_RESULT_DOWNLOAD = 5;

    const STEP_RESULT_PARSE = 6;

    const STEP_RESULT_DB_STORE = 7;

    public static $enum_steps = [
        self::STEP_PENDING => 'Pending',
        self::STEP_IONIZED => 'Ionized',
        self::STEP_SDF_READY => 'Make SDF',
        self::STEP_OPTIMIZATION => 'Structure optimization',
        self::STEP_COSMO => 'COSMO prediction',
        self::STEP_RESULT_DOWNLOAD => 'Result prepared for parsing',
        self::STEP_RESULT_PARSE => 'Result Parsed',
        self::STEP_RESULT_DB_STORE => 'Result Stored',
    ];

    public static $enum_states = [
        self::STATE_STOPPED => 'Stopped',
        self::STATE_PREPARED => 'Prepared',
        self::STATE_RUNNING => 'Running',
        self::STATE_FINISHED => 'Finished',
        self::STATE_ERROR => 'Error',
        self::STATE_REMOVE => 'Remove',
    ];

    public static $enum_priorities = [
        self::PRIORITY_LOW => 'Low',
        self::PRIORITY_MEDIUM => 'Medium',
        self::PRIORITY_HIGH => 'High',
    ];

    public static $enum_remote_statuses = [
        'paused' => 'Paused',
        'pending' => 'Pending',
        'queued' => 'Queued',
        'running' => 'Running',
        'waiting_for_conformers' => 'Preparing conformers',
        'waiting_for_script' => 'Waiting for sources',
        'completed' => 'Completed',
        'failed' => 'Failed',
    ];

    public static function finalStep(): int
    {
        return self::STEP_RESULT_DB_STORE;
    }

    /**
     * @return array<int>
     */
    public static function failedStates(): array
    {
        return [
            self::STATE_ERROR,
            self::STATE_REMOVE,
            self::STATE_STOPPED,
        ];
    }

    /**
     * @return array<string>
     */
    public static function activeRemoteStatuses(): array
    {
        return [
            RemotePredictionStatus::PENDING->value,
            RemotePredictionStatus::QUEUED->value,
            RemotePredictionStatus::RUNNING->value,
            RemotePredictionStatus::WAITING_FOR_CONFORMERS->value,
            RemotePredictionStatus::WAITING_FOR_SCRIPT->value,
        ];
    }

    public static function progressStepValue(?int $step, ?int $state = null, mixed $resultId = null): int
    {
        if ($resultId !== null) {
            return self::finalStep();
        }

        if ($step === null || $step < 0) {
            return 0;
        }

        if ($step >= self::finalStep()) {
            return self::finalStep();
        }

        return min($step, self::finalStep());
    }

    public static function progressPercent(?int $step, ?int $state = null, mixed $resultId = null): int
    {
        if (self::finalStep() === 0) {
            return 0;
        }

        return (int) round((self::progressStepValue($step, $state, $resultId) / self::finalStep()) * 100);
    }

    public static function enumMethod($method): string
    {
        return static::methods()->get($method)?->label ?? (string) $method;
    }

    /**
     * Short code used in COSMO result file paths (e.g. "perm"). Falls back to a
     * slug of the method key when the prediction_methods row has none set.
     */
    public static function methodShortKey($method): string
    {
        return static::methods()->get($method)?->short_key
            ?: Str::slug((string) $method, '-');
    }

    public static function enumStep($step): string
    {
        return self::$enum_steps[$step] ?? 'N/A';
    }

    public static function enumState($state): string
    {
        return self::$enum_states[$state] ?? 'N/A';
    }

    public function isRemotePaused(): bool
    {
        return $this->remote_paused_at !== null;
    }

    public function effectiveState(): string
    {
        if ($this->isRemotePaused()) {
            return 'paused';
        }

        return match ((int) $this->state) {
            self::STATE_STOPPED => 'stopped',
            self::STATE_PREPARED => 'prepared',
            self::STATE_ERROR => 'error',
            self::STATE_REMOVE => 'remove',
            self::STATE_RUNNING => 'running',
            self::STATE_FINISHED => 'finished',
            default => 'unknown',
        };
    }

    public function effectiveStateLabel(): string
    {
        return $this->isRemotePaused() ? 'Paused' : self::enumState($this->state);
    }

    public function effectiveRemoteStatus(): ?string
    {
        return $this->isRemotePaused() ? 'paused' : $this->remote_status;
    }

    public function effectiveRemoteStatusLabel(): ?string
    {
        return self::enumRemoteStatus($this->effectiveRemoteStatus());
    }

    public static function enumRemoteStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        return self::$enum_remote_statuses[$status] ?? $status;
    }

    public static function enumPriority($priority): string
    {
        return self::$enum_priorities[$priority] ?? 'N/A';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'logs' => 'array',
            'remote_submitted_at' => 'datetime',
            'remote_heartbeat_at' => 'datetime',
            'remote_last_status_at' => 'datetime',
            'remote_finished_at' => 'datetime',
            'remote_paused_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * In-process cache of prediction_methods, keyed by method key. This table
     * rarely changes and these lookups can run once per row inside loops
     * (e.g. RunPredictionsWorker), so avoid a query per call.
     */
    protected static ?Collection $methodsCache = null;

    protected static function methods(): Collection
    {
        return static::$methodsCache ??= PredictionMethod::query()->get()->keyBy('key');
    }

    /**
     * All methods that have a remote prediction mapping, regardless of
     * `enabled` - used wherever already-existing predictions/datasets need to
     * keep being processed even if the method was since disabled for new
     * uploads (e.g. the remote submission worker).
     *
     * @return array<string, string>
     */
    public static function remotePredictionMethodOptions(): array
    {
        return static::methods()
            ->mapWithKeys(fn (PredictionMethod $method): array => [$method->key => $method->label])
            ->all();
    }

    /**
     * Methods currently allowed for new prediction/dataset uploads - used by
     * creation-facing validation and the frontend "new prediction" form.
     *
     * @return array<string, string>
     */
    public static function enabledPredictionMethodOptions(): array
    {
        return static::methods()
            ->where('enabled', true)
            ->mapWithKeys(fn (PredictionMethod $method): array => [$method->key => $method->label])
            ->all();
    }

    public static function hasRemotePredictionMethod(?string $methodType): bool
    {
        return $methodType !== null && static::methods()->has($methodType);
    }

    /**
     * Nullable variant of remotePredictionMethod() for places that just want
     * to display the remote key without needing a Prediction instance and
     * without throwing when the method is unmapped.
     */
    public static function remoteMethodKeyFor(?string $methodType): ?string
    {
        if ($methodType === null) {
            return null;
        }

        return static::methods()->get($methodType)?->remote_key;
    }

    public function remotePredictionMethod(): string
    {
        $methodType = (string) $this->method_type;
        $remoteKey = static::remoteMethodKeyFor($methodType);

        if ($remoteKey === null) {
            throw new RuntimeException("Prediction method {$methodType} has no remote prediction mapping.");
        }

        return $remoteKey;
    }

    public function predictionDatasets(): BelongsToMany
    {
        return $this->belongsToMany(PredictionDataset::class, 'prediction_has_datasets', 'prediction_id', 'dataset_id')
            ->withTimestamps();
    }

    public function predictionResult(): BelongsTo
    {
        return $this->belongsTo(PredictionResult::class, 'result_id');
    }

    public function predictionStructure(): BelongsTo
    {
        return $this->belongsTo(PredictionStructure::class, 'structure_id');
    }

    public function predictionMembrane(): BelongsTo
    {
        return $this->belongsTo(PredictionMembrane::class, 'membrane_id');
    }

    public function submitToRemotePrediction(
        ?string $preferredMembraneKey = null,
        ?RemotePredictionClient $client = null,
    ): RemotePredictionJobSubmission {
        $client = $this->remotePredictionClient($client);
        $membraneKey = $this->remotePredictionMembraneKey($preferredMembraneKey, $client);

        try {
            return $client->createJob(
                $this->remotePredictionSmiles(),
                $membraneKey,
                (float) $this->temperature,
                $this->remotePredictionMethod(),
            );
        } catch (RemotePredictionException $e) {
            if (! str_contains(strtolower($e->getMessage()), 'unknown membrane key')) {
                throw $e;
            }

            $membrane = $this->predictionMembrane;

            if (! $membrane?->hasRemotePredictionDefinitionFile()) {
                throw $e;
            }

            $membraneKey = $membrane->uploadToRemotePrediction($preferredMembraneKey, $client)->key;

            return $client->createJob(
                $this->remotePredictionSmiles(),
                $membraneKey,
                (float) $this->temperature,
                $this->remotePredictionMethod(),
            );
        }
    }

    public function submitAndStoreRemotePrediction(
        ?string $preferredMembraneKey = null,
        ?RemotePredictionClient $client = null,
    ): RemotePredictionJobSubmission {
        $submission = $this->submitToRemotePrediction($preferredMembraneKey, $client);

        $this->forceFill([
            'remote_method' => $this->remotePredictionMethod(),
            'remote_calculation_id' => $submission->calculationId,
            'remote_molecule_id' => $submission->moleculeId,
            'remote_status' => $submission->calculationStatus instanceof RemotePredictionStatus
                ? $submission->calculationStatus->value
                : (string) $submission->calculationStatus,
            'remote_submitted_at' => $this->remote_submitted_at ?? now(),
            'remote_last_status_at' => now(),
            'remote_error_message' => null,
            'state' => self::STATE_RUNNING,
            'step' => self::STEP_PENDING,
        ])->save();

        $moleculeStatus = $submission->moleculeStatus instanceof RemotePredictionStatus
            ? $submission->moleculeStatus->value
            : (string) $submission->moleculeStatus;

        $this->predictionStructure?->forceFill([
            'remote_molecule_status' => $moleculeStatus ?: null,
        ])->save();

        return $submission;
    }

    public function remotePredictionStatus(
        int $eventsLimit = 30,
        ?RemotePredictionClient $client = null,
    ): RemotePredictionJobSnapshot {
        $client = $this->remotePredictionClient($client);
        $membraneKey = $this->remotePredictionMembraneKey(client: $client);

        return $client->jobStatus(
            $this->remotePredictionSmiles(),
            $eventsLimit,
            $membraneKey,
            (float) $this->temperature,
        );
    }

    public function requeueRemotePrediction(
        ?RemotePredictionClient $client = null,
    ): RemotePredictionJobSnapshot {
        $client = $this->remotePredictionClient($client);

        return $client->requeueJob(
            $this->remotePredictionSmiles(),
            $this->remotePredictionMembraneKey(client: $client),
            (float) $this->temperature,
        );
    }

    public function forceRequeueRemotePrediction(
        RemotePredictionStep $step,
        ?RemotePredictionClient $client = null,
    ): RemotePredictionJobSnapshot {
        $client = $this->remotePredictionClient($client);

        return $client->forceRequeueJob(
            $this->remotePredictionSmiles(),
            $this->remotePredictionMembraneKey(client: $client),
            (float) $this->temperature,
            $step,
        );
    }

    public function requeueAndStoreRemotePrediction(
        ?RemotePredictionStep $step = null,
        bool $force = false,
        ?RemotePredictionClient $client = null,
    ): RemotePredictionJobSnapshot {
        if ($force && $step === null) {
            throw new RuntimeException('A target step is required for force requeue.');
        }

        if ($force && ! array_key_exists($step->value, $this->forceRequeueStepOptions($client))) {
            throw new RuntimeException("Remote step [{$step->value}] has not been reached by this prediction.");
        }

        $snapshot = $force
            ? $this->forceRequeueRemotePrediction($step, $client)
            : $this->requeueRemotePrediction($client);
        $requeuedStep = $step ?? $this->remoteStepFromRequeueSnapshot($snapshot);
        $previousResultId = $this->result_id;

        $this->forceFill([
            'result_id' => null,
            'remote_status' => RemotePredictionStatus::QUEUED->value,
            'remote_current_step' => $requeuedStep?->value,
            'remote_last_status_at' => now(),
            'remote_finished_at' => null,
            'remote_error_message' => null,
            'state' => self::STATE_RUNNING,
            'step' => $this->stepForRemoteStep(null, $requeuedStep?->value),
            'logs' => $this->logsWithWorkerEvent(
                $force ? 'Remote prediction force requeued.' : 'Remote prediction requeued.',
                [
                    'step' => $requeuedStep?->value,
                    'force' => $force,
                    'previous_result_id' => $previousResultId,
                ],
                'REMOTE REQUEUE',
            ),
        ])->save();

        return $snapshot;
    }

    /**
     * @return array<string, string>
     */
    public function forceRequeueStepOptions(?RemotePredictionClient $client = null): array
    {
        $snapshot = $this->remotePredictionStatus(0, $client);
        $availableSteps = [RemotePredictionStep::RDKIT->value => true];

        foreach ($snapshot->steps as $step) {
            if (
                $step->step instanceof RemotePredictionStep
                && (
                    $this->remoteStatusValue($step->status) !== RemotePredictionStatus::PENDING->value
                    || $step->attempts > 0
                    || $step->startedAt !== null
                )
            ) {
                $availableSteps[$step->step->value] = true;
            }
        }

        if ($snapshot->currentStep instanceof RemotePredictionStep) {
            $availableSteps[$snapshot->currentStep->value] = true;
        }

        foreach ($snapshot->conformers as $conformer) {
            if ($conformer->currentStep instanceof RemotePredictionStep) {
                $availableSteps[$conformer->currentStep->value] = true;
            }

            foreach ($conformer->steps as $step) {
                if (
                    $step->step instanceof RemotePredictionStep
                    && (
                        $this->remoteStatusValue($step->status) !== RemotePredictionStatus::PENDING->value
                        || $step->attempts > 0
                        || $step->startedAt !== null
                    )
                ) {
                    $availableSteps[$step->step->value] = true;
                }
            }
        }

        $calculation = filled($this->remote_calculation_id)
            ? $snapshot->calculationById((string) $this->remote_calculation_id)
            : null;

        if ($calculation !== null && in_array(
            $this->remoteStatusValue($calculation->status),
            [
                RemotePredictionStatus::QUEUED->value,
                RemotePredictionStatus::RUNNING->value,
                RemotePredictionStatus::WAITING_FOR_SCRIPT->value,
                RemotePredictionStatus::COMPLETED->value,
                RemotePredictionStatus::FAILED->value,
            ],
            true,
        )) {
            $availableSteps[RemotePredictionStep::COSMO->value] = true;
        }

        return collect(RemotePredictionStep::cases())
            ->filter(fn (RemotePredictionStep $step): bool => isset($availableSteps[$step->value]))
            ->mapWithKeys(fn (RemotePredictionStep $step): array => [$step->value => $step->label()])
            ->all();
    }

    public function lastRemotePredictionStepForRequeue(): RemotePredictionStep
    {
        if ($this->remote_current_step) {
            $remoteStep = RemotePredictionStep::tryFrom((string) $this->remote_current_step);

            if ($remoteStep !== null) {
                return $remoteStep;
            }
        }

        return match (true) {
            (int) $this->step >= self::STEP_COSMO => RemotePredictionStep::COSMO,
            (int) $this->step >= self::STEP_OPTIMIZATION => RemotePredictionStep::OPTIMIZATION_TURBOMOLE,
            (int) $this->step >= self::STEP_SDF_READY => RemotePredictionStep::CONFORMERS,
            default => RemotePredictionStep::RDKIT,
        };
    }

    public function remotePredictionCalculation(
        ?RemotePredictionClient $client = null,
    ): ?RemotePredictionCalculation {
        $client = $this->remotePredictionClient($client);
        $membraneKey = $this->remotePredictionMembraneKey(client: $client);
        $snapshot = $client->jobStatus(
            $this->remotePredictionSmiles(),
            0,
            $membraneKey,
            (float) $this->temperature,
        );

        if (filled($this->remote_calculation_id)) {
            return $snapshot->calculationById((string) $this->remote_calculation_id)
                ?? $snapshot->calculationFor($membraneKey, (float) $this->temperature);
        }

        return $snapshot->calculationFor($membraneKey, (float) $this->temperature);
    }

    public function refreshRemotePredictionState(
        int $eventsLimit = 100,
        ?RemotePredictionClient $client = null,
    ): RemotePredictionJobSnapshot {
        $snapshot = $this->remotePredictionStatus($eventsLimit, $client);
        $this->refresh();

        if ($this->remote_paused_at !== null) {
            return $snapshot;
        }
        $calculation = filled($this->remote_calculation_id)
            ? $snapshot->calculationById((string) $this->remote_calculation_id)
            : null;
        $calculation ??= $snapshot->calculationFor(
            $this->remotePredictionMembraneKey(client: $this->remotePredictionClient($client)),
            (float) $this->temperature,
        );

        $calcStatus = $this->remoteStatusValue($calculation?->status);
        $cosmoPhase = in_array($calcStatus, [
            RemotePredictionStatus::QUEUED->value,
            RemotePredictionStatus::RUNNING->value,
            RemotePredictionStatus::WAITING_FOR_SCRIPT->value,
            RemotePredictionStatus::COMPLETED->value,
            RemotePredictionStatus::FAILED->value,
        ], true);
        $status = $cosmoPhase ? $calcStatus : $this->remoteStatusValue($snapshot->status);
        $currentStep = $this->remoteStepValue($this->resolveCurrentStep($snapshot, $calculation));
        $heartbeatAt = $this->heartbeatForSnapshot($snapshot, $calculation, $currentStep);

        $this->forceFill([
            'remote_method' => $this->remote_method ?: $this->remotePredictionMethod(),
            'remote_calculation_id' => $calculation?->id ?: $this->remote_calculation_id,
            'remote_molecule_id' => $calculation?->moleculeId ?: ($snapshot->id ?: $this->remote_molecule_id),
            'remote_status' => $status,
            'remote_current_step' => $currentStep,
            'remote_heartbeat_at' => $heartbeatAt,
            'remote_last_status_at' => now(),
            'remote_finished_at' => $this->remoteFinishedAt($status, $calculation?->finishedAt),
            'remote_error_message' => $status === RemotePredictionStatus::FAILED->value
                ? $calculation?->message
                : null,
            'logs' => $snapshot->toArray()['events'] ?? [],
            'state' => $this->stateForRemoteStatus($status),
            'step' => $this->stepForRemoteStep($status, $currentStep),
        ])->save();

        $moleculeStatus = $this->remoteStatusValue($snapshot->status);
        $this->predictionStructure?->forceFill([
            'remote_molecule_status' => $moleculeStatus ?: null,
        ])->save();

        return $snapshot;
    }

    public function downloadRemotePredictionResult(
        ?RemotePredictionClient $client = null,
    ): RemotePredictionFile {
        return $this->remotePredictionClient($client)
            ->downloadCalculation($this->remotePredictionCalculationId($client));
    }

    public function downloadRemotePredictionArtifact(
        RemotePredictionArtifact $artifact,
        ?RemotePredictionClient $client = null,
    ): RemotePredictionFile {
        return $this->remotePredictionClient($client)
            ->downloadArtifact($this->remotePredictionCalculationId($client), $artifact);
    }

    public function downloadRemotePredictionConformers(
        ?RemotePredictionClient $client = null,
    ): RemotePredictionFile {
        return $this->downloadRemotePredictionArtifact(RemotePredictionArtifact::CONFORMERS, $client);
    }

    public function downloadRemotePredictionSdf(
        ?RemotePredictionClient $client = null,
    ): RemotePredictionFile {
        return $this->downloadRemotePredictionArtifact(RemotePredictionArtifact::SDF, $client);
    }

    public function downloadRemotePredictionCosmoArchive(
        ?RemotePredictionClient $client = null,
    ): RemotePredictionFile {
        return $this->downloadRemotePredictionArtifact(RemotePredictionArtifact::COSMO, $client);
    }

    public function storeRemotePredictionResult(
        ?RemotePredictionClient $client = null,
    ): PredictionResult {
        if ($this->result_id !== null) {
            return $this->predictionResult;
        }

        if ($this->remote_status !== RemotePredictionStatus::COMPLETED->value) {
            throw new RuntimeException("Prediction {$this->getKey()} is not completed on remote server.");
        }

        $download = $this->downloadRemotePredictionResult($client);
        $diskName = $this->remotePredictionResultsDiskName();
        $path = $this->remotePredictionResultPath($download);

        if (! Storage::disk($diskName)->put($path, $download->contents)) {
            throw new RuntimeException("Unable to store remote prediction result to [{$diskName}:{$path}].");
        }

        $parsed = $this->parseRemotePredictionResult($download, $diskName, $path);

        return DB::connection($this->getConnectionName())->transaction(function () use ($download, $diskName, $path, $parsed): PredictionResult {
            $file = PredictionFile::query()->create([
                'type' => $this->remotePredictionResultFileType($download),
                'name' => $this->remotePredictionResultFilename($download),
                'mime' => $download->mimeType,
                'storage' => $diskName,
                'path' => $path,
            ]);

            $result = PredictionResult::query()->create([
                'file_id' => $file->id,
                'data' => $parsed,
            ]);

            $this->forceFill([
                'result_id' => $result->id,
                'state' => self::STATE_FINISHED,
                // Not the final step - ImportFinishedPredictionResults still needs to
                // turn this parsed result into a real interaction record before this
                // prediction is truly "stored" (step advances to STEP_RESULT_DB_STORE there).
                'step' => self::STEP_RESULT_PARSE,
                'remote_last_status_at' => now(),
                'remote_finished_at' => $this->remote_finished_at ?? now(),
                'remote_error_message' => null,
                'logs' => $this->logsWithWorkerEvent('Remote prediction result downloaded and parsed.', [
                    'disk' => $diskName,
                    'path' => $path,
                    'filename' => $download->filename,
                    'size' => $download->size(),
                ], 'RESULT DOWNLOAD'),
            ])->save();

            return $result;
        });
    }

    /**
     * Store the remote COSMO conformer files (.ccf) next to the stored prediction result.
     * Returns the number of stored files, 0 when they are already stored.
     */
    public function storeRemotePredictionCosmoFiles(
        ?RemotePredictionClient $client = null,
    ): int {
        if ($this->hasStoredCosmoFiles()) {
            return 0;
        }

        $download = filled($this->remote_calculation_id)
            ? $this->remotePredictionClient($client)->downloadArtifact((string) $this->remote_calculation_id, RemotePredictionArtifact::COSMO)
            : $this->downloadRemotePredictionCosmoArchive($client);

        return $this->storeCosmoFiles($this->cosmoFilesFromDownload($download), 'remote prediction service');
    }

    /**
     * Store conformer COSMO files into the folder of the stored prediction result (next to cosmo.xml).
     * Only adds files: an existing file with the same name is kept when identical, otherwise nothing is stored.
     *
     * @param  array<string, string>  $cosmoFiles  filename => contents
     */
    public function storeCosmoFiles(array $cosmoFiles, string $source): int
    {
        [$diskName, $folder] = $this->cosmoFilesLocation();
        $disk = Storage::disk($diskName);
        $existingFiles = array_flip(array_map('basename', $disk->files($folder)));
        $missingFiles = [];

        foreach ($cosmoFiles as $filename => $contents) {
            if (! isset($existingFiles[$filename])) {
                $missingFiles[$filename] = $contents;

                continue;
            }

            if (md5((string) $disk->get("{$folder}/{$filename}")) !== md5($contents)) {
                throw new RuntimeException("COSMO file [{$diskName}:{$folder}/{$filename}] already exists with different contents.");
            }
        }

        foreach ($missingFiles as $filename => $contents) {
            if (! $disk->put("{$folder}/{$filename}", $contents)) {
                throw new RuntimeException("Unable to store COSMO file to [{$diskName}:{$folder}/{$filename}].");
            }
        }

        DB::connection($this->getConnectionName())->transaction(function () use ($cosmoFiles, $diskName, $folder, $source): void {
            $finfo = new finfo(FILEINFO_MIME_TYPE);

            foreach ($cosmoFiles as $filename => $contents) {
                PredictionFile::query()->create([
                    'type' => PredictionFile::TYPE_COSMO_CONFORMERS,
                    'name' => $filename,
                    'mime' => $finfo->buffer($contents) ?: 'application/octet-stream',
                    'hash' => md5($contents),
                    'storage' => $diskName,
                    'path' => "{$folder}/{$filename}",
                ]);
            }

            $this->forceFill([
                'logs' => $this->logsWithWorkerEvent('COSMO files stored.', [
                    'source' => $source,
                    'disk' => $diskName,
                    'folder' => $folder,
                    'files' => array_keys($cosmoFiles),
                    'size' => array_sum(array_map('strlen', $cosmoFiles)),
                ], 'COSMO DOWNLOAD'),
            ])->save();
        });

        return count($cosmoFiles);
    }

    public function hasStoredCosmoFiles(): bool
    {
        [$diskName, $folder] = $this->cosmoFilesLocation();

        return PredictionFile::query()
            ->where('type', PredictionFile::TYPE_COSMO_CONFORMERS)
            ->where('storage', $diskName)
            ->where('path', 'like', addcslashes($folder, '\\%_').'/%')
            ->exists();
    }

    /**
     * Disk and folder of the stored result file - COSMO files are stored next to it.
     *
     * @return array{0: string, 1: string}
     */
    public function cosmoFilesLocation(): array
    {
        $resultFile = $this->predictionResult?->file;

        if ((int) $this->state !== self::STATE_FINISHED || ! $resultFile) {
            throw new RuntimeException("Prediction {$this->getKey()} has no finished result.");
        }

        return [$resultFile->storage, dirname($resultFile->path)];
    }

    /**
     * Extract conformer COSMO files from the downloaded COSMO artifact. The remote service zips
     * the whole calculation directory, conformer files are stored as conformers/cNNN/{molecule}_cNNN.ccf.
     *
     * @return array<string, string> filename => contents
     */
    private function cosmoFilesFromDownload(RemotePredictionFile $download): array
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'remote-prediction-cosmo-');

        if ($temporaryPath === false || file_put_contents($temporaryPath, $download->contents) === false) {
            throw new RuntimeException("Unable to buffer remote COSMO artifact for prediction {$this->getKey()}.");
        }

        $archive = new ZipArchive;
        $isOpen = false;

        try {
            if ($archive->open($temporaryPath) !== true) {
                throw new RuntimeException("Remote COSMO artifact for prediction {$this->getKey()} is not a valid zip archive.");
            }

            $isOpen = true;
            $files = [];
            $entryNames = [];

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entryName = (string) ($archive->statIndex($index)['name'] ?? '');
                $entryNames[] = $entryName;

                if (! in_array(strtolower(pathinfo($entryName, PATHINFO_EXTENSION)), ['ccf', 'cosmo'], true)) {
                    continue;
                }

                $filename = $this->safeCosmoFilename(basename($entryName));

                // Same file name in different archive folders - keep the folder in the name.
                if (isset($files[$filename])) {
                    $filename = $this->safeCosmoFilename(str_replace('/', '_', trim($entryName, '/')));
                }

                $contents = $archive->getFromIndex($index);

                if (! is_string($contents)) {
                    throw new RuntimeException("Unable to read [{$entryName}] from remote COSMO artifact for prediction {$this->getKey()}.");
                }

                $files[$filename] = $contents;
            }

            if ($files === []) {
                throw new RuntimeException("Remote COSMO artifact for prediction {$this->getKey()} contains no .ccf/.cosmo files [".implode(', ', $entryNames).'].');
            }

            ksort($files, SORT_NATURAL);

            return $files;
        } finally {
            if ($isOpen) {
                $archive->close();
            }

            if (file_exists($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function safeCosmoFilename(string $filename): string
    {
        return Str::of(basename(trim($filename)))
            ->replaceMatches('/[^A-Za-z0-9._-]/', '_')
            ->toString();
    }

    /**
     * @return array<int, mixed>
     */
    private function parseRemotePredictionResult(
        RemotePredictionFile $download,
        string $diskName,
        string $path,
    ): array {
        $parser = new CosmoXmlParser;

        try {
            return $parser->parseString($download->contents)->jsonSerialize();
        } catch (Throwable $directParseException) {
            $xmlFromArchive = $this->cosmoXmlFromArchive($download->contents);

            if ($xmlFromArchive !== null) {
                try {
                    return $parser->parseString($xmlFromArchive)->jsonSerialize();
                } catch (Throwable $archiveParseException) {
                    throw new RuntimeException(
                        "Downloaded remote prediction result [{$diskName}:{$path}] archive XML could not be parsed: {$archiveParseException->getMessage()}",
                        previous: $archiveParseException,
                    );
                }
            }

            throw new RuntimeException(
                "Downloaded remote prediction result [{$diskName}:{$path}] could not be parsed: {$directParseException->getMessage()}",
                previous: $directParseException,
            );
        }
    }

    private function cosmoXmlFromArchive(string $contents): ?string
    {
        if (! str_starts_with($contents, 'PK') || ! class_exists(ZipArchive::class)) {
            return null;
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'remote-prediction-result-');

        if ($temporaryPath === false || file_put_contents($temporaryPath, $contents) === false) {
            return null;
        }

        $archive = new ZipArchive;
        $isOpen = false;

        try {
            if ($archive->open($temporaryPath) !== true) {
                return null;
            }

            $isOpen = true;

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);
                $entryName = is_array($entry) ? (string) ($entry['name'] ?? '') : '';

                if (! str_ends_with(strtolower($entryName), '.xml')) {
                    continue;
                }

                $xml = $archive->getFromIndex($index);

                if (is_string($xml) && str_contains($xml, '<micoutput')) {
                    return $xml;
                }
            }

            return null;
        } finally {
            if ($isOpen) {
                $archive->close();
            }

            if (file_exists($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function resolveCurrentStep(
        RemotePredictionJobSnapshot $snapshot,
        ?RemotePredictionCalculation $calculation,
    ): RemotePredictionStep|string|null {
        // Calculation is actively queued or running for COSMO — optimization phase is complete
        if ($calculation !== null && in_array(
            $this->remoteStatusValue($calculation->status),
            [
                RemotePredictionStatus::QUEUED->value,
                RemotePredictionStatus::RUNNING->value,
                RemotePredictionStatus::WAITING_FOR_SCRIPT->value,
            ],
            true,
        )) {
            return RemotePredictionStep::COSMO;
        }

        // Look for a running step inside conformers (optimization phase)
        foreach ($snapshot->conformers as $conformer) {
            $running = $conformer->steps->first(
                fn ($step): bool => $this->remoteStatusValue($step->status) === RemotePredictionStatus::RUNNING->value,
            );
            if ($running !== null) {
                return $running->step;
            }
        }

        // Look for a running step at molecule level (rdkit, conformers)
        $topRunning = $snapshot->steps->first(
            fn ($step): bool => $this->remoteStatusValue($step->status) === RemotePredictionStatus::RUNNING->value,
        );

        return $topRunning?->step ?? $snapshot->currentStep;
    }

    private function remoteStepFromRequeueSnapshot(RemotePredictionJobSnapshot $snapshot): RemotePredictionStep
    {
        foreach (['step', 'failed_step', 'first_failed_step', 'from_step'] as $key) {
            $step = data_get($snapshot->requeue, $key);

            if ($step instanceof RemotePredictionStep) {
                return $step;
            }

            if (is_string($step) && RemotePredictionStep::tryFrom($step) !== null) {
                return RemotePredictionStep::from($step);
            }
        }

        $failedSteps = [];

        foreach ($snapshot->steps as $step) {
            if (
                $step->step instanceof RemotePredictionStep
                && $this->remoteStatusValue($step->status) === RemotePredictionStatus::FAILED->value
            ) {
                $failedSteps[$step->step->value] = true;
            }
        }

        foreach ($snapshot->conformers as $conformer) {
            foreach ($conformer->steps as $step) {
                if (
                    $step->step instanceof RemotePredictionStep
                    && $this->remoteStatusValue($step->status) === RemotePredictionStatus::FAILED->value
                ) {
                    $failedSteps[$step->step->value] = true;
                }
            }
        }

        $calculation = filled($this->remote_calculation_id)
            ? $snapshot->calculationById((string) $this->remote_calculation_id)
            : null;

        if (
            $calculation !== null
            && $this->remoteStatusValue($calculation->status) === RemotePredictionStatus::FAILED->value
        ) {
            $failedSteps[RemotePredictionStep::COSMO->value] = true;
        }

        foreach (RemotePredictionStep::cases() as $step) {
            if (isset($failedSteps[$step->value])) {
                return $step;
            }
        }

        if ($snapshot->currentStep instanceof RemotePredictionStep) {
            return $snapshot->currentStep;
        }

        return $this->lastRemotePredictionStepForRequeue();
    }

    private function remotePredictionCalculationId(?RemotePredictionClient $client): string
    {
        $calculation = $this->remotePredictionCalculation($client);

        if (! $calculation) {
            throw new RuntimeException("RemotePrediction calculation for prediction {$this->getKey()} was not found.");
        }

        return $calculation->id;
    }

    private function remotePredictionResultsDiskName(): string
    {
        $filesystem = Filesystem::query()
            ->where('type', Filesystem::TYPE_PREDICTIONS_STORAGE)
            ->first();

        if (! $filesystem) {
            throw new RuntimeException('Prediction results filesystem is not configured.');
        }

        if (! $filesystem->isInitialized()) {
            throw new RuntimeException("Prediction results filesystem [{$filesystem->name}] is not initialized.");
        }

        return $filesystem->systemName;
    }

    private function remotePredictionResultPath(RemotePredictionFile $download): string
    {
        return $this->remotePredictionResultFolder().'/'.$this->remotePredictionResultFilename($download);
    }

    private function remotePredictionResultFileType(RemotePredictionFile $download): int
    {
        $filename = strtolower($download->filename);
        $mimeType = strtolower($download->mimeType);

        if (
            str_ends_with($filename, '.zip')
            || str_contains($mimeType, 'zip')
            || str_starts_with($download->contents, 'PK')
        ) {
            return PredictionFile::TYPE_RESULT_ARCHIVE;
        }

        return PredictionFile::TYPE_RESULT_COSMO_XML;
    }

    private function remotePredictionResultFolder(): string
    {
        return $this->predictionStructure->id
            .'/'
            .self::methodShortKey($this->method_type)
            .'_'
            .str_replace('/', '_', (string) $this->predictionMembrane->abbreviation)
            .'_'
            .str_replace('.', ',', number_format((float) $this->temperature, 1));
    }

    private function remotePredictionResultFilename(RemotePredictionFile $download): string
    {
        $filename = basename(trim($download->filename));

        if ($filename === '' || $filename === 'download') {
            return 'cosmo.xml';
        }

        return Str::of($filename)
            ->replaceMatches('/[^A-Za-z0-9._-]/', '_')
            ->toString();
    }

    /**
     * Keeps only the most recent entries - a prediction stuck retrying the
     * same failure indefinitely (e.g. every minute via the result-import
     * job) would otherwise grow `logs` without bound until it's large
     * enough to exhaust PHP's memory limit on decode/encode.
     */
    private const MAX_LOG_ENTRIES = 100;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, mixed>
     */
    public function logsWithWorkerEvent(string $message, array $payload = [], string $type = 'WORKER', string $context = 'success'): array
    {
        $logs = is_array($this->logs) ? $this->logs : [];
        $logs[] = [
            'type' => $type,
            'context' => $context,
            'message' => $message,
            'payload' => $payload,
            'timestamp' => now()->toIso8601String(),
        ];

        return array_slice($logs, -self::MAX_LOG_ENTRIES);
    }

    private function remoteStatusValue(RemotePredictionStatus|string|null $status): ?string
    {
        if ($status instanceof RemotePredictionStatus) {
            return $status->value;
        }

        return filled($status) ? (string) $status : null;
    }

    private function remoteStepValue(RemotePredictionStep|string|null $step): ?string
    {
        if ($step instanceof RemotePredictionStep) {
            return $step->value;
        }

        return filled($step) ? (string) $step : null;
    }

    private function heartbeatForSnapshot(
        RemotePredictionJobSnapshot $snapshot,
        ?RemotePredictionCalculation $calculation,
        ?string $currentStep,
    ): ?CarbonInterface {
        if ($currentStep !== null) {
            $step = $snapshot->steps->first(
                fn ($step): bool => $this->remoteStepValue($step->step) === $currentStep,
            );

            if ($step?->heartbeatAt) {
                return $step->heartbeatAt;
            }
        }

        $runningStep = $snapshot->steps->first(
            fn ($step): bool => $this->remoteStatusValue($step->status) === RemotePredictionStatus::RUNNING->value,
        );

        // During conformer optimization, heartbeats live inside each conformer's steps.
        // Find the most recent heartbeat across all conformer steps.
        $latestConformerHeartbeat = null;
        foreach ($snapshot->conformers as $conformer) {
            foreach ($conformer->steps as $step) {
                if ($step->heartbeatAt === null) {
                    continue;
                }
                if ($latestConformerHeartbeat === null || $step->heartbeatAt->gt($latestConformerHeartbeat)) {
                    $latestConformerHeartbeat = $step->heartbeatAt;
                }
            }
        }

        $candidates = array_filter([
            $runningStep?->heartbeatAt,
            $latestConformerHeartbeat,
            $calculation?->heartbeatAt,
        ]);

        if (empty($candidates)) {
            return null;
        }

        return collect($candidates)->sortDesc()->first();
    }

    private function remoteFinishedAt(?string $status, ?CarbonInterface $finishedAt): ?CarbonInterface
    {
        if (in_array($status, [
            RemotePredictionStatus::COMPLETED->value,
            RemotePredictionStatus::FAILED->value,
        ], true)) {
            return $finishedAt ?? $this->remote_finished_at ?? now();
        }

        return null;
    }

    private function stateForRemoteStatus(?string $status): int
    {
        return match ($status) {
            RemotePredictionStatus::FAILED->value => self::STATE_ERROR,
            RemotePredictionStatus::COMPLETED->value => self::STATE_RUNNING,
            default => self::STATE_RUNNING,
        };
    }

    private function stepForRemoteStep(?string $status, ?string $currentStep): int
    {
        if ($status === RemotePredictionStatus::COMPLETED->value) {
            return self::STEP_RESULT_DOWNLOAD;
        }

        if ($status === RemotePredictionStatus::FAILED->value) {
            return max((int) $this->step, self::STEP_PENDING);
        }

        return match ($currentStep) {
            RemotePredictionStep::RDKIT->value => self::STEP_SDF_READY,
            RemotePredictionStep::CONFORMERS->value,
            RemotePredictionStep::OPTIMIZATION_ORCA->value,
            RemotePredictionStep::OPTIMIZATION_TURBOMOLE->value => self::STEP_OPTIMIZATION,
            RemotePredictionStep::COSMO->value => self::STEP_COSMO,
            default => max((int) $this->step, self::STEP_PENDING),
        };
    }

    private function remotePredictionMembraneKey(
        ?string $preferredKey = null,
        ?RemotePredictionClient $client = null,
    ): string {
        $membrane = $this->predictionMembrane;

        if (! $membrane) {
            throw new RuntimeException("Prediction {$this->getKey()} has no membrane.");
        }

        return $membrane->ensureRemotePredictionKey($preferredKey, $this->remotePredictionClient($client));
    }

    private function remotePredictionSmiles(): string
    {
        $structure = $this->predictionStructure;

        if (! $structure) {
            throw new RuntimeException("Prediction {$this->getKey()} has no structure.");
        }

        return $structure->remotePredictionSmiles();
    }

    private function remotePredictionClient(?RemotePredictionClient $client): RemotePredictionClient
    {
        return $client ?? app(RemotePredictionClient::class);
    }
}
