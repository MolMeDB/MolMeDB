<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePredictionDatasetRequest;
use App\Http\Resources\PredictionDatasetResource;
use App\Http\Resources\PredictionResource;
use App\Http\Resources\PredictionStructureResource;
use App\Http\Resources\UserResource;
use App\Mail\PredictionsEmailVerificationMail;
use App\Models\FeedbackEmailVerification;
use App\Models\File;
use App\Models\Membrane;
use App\Models\NotificationTemplate;
use App\Rules\TurnstileToken;
use App\Services\EmailLoginService;
use App\Services\NotificationService;
use App\Services\PredictionAdminNotifier;
use App\Services\PredictionSmilesCanonicalizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionDataset;
use Modules\PredictionWorkers\Models\PredictionMembrane;
use Modules\PredictionWorkers\Models\PredictionStat;
use Modules\PredictionWorkers\Models\PredictionStructure;

class PredictionsController extends Controller
{
    public function serverStats(): array
    {
        $latestStat = PredictionStat::newest()->first();

        return [
            'data' => [
                'jobs' => [
                    'queued' => Prediction::where('state', Prediction::STATE_PREPARED)->count(),
                    'running' => Prediction::where('state', Prediction::STATE_RUNNING)
                        ->whereNull('remote_paused_at')
                        ->count(),
                    'paused' => Prediction::whereNotNull('remote_paused_at')->count(),
                    'completed' => Prediction::where('state', Prediction::STATE_FINISHED)->count(),
                    'failed' => Prediction::whereIn('state', Prediction::failedStates())->count(),
                ],
                'remote' => $latestStat ? [
                    'payload' => $latestStat->payload,
                    'fetched_at' => $latestStat->fetched_at?->toISOString(),
                    'server_url' => $latestStat->server_url,
                ] : null,
            ],
        ];
    }

    public function options(): array
    {
        $this->ensurePredictionMembranesAvailable();
        $availableMembraneIds = $this->availablePredictionMembraneRemoteIds();

        return [
            'data' => [
                'structure_validation' => config('prediction-workers.structure_validation'),
                'membranes' => PredictionMembrane::query()
                    ->select(['id', 'remote_id', 'name', 'abbreviation'])
                    ->whereIn('remote_id', $availableMembraneIds)
                    ->orderBy('abbreviation')
                    ->get()
                    ->map(fn (PredictionMembrane $membrane): array => [
                        'id' => $membrane->id,
                        'remote_id' => $membrane->remote_id,
                        'short_name' => $membrane->abbreviation,
                        'long_name' => $membrane->name,
                        'description' => null,
                        'show_more_link' => $membrane->remote_id
                            ? '/browse/membranes?id='.$membrane->remote_id
                            : null,
                    ])
                    ->values(),
                'methods' => collect(Prediction::enabledPredictionMethodOptions())
                    ->map(fn (string $label, string $method): array => [
                        'id' => $method,
                        'short_name' => $label,
                        'long_name' => null,
                        'description' => null,
                    ])
                    ->values(),
            ],
        ];
    }

    private const CODE_ATTEMPTS_LIMIT = 5;

