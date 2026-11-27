<?php

require_once __DIR__.'/../Filament/Predictions/filament_prediction_helpers.php';

use App\Console\Commands\Cron\CheckPredictionServerHealth;
use App\Enums\NotificationDeliveryMode;
use App\Enums\NotificationType;
use App\Enums\PermissionEnums;
use App\Filament\Widgets\PredictionServerHealthWidget;
use App\Models\Config;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\SystemHealth\HealthCheckResult;
use App\Services\SystemHealth\PredictionServerAssessment;
use App\Services\SystemHealth\PredictionServerHealth;
use App\Services\SystemHealth\SystemHealthReport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\PredictionWorkers\Models\PredictionStat;
use Spatie\Permission\PermissionRegistrar;

/*
 * Whether the remote prediction server computes, judged from the statistics
 * the prediction worker fetches every minute: the state of its Kubernetes
 * namespace and of its queues. The system monitors are told when it stops
 * and when it works again; the dashboard and the daily report show it.
 */

beforeEach(function () {
    Notification::fake();
    Cache::forget(CheckPredictionServerHealth::STATE_CACHE_KEY);
    $this->travelTo('2026-11-27 10:00:00');
    Config::set(Config::KEY_REMOTE_PREDICTION_ENABLED, '1');
    Config::set(Config::KEY_REMOTE_PREDICTION_URL, 'https://predictor.test');
});

/**
 * A healthy server's statistics; $cluster and $activity replace parts.
 *
 * @param  array<string, mixed>  $cluster
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function predictionServerPayload(array $cluster = [], array $payload = []): array
{
    return [
        'queue' => ['optimization-turbomole' => 0, 'cosmo' => 3],
        'running' => ['molecule_steps' => [], 'conformer_steps' => [], 'cosmo' => []],
        'activity' => [
            'optimization-turbomole' => ['queued' => 0, 'running' => 0, 'stale_running' => 0, 'last_completed_at' => '2026-11-27T09:30:00+01:00'],
            'cosmo' => ['queued' => 3, 'running' => 8, 'stale_running' => 0, 'last_completed_at' => '2026-11-27T09:58:00+01:00'],
        ],
        'cluster' => [
            'in_cluster' => true,
            'available' => true,
            'checked_at' => '2026-11-27T08:59:00Z',
            'namespace' => 'molmedb-ns',
            'components' => [
                ['name' => 'turbomole-worker', 'kind' => 'Deployment', 'desired' => 1, 'ready' => 1, 'available' => 1, 'updated' => 1, 'rollout_stalled' => false],
                ['name' => 'cosmo-worker', 'kind' => 'Deployment', 'desired' => 8, 'ready' => 8, 'available' => 8, 'updated' => 8, 'rollout_stalled' => false],
            ],
            'problems' => [],
            'problems_total' => 0,
            'events' => [],
            'events_total' => 0,
            ...$cluster,
        ],
        ...$payload,
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function fetchedServerStats(array $payload, ?string $fetchedAt = null): PredictionStat
{
    // The newest snapshot is what counts; the worker keeps one a day.
    PredictionStat::query()->delete();

    return PredictionStat::query()->create([
        'server_url' => 'https://predictor.test',
        'stats_date' => now()->toDateString(),
        'payload' => $payload,
        'fetched_at' => $fetchedAt ?? now(),
    ]);
}

/**
 * The 11-day outage of November 2026: no pod could pull its image, so no
 * worker took the Turbomole step.
 *
 * @return array<string, mixed>
 */
function imagePullOutagePayload(): array
{
    return predictionServerPayload(
        [
            'components' => [
                ['name' => 'turbomole-worker', 'kind' => 'Deployment', 'desired' => 1, 'ready' => 0, 'available' => 0, 'updated' => 1, 'rollout_stalled' => false],
                ['name' => 'cosmo-worker', 'kind' => 'Deployment', 'desired' => 8, 'ready' => 2, 'available' => 2, 'updated' => 8, 'rollout_stalled' => false],
            ],
            'problems' => [
                ['pod' => 'cosmo-worker-a', 'component' => 'cosmo-worker', 'container' => 'worker', 'reason' => 'ImagePullBackOff', 'message' => 'failed to pull image: 401 Unauthorized', 'since' => '2026-11-19T10:00:00Z'],
                ['pod' => 'cosmo-worker-b', 'component' => 'cosmo-worker', 'container' => 'worker', 'reason' => 'ImagePullBackOff', 'message' => 'failed to pull image: 401 Unauthorized', 'since' => '2026-11-16T14:00:00Z'],
                ['pod' => 'turbomole-worker-c', 'component' => 'turbomole-worker', 'container' => 'worker', 'reason' => 'ImagePullBackOff', 'message' => '401 Unauthorized', 'since' => '2026-11-26T13:00:00Z'],
            ],
            'problems_total' => 3,
        ],
        [
            'activity' => [
                'optimization-turbomole' => ['queued' => 538, 'running' => 0, 'stale_running' => 0, 'last_completed_at' => '2026-11-16T14:59:49+01:00'],
                'cosmo' => ['queued' => 0, 'running' => 0, 'stale_running' => 0, 'last_completed_at' => '2026-11-16T21:38:34+01:00'],
            ],
        ],
    );
}

