<?php

require_once __DIR__.'/api_contract_seed.php';

use App\Enums\PermissionEnums;
use App\Models\FeedbackEmailVerification;
use App\Models\File;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\PredictionWorkers\DTO\JobResult;
use Modules\PredictionWorkers\DTO\PredictionResultJson;
use Modules\PredictionWorkers\DTO\SoluteResult;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionDataset;
use Modules\PredictionWorkers\Models\PredictionFile;
use Modules\PredictionWorkers\Models\PredictionMembrane;
use Modules\PredictionWorkers\Models\PredictionResult;
use Modules\PredictionWorkers\Models\PredictionStat;
use Modules\PredictionWorkers\Models\PredictionStructure;
use Spatie\Permission\PermissionRegistrar;

/*
 * Pins the responses of the internal account endpoints used by the frontend:
 * predictions, notifications, feedback and the signed-in user
 * (see Tests\Support\ApiContract).
 */

const ACCOUNT_CONTRACT_VERIFICATION_CODE = '482913';

beforeEach(function () {
    $this->travelTo('2026-10-01 12:00:00');

    prepareApiEndpointTestEnvironment();

    config()->set('services.turnstile.enabled', true);
    config()->set('services.turnstile.secret_key', 'turnstile-test-secret');
    config()->set('services.turnstile.verify_url', 'https://turnstile.test/siteverify');

    Http::fake(['https://turnstile.test/siteverify' => Http::response(['success' => true], 200)]);
    Http::preventStrayRequests();

    // Admin recipients of the feedback and prediction notifications are looked up by permission.
    foreach (PermissionEnums::cases() as $permission) {
        Permission::query()->firstOrCreate(['name' => $permission->value, 'guard_name' => 'web'], ['description' => $permission->description()]);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Mail::fake();
    Notification::fake();
    Queue::fake();

    // Prediction methods are cached statically for the whole process.
    Closure::bind(function (): void {
        static::$methodsCache = null;
    }, null, Prediction::class)();
});

afterEach(function () {
    resetApiRouteCdkDepictState();
    resetApiRouteRdkitState();
});

/**
 * A prediction dataset of the given owner with one finished (result attached)
 * and one paused prediction of the contract world's structure, plus a remote
 * server statistics snapshot.
 *
 * @return array{world: array<string, mixed>, membrane: PredictionMembrane, structure: PredictionStructure, dataset: PredictionDataset, finished: Prediction, paused: Prediction}
 */
function seedApiAccountPredictions(User $owner): array
{
    $world = seedApiContractWorld();

    $cosmoFile = File::create([
        'type' => File::TYPE_COSMO_MEMBRANE,
        'path' => 'membranes/eggpc.mic',
        'mime' => 'text/plain',
        'hash' => md5('cosmo membrane'),
        'name' => 'eggpc.mic',
    ]);
    $world['membrane']->files()->attach($cosmoFile->id, ['model_type' => $world['membrane']::class]);

    $membrane = PredictionMembrane::query()->create([
        'remote_id' => $world['membrane']->id,
        'name' => $world['membrane']->name,
        'abbreviation' => $world['membrane']->abbreviation,
    ]);

    $structure = PredictionStructure::query()->create([
        'remote_id' => $world['structure']->id,
        'canonical_smiles' => $world['structure']->canonical_smiles,
        'remote_molecule_status' => 'completed',
    ]);

    $dataset = PredictionDataset::query()->create([
        'comment' => 'Caffeine permeability',
        'token' => 'contract-dataset-token-0000000001',
        'user_id' => $owner->id,
        'temperature' => 25.0,
        'membrane_id' => $membrane->id,
        'method_type' => 'cosmoperm',
        'priority' => Prediction::PRIORITY_MEDIUM,
    ]);

    $resultFile = PredictionFile::query()->create([
        'type' => PredictionFile::TYPE_RESULT_COSMO_XML,
        'name' => 'cosmo.xml',
        'mime' => 'application/xml',
        'storage' => 'predictions',
        'path' => 'results/contract/cosmo.xml',
        'hash' => md5('cosmo result'),
    ]);

    $result = PredictionResult::query()->create([
        'file_id' => $resultFile->id,
        'data' => (new PredictionResultJson([
            new JobResult(
                jobNumber: '1',
                symmetry: 'planar',
                layerCount: 3,
                layerPositions: [0.0, 1.2, 2.3],
                temperature: 25.0,
                solutes: [
                    new SoluteResult(meanPosition: 1.5, logK: 2.75, logPerm: -4.5, energyValues: [-0.85, -0.43, 0.0]),
                ],
            ),
        ]))->jsonSerialize(),
    ]);

    $finished = Prediction::query()->create([
        'result_id' => $result->id,
        'structure_id' => $structure->id,
        'state' => Prediction::STATE_FINISHED,
        'step' => Prediction::STEP_RESULT_DB_STORE,
        'temperature' => 25.0,
        'membrane_id' => $membrane->id,
        'method_type' => 'cosmoperm',
        'remote_method' => 'cosmoperm',
        'remote_calculation_id' => 'calc-0001',
        'remote_molecule_id' => 'mol-0001',
        'remote_status' => 'completed',
        'remote_current_step' => 'cosmo',
        'remote_submitted_at' => now()->subHours(3),
        'remote_heartbeat_at' => now()->subHour(),
        'remote_last_status_at' => now()->subHour(),
        'remote_finished_at' => now()->subHour(),
        'remote_error_message' => 'Conformer 3 did not converge.',
        'logs' => [['at' => '2026-10-01T09:00:00Z', 'message' => 'Submitted to the remote server.']],
        'priority' => Prediction::PRIORITY_MEDIUM,
    ]);

    $paused = Prediction::query()->create([
        'structure_id' => $structure->id,
        'state' => Prediction::STATE_RUNNING,
        'step' => Prediction::STEP_COSMO,
        'temperature' => 37.0,
        'membrane_id' => $membrane->id,
        'method_type' => 'cosmoperm',
        'remote_method' => 'cosmoperm',
        'remote_calculation_id' => 'calc-0002',
        'remote_molecule_id' => 'mol-0001',
        'remote_status' => 'running',
        'remote_current_step' => 'cosmo',
        'remote_submitted_at' => now()->subHours(2),
        'remote_heartbeat_at' => now()->subMinutes(30),
        'remote_last_status_at' => now()->subMinutes(30),
        'remote_paused_at' => now()->subMinutes(20),
        'remote_pause_reason' => 'Paused by an administrator.',
        'logs' => [['at' => '2026-10-01T10:00:00Z', 'message' => 'Paused.']],
        'priority' => Prediction::PRIORITY_MEDIUM,
    ]);

    $dataset->predictions()->attach([$finished->id, $paused->id]);

    $periodStats = ['started' => 2, 'running' => 1, 'completed' => 1, 'failed' => 0, 'total' => 2, 'avg_duration_seconds' => 5400];

    PredictionStat::storeDailySnapshot('https://predictor.test', now(), [
        'periods' => [
            'day' => ['steps' => ['cosmo' => $periodStats]],
            'total' => ['steps' => ['cosmo' => $periodStats]],
        ],
        'running' => [
            'molecule_steps' => [],
            'conformer_steps' => [],
            'cosmo' => [[
                'calculation_id' => 'calc-0002',
                'started_at' => '2026-10-01T10:00:00Z',
                'lease_owner' => 'worker-1',
                'heartbeat_age_seconds' => 12,
                'molecule_id' => 'mol-0001',
                'membrane_key' => 'eggpc',
                'temperature_c' => 37.0,
                'canonical_smiles' => $structure->canonical_smiles,
                'paused_at' => null,
                'cancel_requested_at' => null,
            ]],
        ],
        'queue' => ['cosmo' => 3],
        'paused' => ['molecules' => 0, 'cosmo_calculations' => 1],
    ]);

    return compact('world', 'membrane', 'structure', 'dataset', 'finished', 'paused');
}

function createApiAccountEmailVerification(string $email): FeedbackEmailVerification
{
    return FeedbackEmailVerification::query()->create([
        'email' => $email,
        'code_hash' => Hash::make(ACCOUNT_CONTRACT_VERIFICATION_CODE),
        'expires_at' => now()->addMinutes(15),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
    ]);
}

function createApiAccountUser(): User
{
    return User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane.doe@example.org']);
}

test('predictions endpoint keeps its contract', function (string $route, Closure $request) {
    $user = createApiAccountUser();
    $seed = seedApiAccountPredictions($user);

    $this->actingAs($user);

    $request($this, $seed)->assertSuccessful()->assertApiContract($route);
})->with([
    'options' => ['GET api/predictions/options', fn ($test) => $test->getJson('/api/predictions/options')],
    'server stats' => ['GET api/predictions/server-stats', fn ($test) => $test->getJson('/api/predictions/server-stats')],
    'dataset by token' => ['POST api/predictions/by-token', fn ($test, $seed) => $test->postJson('/api/predictions/by-token', ['token' => $seed['dataset']->token])],
    'validate smiles' => ['POST api/predictions/validate-smiles', fn ($test) => $test->postJson('/api/predictions/validate-smiles', ['smiles' => ['CCO', 'OCC']])],
    'structure validate smiles' => ['POST api/structure/predictions/validate', fn ($test) => $test->postJson('/api/structure/predictions/validate', ['smiles' => ['CCO']])],
    'store dataset' => ['POST api/predictions/datasets', fn ($test, $seed) => $test->postJson('/api/predictions/datasets', [
        'membranes' => [$seed['membrane']->id],
        'methods' => ['cosmoperm'],
        'smiles' => ['CCO', 'OCC'],
        'temperature' => 25,
        'description' => 'Ethanol permeability',
    ])],
    'datasets' => ['GET api/predictions/datasets', fn ($test) => $test->getJson('/api/predictions/datasets')],
    'dataset' => ['GET api/predictions/datasets/{record}', fn ($test, $seed) => $test->getJson("/api/predictions/datasets/{$seed['dataset']->id}")],
    'update dataset' => ['PATCH api/predictions/datasets/{record}', fn ($test, $seed) => $test->patchJson("/api/predictions/datasets/{$seed['dataset']->id}", ['comment' => 'Caffeine permeability (rerun)'])],
    'dataset records' => ['GET api/predictions/datasets/{record}/records', fn ($test, $seed) => $test->getJson("/api/predictions/datasets/{$seed['dataset']->id}/records")],
    'dataset structures' => ['GET api/predictions/datasets/{record}/structures', fn ($test, $seed) => $test->getJson("/api/predictions/datasets/{$seed['dataset']->id}/structures")],
    'predictions by structure' => ['GET api/predictions/byStructure/{record}', fn ($test, $seed) => $test->getJson("/api/predictions/byStructure/{$seed['structure']->id}")],
]);

test('email verification request keeps its contract', function (string $route, string $path) {
    $this->postJson($path, [
        'email' => 'Jane.Doe@example.org',
        'turnstile_token' => 'turnstile-test-token',
    ])->assertSuccessful()->assertApiContract($route);
})->with([
    'predictions' => ['POST api/predictions/email-verification', '/api/predictions/email-verification'],
    'feedback' => ['POST api/feedback/email-verification', '/api/feedback/email-verification'],
]);

test('email verification keeps its contract', function (string $route, string $path) {
    createApiAccountEmailVerification('jane.doe@example.org');

    // The code signs the user in, which needs the stateful (session) API.
    $this->withHeader('Referer', 'http://localhost')
        ->postJson($path, ['email' => 'jane.doe@example.org', 'code' => ACCOUNT_CONTRACT_VERIFICATION_CODE])
        ->assertSuccessful()
        ->assertApiContract($route);
})->with([
    'predictions' => ['POST api/predictions/email-verification/verify', '/api/predictions/email-verification/verify'],
    'feedback' => ['POST api/feedback/email-verification/verify', '/api/feedback/email-verification/verify'],
]);

test('feedback submission keeps its contract', function (string $route, string $path) {
    $this->actingAs(createApiAccountUser());

    $this->postJson($path, [
        'context' => 'https://molmedb.upol.cz/mol/MM00040',
        'message' => 'The permeability value of caffeine looks wrong.',
    ])->assertSuccessful()->assertApiContract($route);
})->with([
    'feedback' => ['POST api/feedback', '/api/feedback'],
    'authenticated feedback' => ['POST api/feedback/authenticated', '/api/feedback/authenticated'],
]);

test('notification endpoint keeps its contract', function (string $route, Closure $request) {
    $user = createApiAccountUser();
    $template = NotificationTemplate::query()->where('key', NotificationTemplate::KEY_PREDICTION_JOB_SUBMITTED)->first();

    UserNotification::query()->create([
        'user_id' => $user->id,
        'notification_template_id' => $template?->id,
        'state' => UserNotification::STATE_READ,
        'title' => 'Prediction job submitted',
        'body' => 'Your prediction job "Caffeine permeability" was queued.',
        'read_at' => now()->subHour(),
        'created_at' => now()->subHours(2),
    ]);

    UserNotification::query()->create([
        'user_id' => $user->id,
        'notification_template_id' => $template?->id,
        'state' => UserNotification::STATE_NEW,
        'title' => 'Prediction job finished',
        'body' => 'Your prediction job "Caffeine permeability" has finished.',
    ]);

    $user->pushSubscriptions()->create([
        'endpoint' => 'https://push.test/subscriptions/contract',
        'public_key' => 'contract-public-key',
        'auth_token' => 'contract-auth-token',
    ]);

    $this->actingAs($user);

    $request($this)->assertSuccessful()->assertApiContract($route);
})->with([
    'notifications' => ['GET api/notifications', fn ($test) => $test->getJson('/api/notifications')],
    'mark as read' => ['POST api/notifications/read', fn ($test) => $test->postJson('/api/notifications/read')],
    'clear' => ['DELETE api/notifications/clear', fn ($test) => $test->deleteJson('/api/notifications/clear')],
    'preferences' => ['GET api/notifications/preferences', fn ($test) => $test->getJson('/api/notifications/preferences')],
    'update preferences' => ['PUT api/notifications/preferences', fn ($test) => $test->putJson('/api/notifications/preferences', [
        'preferences' => [
            ['key' => 'prediction.job.finished', 'email_enabled' => true, 'push_enabled' => false],
        ],
    ])],
    'store push subscription' => ['POST api/notifications/push-subscriptions', fn ($test) => $test->postJson('/api/notifications/push-subscriptions', [
        'endpoint' => 'https://push.test/subscriptions/new',
        'keys' => ['p256dh' => 'new-public-key', 'auth' => 'new-auth-token'],
    ])],
    'delete push subscription' => ['DELETE api/notifications/push-subscriptions', fn ($test) => $test->deleteJson('/api/notifications/push-subscriptions', [
        'endpoint' => 'https://push.test/subscriptions/contract',
    ])],
]);

test('signed-in user endpoint keeps its contract', function () {
    $this->actingAs(createApiAccountUser());

    $this->getJson('/api/user')->assertSuccessful()->assertApiContract('GET api/user');
});