    public function requestEmailVerification(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'turnstile_token' => ['required', 'string', new TurnstileToken($request->ip())],
        ]);
        $email = Str::lower($data['email']);

        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(15);

        FeedbackEmailVerification::query()->create([
            'email' => $email,
            'code_hash' => Hash::make($code),
            'expires_at' => $expiresAt,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        Mail::to($email)->queue(new PredictionsEmailVerificationMail($code, $expiresAt));

        return response()->json([
            'message' => 'Verification code has been sent.',
            'expires_at' => $expiresAt->toISOString(),
        ]);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'size:6'],
        ]);
        $email = Str::lower($data['email']);

        $verification = FeedbackEmailVerification::query()
            ->where('email', $email)
            ->whereNull('verified_at')
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (! $verification) {
            throw ValidationException::withMessages(['code' => ['Verification code is invalid or has expired.']]);
        }

        if ($verification->attempts >= self::CODE_ATTEMPTS_LIMIT) {
            throw ValidationException::withMessages(['code' => ['Too many invalid attempts. Please request a new code.']]);
        }

        if (! Hash::check($data['code'], $verification->code_hash)) {
            $verification->increment('attempts');
            throw ValidationException::withMessages(['code' => ['Verification code is invalid or has expired.']]);
        }

        $user = app(EmailLoginService::class)->authenticate($request, $verification, $email);

        return response()->json([
            'data' => UserResource::make($user)->resolve($request),
            'meta' => [
                'session_expires_at' => now()
                    ->addMinutes((int) config('session.lifetime'))
                    ->toISOString(),
            ],
        ]);
    }

    /**
     * Look up a dataset by its public token (no auth required).
     */
    public function datasetByToken(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:128']]);
        $dataset = PredictionDataset::query()->where('token', $data['token'])->first();

        if (! $dataset) {
            throw ValidationException::withMessages(['token' => ['Invalid or expired token.']]);
        }

        return response()->json(['data' => ['dataset_id' => $dataset->id]]);
    }

    public function validateSmiles(Request $request, PredictionSmilesCanonicalizer $canonicalizer): JsonResponse
    {
        $validated = $request->validate([
            'smiles' => ['required', 'array', 'min:1', 'max:'.StorePredictionDatasetRequest::MAX_MOLECULES],
            'smiles.*' => ['string', 'max:4000'],
        ]);

        return response()->json([
            'data' => $canonicalizer->canonicalize($validated['smiles']),
        ]);
    }

    public function storeDataset(Request $request, PredictionSmilesCanonicalizer $canonicalizer)
    {
        $this->ensurePredictionMembranesAvailable();
        $availableMembraneIds = $this->availablePredictionMembraneRemoteIds();

        $user = $request->user();
        abort_unless($user, 401);

        $validated = $request->validate((new StorePredictionDatasetRequest)->rules());
        $userId = $user->id;
        $priority = Prediction::PRIORITY_MEDIUM;
        $membraneIds = collect($validated['membranes'])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $methodTypes = collect($validated['methods'])
            ->map(fn ($method): string => (string) $method)
            ->unique()
            ->values();
        $canonicalized = $canonicalizer->canonicalize($validated['smiles']);
        $smiles = collect($canonicalized['smiles']);

        $existingMembraneIds = PredictionMembrane::query()
            ->whereIn('id', $membraneIds)
            ->whereIn('remote_id', $availableMembraneIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);

        if ($existingMembraneIds->count() !== $membraneIds->count()) {
            throw ValidationException::withMessages([
                'membranes' => ['One or more selected membranes are not available.'],
            ]);
        }

        $result = Cache::lock('predictions:dataset-store', 30)->block(
            15,
            fn (): array => DB::connection(config('database.default_predictions'))->transaction(
                function () use ($validated, $userId, $priority, $membraneIds, $methodTypes, $smiles): array {
                    $structures = $smiles->map(fn (string $smiles): PredictionStructure => PredictionStructure::query()
                        ->firstOrCreate(['canonical_smiles' => $smiles]));

                    $datasets = [];
                    $predictionsCreated = 0;

                    foreach ($membraneIds as $membraneId) {
                        foreach ($methodTypes as $methodType) {
                            $dataset = PredictionDataset::query()->create([
                                'comment' => $validated['description'],
                                'token' => Str::random(32),
                                'user_id' => $userId,
                                'temperature' => (float) $validated['temperature'],
                                'membrane_id' => $membraneId,
                                'method_type' => $methodType,
                                'priority' => $priority,
                            ]);

                            foreach ($structures as $structure) {
                                $prediction = Prediction::query()->firstOrCreate(
                                    [
                                        'structure_id' => $structure->id,
                                        'membrane_id' => $membraneId,
                                        'method_type' => $methodType,
                                        'temperature' => (float) $validated['temperature'],
                                    ],
                                    [
                                        'result_id' => null,
                                        'state' => Prediction::STATE_PREPARED,
                                        'step' => Prediction::STEP_PENDING,
                                        'priority' => $priority,
                                        'remote_method' => (new Prediction(['method_type' => $methodType]))->remotePredictionMethod(),
                                        'logs' => [],
                                    ],
                                );

                                $prediction->forceFill(['priority' => Prediction::PRIORITY_MEDIUM])->save();

                                $dataset->predictions()->syncWithoutDetaching([$prediction->id]);
                                $predictionsCreated++;
                            }

                            $datasets[] = $dataset->fresh(['predictionMembrane', 'user']);
                        }
                    }

                    return [
                        'datasets' => $datasets,
                        'predictions_count' => $predictionsCreated,
                    ];
                }
            ),
        );

        $notificationService = app(NotificationService::class);
        $adminNotifier = app(PredictionAdminNotifier::class);
        $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        $uploaderLabel = $user->name;

        foreach ($result['datasets'] as $dataset) {
            $membrane = $dataset->predictionMembrane?->name ?? 'N/A';
            $method = Prediction::enumMethod($dataset->method_type);
            $datasetUrl = "{$frontendUrl}/lab/running-predictions?token={$dataset->token}";
            $notifData = [
                'comment' => $dataset->comment ?: "Dataset #{$dataset->id}",
                'total' => $smiles->count(),
                'membrane' => $membrane,
                'method' => $method,
                'dataset_url' => $datasetUrl,
            ];

            $notificationService->send($user, NotificationTemplate::KEY_PREDICTION_JOB_SUBMITTED, $notifData);

            $adminNotifier->notify(NotificationTemplate::KEY_PREDICTION_ADMIN_NEW_SUBMISSION, [
                ...$notifData,
                'uploader_label' => $uploaderLabel,
            ]);
        }

        return response()->json([
            'message' => 'Prediction calculations were queued.',
            'data' => [
                'datasets' => PredictionDatasetResource::collection(collect($result['datasets']))->resolve($request),
                'dataset_ids' => collect($result['datasets'])->pluck('id')->values(),
                'predictions_count' => $result['predictions_count'],
                'duplicates_removed' => $canonicalized['duplicates_removed'],
            ],
        ], 201);
    }

    public function index_datasets(Request $request)
    {
        $perPage = $this->perPage($request);

        $user = $request->user();
        abort_unless($user, 401);

        $query = PredictionDataset::with(['user', 'predictionMembrane']);

        if (! Gate::allows('viewAny', PredictionDataset::class)) {
            $query->where('user_id', $user->id);
        }

        $pubs = $query->filter($request->all())->paginateFilter($perPage);

        return PredictionDatasetResource::collection($pubs);
    }

    public function index(Request $request, PredictionDataset $record)
    {
        $this->authorizeDatasetAccess($request, $record);
        $record->loadMissing(['user', 'predictionMembrane']);

        return PredictionDatasetResource::make($record);
    }

    public function updateDataset(Request $request, PredictionDataset $record)
    {
        $this->authorizeDatasetAccess($request, $record);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $record->update($validated);
        $record->loadMissing(['user', 'predictionMembrane']);

        return PredictionDatasetResource::make($record);
    }

    public function records(Request $request, PredictionDataset $record)
    {
        $this->authorizeDatasetAccess($request, $record);
        $perPage = $this->perPage($request);

        // Add dataset id to params
        $request->merge([
            'datasetId' => $record->id,
        ]);

        $records = Prediction::filter($request->all())
            ->paginateFilter($perPage);

        return PredictionResource::collection($records);
    }

    public function structures(Request $request, PredictionDataset $record)
    {
        $this->authorizeDatasetAccess($request, $record);
        $perPage = $this->perPage($request);

        // Add dataset id to params
        $request->merge([
            'datasetId' => $record->id,
        ]);

        $records = PredictionStructure::filter($request->all())
            ->paginateFilter($perPage);

        return PredictionStructureResource::collection($records);
    }

    public function predictionsByStructure(Request $request, PredictionStructure $record)
    {
        $token = (string) ($request->query('token') ?? '');
        $user = $request->user();

        if ($token !== '') {
            // Token path — verify a dataset containing this structure has this exact token
            $valid = PredictionDataset::query()
                ->whereHas('predictions', fn ($q) => $q->where('structure_id', $record->id))
                ->get()
                ->contains(fn ($dataset) => $dataset->token !== null && hash_equals($dataset->token, $token));

            if (! $valid) {
                abort(403);
            }
        } elseif ($user) {
            // Auth path — manage-all OR owns at least one dataset containing this structure
            if (! Gate::allows('viewAny', PredictionDataset::class)) {
                $owns = PredictionDataset::query()
                    ->where('user_id', $user->id)
                    ->whereHas('predictions', fn ($q) => $q->where('structure_id', $record->id))
                    ->exists();

                if (! $owns) {
                    abort(403);
                }
            }
        } else {
            abort(403);
        }

        $perPage = $this->perPage($request);

        $request->merge(['structureId' => $record->id]);

        $records = Prediction::filter($request->all())
            ->when(
                $user && ! Gate::allows('viewAny', PredictionDataset::class),
                fn ($q) => $q->whereHas('predictionDatasets', fn ($q2) => $q2->where('user_id', $user->id))
            )
            ->paginateFilter($perPage);

        return PredictionResource::collectionWithParsedResults($records);
    }

    /**
     * Token access (unauthenticated, ?token=) is checked first.
     * Otherwise, the request must be authenticated and pass PredictionDatasetPolicy.
     *
     * @throws AuthorizationException
     */
    private function authorizeDatasetAccess(Request $request, PredictionDataset $record): void
    {
        $token = (string) ($request->query('token') ?? '');

        if ($token !== '') {
            // Token path is exclusive — a wrong token never falls back to session auth
            if ($record->token !== null && hash_equals($record->token, $token)) {
                return;
            }
            abort(403);
        }

        // ID-based path (no token) — must be authenticated and pass Policy
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        Gate::authorize('view', $record);
    }

    private function perPage(Request $request, int $default = 10, int $maximum = 100): int
    {
        return min(max($request->integer('per_page', $default), 1), $maximum);
    }

    private function ensurePredictionMembranesAvailable(): void
    {
        Membrane::query()
            ->select(['id', 'name', 'abbreviation'])
            ->whereIn('id', $this->availablePredictionMembraneRemoteIds())
            ->orderBy('id')
            ->get()
            ->each(function (Membrane $membrane): void {
                PredictionMembrane::query()->firstOrCreate(
                    ['remote_id' => $membrane->id],
                    [
                        'name' => $membrane->name,
                        'abbreviation' => $membrane->abbreviation,
                    ],
                );
            });
    }

    /**
     * @return Collection<int, int>
     */
    private function availablePredictionMembraneRemoteIds(): Collection
    {
        return Membrane::query()
            ->whereHas('files', fn ($query) => $query->where('type', File::TYPE_COSMO_MEMBRANE))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values();
    }
}