function predictionServerMonitor(): User
{
    Permission::query()->firstOrCreate(
        ['name' => PermissionEnums::SYSTEM_MONITOR->value, 'guard_name' => 'web'],
        ['description' => PermissionEnums::SYSTEM_MONITOR->description()],
    );
    $role = Role::query()->create(['name' => 'Server monitors '.fake()->unique()->numberBetween(1, 1_000_000), 'guard_name' => 'web']);
    $role->givePermissionTo(PermissionEnums::SYSTEM_MONITOR->value);
    $user = User::factory()->create();
    $user->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

function assessPredictionServer(): PredictionServerAssessment
{
    return app(PredictionServerHealth::class)->assess();
}

test('a server whose components run and whose queues move is fine', function () {
    fetchedServerStats(predictionServerPayload());

    $assessment = assessPredictionServer();

    expect($assessment->status)->toBe(HealthCheckResult::STATUS_OK)
        ->and($assessment->problems)->toBe([])
        ->and($assessment->warnings)->toBe([])
        ->and($assessment->summary())->toBe('All 2 components run and the queues move.')
        ->and(array_column($assessment->components, 'name'))->toBe(['cosmo-worker', 'turbomole-worker']);
});

test('the image pull outage is recognised: pods that cannot start, a component with nothing ready, a step nobody takes', function () {
    fetchedServerStats(imagePullOutagePayload());

    $assessment = assessPredictionServer();

    expect($assessment->status)->toBe(HealthCheckResult::STATUS_PROBLEM)
        ->and(array_keys($assessment->problems))->toEqualCanonicalizing([
            'pods:cosmo-worker:ImagePullBackOff',
            'pods:turbomole-worker:ImagePullBackOff',
            'component:turbomole-worker',
            'step:optimization-turbomole',
        ])
        ->and($assessment->problems['pods:cosmo-worker:ImagePullBackOff'])
        ->toBe('cosmo-worker: 2 pods ImagePullBackOff since 2026-11-16 14:00 (1 week ago) — failed to pull image: 401 Unauthorized')
        ->and($assessment->problems['step:optimization-turbomole'])
        ->toBe('Step optimization-turbomole: 538 waiting, none running, last finished 2026-11-16 13:59 (1 week ago).')
        ->and($assessment->warnings)->toBe(['cosmo-worker: 2 of 8 replicas ready.']);
});

test('a server that stopped answering is a problem', function () {
    fetchedServerStats(predictionServerPayload(), '2026-11-27 09:45:00');

    $assessment = assessPredictionServer();

    expect($assessment->problems)->toHaveKey('unreachable')
        ->and($assessment->problems['unreachable'])->toStartWith('The prediction server has not answered since 2026-11-27 09:45 (15 minutes ago)');
});

test('a server never heard from is a problem', function () {
    expect(assessPredictionServer()->problems)->toBe([
        'unreachable' => 'No statistics have been fetched from the prediction server yet.',
    ]);
});

test('pods that cannot be created for the quota are a problem of their component', function () {
    fetchedServerStats(predictionServerPayload([
        'events' => [
            ['reason' => 'FailedCreate', 'object' => 'replicaset/cosmo-worker-dcb4c95d6', 'message' => 'exceeded quota: default-zgkql, requested: limits.memory=15Gi', 'count' => 12, 'last_seen' => '2026-11-27T08:58:00Z'],
        ],
        'events_total' => 1,
    ]));

    expect(assessPredictionServer()->problems)->toBe([
        'event:cosmo-worker:FailedCreate' => 'cosmo-worker: FailedCreate (12×) — exceeded quota: default-zgkql, requested: limits.memory=15Gi',
    ]);
});

test('a step that works or finished lately is not idle, whatever waits', function (array $activity) {
    fetchedServerStats(predictionServerPayload(payload: ['activity' => ['cosmo' => $activity]]));

    expect(assessPredictionServer()->problems)->toBe([]);
})->with([
    'running' => [['queued' => 90, 'running' => 8, 'last_completed_at' => '2026-11-20T10:00:00+01:00']],
    'finished lately' => [['queued' => 90, 'running' => 0, 'last_completed_at' => '2026-11-27T09:30:00Z']],
    'nothing waits' => [['queued' => 0, 'running' => 0, 'last_completed_at' => null]],
]);

test('running work whose worker stopped reporting is a problem', function () {
    fetchedServerStats(predictionServerPayload(payload: [
        'running' => [
            'molecule_steps' => [],
            'conformer_steps' => [
                ['step' => 'optimization-orca', 'heartbeat_age_seconds' => 3600],
                ['step' => 'optimization-orca', 'heartbeat_age_seconds' => 4000],
                ['step' => 'optimization-orca', 'heartbeat_age_seconds' => 20],
            ],
            'cosmo' => [['calculation_id' => 'c1', 'heartbeat_age_seconds' => 1000]],
        ],
    ]));

    expect(assessPredictionServer()->problems)->toBe([
        'stuck:optimization-orca' => 'Step optimization-orca: 2 running items without a sign of life from the worker for over 15 minutes.',
        'stuck:cosmo' => 'Step cosmo: 1 running item without a sign of life from the worker for over 15 minutes.',
    ]);
});

test('an unreadable cluster, a stalled rollout and a recent out-of-memory kill are only warnings', function () {
    fetchedServerStats(predictionServerPayload([
        'components' => [
            ['name' => 'api', 'kind' => 'Deployment', 'desired' => 1, 'ready' => 1, 'rollout_stalled' => true],
        ],
        'problems' => [
            ['pod' => 'cosmo-worker-a', 'component' => 'cosmo-worker', 'reason' => 'OOMKilled', 'message' => 'The container ran out of memory and was restarted.', 'since' => '2026-11-27T09:30:00Z'],
        ],
        'problems_total' => 51,
    ]));

    $assessment = assessPredictionServer();

    expect($assessment->status)->toBe(HealthCheckResult::STATUS_WARNING)
        ->and($assessment->problems)->toBe([])
        ->and($assessment->warnings)->toBe([
            'cosmo-worker: 1 pod OOMKilled since 2026-11-27 09:30 (30 minutes ago) — The container ran out of memory and was restarted.',
            '50 more pod problems not listed by the server.',
            'api: the rollout of a new version is past its deadline; the old pods still run.',
        ]);

    fetchedServerStats(predictionServerPayload(['available' => false, 'error' => 'HTTPError: HTTP Error 403: Forbidden']));

    expect(assessPredictionServer()->warnings)->toBe(['The state of the cluster could not be read: HTTPError: HTTP Error 403: Forbidden']);
});

test('a server without the cluster report is judged by its queues alone', function () {
    $payload = predictionServerPayload();
    unset($payload['cluster']);
    fetchedServerStats($payload);

    $assessment = assessPredictionServer();

    expect($assessment->status)->toBe(HealthCheckResult::STATUS_OK)
        ->and($assessment->note)->toBe('The prediction server does not report the state of its cluster (an older version).');
});

test('with remote predictions switched off there is nothing to judge', function () {
    Config::set(Config::KEY_REMOTE_PREDICTION_ENABLED, '0');

    $assessment = assessPredictionServer();

    expect($assessment->status)->toBe(PredictionServerAssessment::STATUS_DISABLED)
        ->and($assessment->toHealthCheckResult()->status)->toBe(HealthCheckResult::STATUS_OK);
});

test('the monitors are told once a problem has lasted five minutes, not sooner, and nobody else is told', function () {
    $monitor = predictionServerMonitor();
    $other = User::factory()->create();
    fetchedServerStats(imagePullOutagePayload());

    $this->artisan(CheckPredictionServerHealth::class)->assertSuccessful();
    expect(UserNotification::query()->count())->toBe(0);

    $this->travel(5)->minutes();
    fetchedServerStats(imagePullOutagePayload());
    $this->artisan(CheckPredictionServerHealth::class)->assertSuccessful();

    $notification = UserNotification::query()->sole();
    expect($notification->user_id)->toBe($monitor->id)
        ->and($notification->title)->toBe('Prediction server: 4 problems')
        ->and($notification->body)->toContain('Predictions are not being computed as they should since 2026-11-27 10:00.')
        ->and($notification->email_message)->toContain('<li>turbomole-worker: none of 1 replica ready.</li>')
        ->and($notification->email_message)->toContain('<a href="http://localhost/">')
        ->and(UserNotification::query()->where('user_id', $other->id)->exists())->toBeFalse();

    // The same outage five minutes later is not announced again.
    $this->travel(5)->minutes();
    fetchedServerStats(imagePullOutagePayload());
    $this->artisan(CheckPredictionServerHealth::class)->assertSuccessful();

    expect(UserNotification::query()->count())->toBe(1);
});

test('a problem that passes within five minutes is never announced', function () {
    predictionServerMonitor();
    fetchedServerStats(imagePullOutagePayload());
    $this->artisan(CheckPredictionServerHealth::class)->assertSuccessful();

    $this->travel(4)->minutes();
    fetchedServerStats(predictionServerPayload());
    $this->artisan(CheckPredictionServerHealth::class)->assertSuccessful();

    expect(UserNotification::query()->count())->toBe(0)
        ->and(Cache::get(CheckPredictionServerHealth::STATE_CACHE_KEY))->toBeNull();
});

test('a new kind of problem is announced, a lasting one again after a day, and the end of it too', function () {
    predictionServerMonitor();
    $events = ['events' => [['reason' => 'FailedCreate', 'object' => 'replicaset/orca-worker-66c48d779d', 'message' => 'exceeded quota', 'count' => 3, 'last_seen' => '2026-11-27T09:00:00Z']]];

    fetchedServerStats(imagePullOutagePayload());
    $this->artisan(CheckPredictionServerHealth::class);
    $this->travel(5)->minutes();
    fetchedServerStats(imagePullOutagePayload());
    $this->artisan(CheckPredictionServerHealth::class);
    expect(UserNotification::query()->count())->toBe(1);

    // The quota joins the outage: announced once it lasted five minutes.
    $withQuota = imagePullOutagePayload();
    $withQuota['cluster'] = [...$withQuota['cluster'], ...$events];
    $this->travel(5)->minutes();
    fetchedServerStats($withQuota);
    $this->artisan(CheckPredictionServerHealth::class);
    expect(UserNotification::query()->count())->toBe(1);

    $this->travel(5)->minutes();
    fetchedServerStats($withQuota);
    $this->artisan(CheckPredictionServerHealth::class);
    expect(UserNotification::query()->count())->toBe(2)
        ->and(UserNotification::query()->latest('id')->first()->title)->toBe('Prediction server still: 5 problems');

    // A day later it still lasts.
    $this->travel(1)->days();
    fetchedServerStats($withQuota);
    $this->artisan(CheckPredictionServerHealth::class);
    expect(UserNotification::query()->count())->toBe(3);

    // Fixed.
    $this->travel(5)->minutes();
    fetchedServerStats(predictionServerPayload());
    $this->artisan(CheckPredictionServerHealth::class);

    $resolved = UserNotification::query()->latest('id')->first();
    expect(UserNotification::query()->count())->toBe(4)
        ->and($resolved->title)->toBe('Prediction server works again')
        ->and($resolved->body)->toBe('The problems announced at 2026-11-27 10:05 are gone.')
        ->and(Cache::get(CheckPredictionServerHealth::STATE_CACHE_KEY))->toBeNull();
});

test('the notification is an immediate one for the system monitors, with its template', function () {
    $type = NotificationType::PREDICTION_ADMIN_SERVER_HEALTH;

    expect($type->permission())->toBe(PermissionEnums::SYSTEM_MONITOR)
        ->and($type->deliveryMode())->toBe(NotificationDeliveryMode::IMMEDIATE)
        ->and($type->defaultEmail())->toBeTrue()
        ->and($type->group())->toBe('Administration – system health')
        ->and($type->label())->toBe('System admin: prediction server stopped working')
        ->and(NotificationTemplate::query()->where('key', $type->value)->where('is_active', true)->exists())->toBeTrue();
});

test('it runs every five minutes', function () {
    $event = collect(app(Schedule::class)->events())
        ->sole(fn ($event): bool => str_contains($event->command ?? '', 'predictions:check-server-health'));

    expect($event->expression)->toBe('*/5 * * * *');
});

test('the daily report shows the prediction server', function () {
    fetchedServerStats(imagePullOutagePayload());

    $check = collect(app(SystemHealthReport::class)->run(now()->subDay()))
        ->sole(fn (HealthCheckResult $result): bool => $result->label === 'Prediction server');

    expect($check->status)->toBe(HealthCheckResult::STATUS_PROBLEM)
        ->and($check->summary)->toBe('4 problems, 1 warning.')
        ->and($check->details)->toContain('turbomole-worker: none of 1 replica ready.');
});

test('the dashboard shows what is wrong with the prediction server', function () {
    seedFilamentPredictionPermissions();
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTIONS_MANAGE]));
    fetchedServerStats(imagePullOutagePayload());

    expect(PredictionServerHealthWidget::canView())->toBeTrue();

    Livewire::test(PredictionServerHealthWidget::class)
        ->assertSee('Prediction server')
        ->assertSee('Step optimization-turbomole: 538 waiting, none running')
        ->assertSee('turbomole-worker 0/1')
        ->assertSee('cosmo-worker: 2 of 8 replicas ready.');
});

test('the dashboard widget is hidden from users who do not see the prediction statistics', function () {
    seedFilamentPredictionPermissions();
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::SYSTEM_MONITOR]));

    expect(PredictionServerHealthWidget::canView())->toBeFalse();
});
