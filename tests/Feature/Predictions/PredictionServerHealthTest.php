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

function predictionServerAlertState(): ?string
{
    return Config::get(Config::KEY_PREDICTION_SERVER_HEALTH_ALERT);
}

/**
 * @return array<string, mixed>
 */
function quotaEvent(string $object = 'replicaset/cosmo-worker-dcb4c95d6'): array
{
    return ['reason' => 'FailedCreate', 'object' => $object, 'message' => 'exceeded quota: default-zgkql, requested: limits.memory=15Gi', 'count' => 12, 'last_seen' => now()->subMinutes(2)->toIso8601String()];
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
        'components' => [
            ['name' => 'turbomole-worker', 'kind' => 'Deployment', 'desired' => 1, 'ready' => 1, 'available' => 1, 'updated' => 1, 'rollout_stalled' => false],
            ['name' => 'cosmo-worker', 'kind' => 'Deployment', 'desired' => 8, 'ready' => 6, 'available' => 6, 'updated' => 8, 'rollout_stalled' => false],
        ],
        'events' => [
            quotaEvent(),
            // Another pod of the same component waiting for a node.
            ['reason' => 'FailedScheduling', 'object' => 'pod/orca-worker-66c48d779d-24pcm', 'message' => '0/80 nodes are available.', 'count' => 3, 'last_seen' => now()->subMinute()->toIso8601String()],
            ['reason' => 'FailedScheduling', 'object' => 'pod/orca-worker-66c48d779d-52q7j', 'message' => '0/80 nodes are available.', 'count' => 3, 'last_seen' => now()->subMinute()->toIso8601String()],
            // Over and done with.
            ['reason' => 'FailedCreate', 'object' => 'replicaset/api-76447c59f', 'message' => 'exceeded quota', 'count' => 1, 'last_seen' => now()->subMinutes(40)->toIso8601String()],
            // Not about pods that are not created: only worth a look.
            ['reason' => 'FailedMount', 'object' => 'pod/db-0', 'message' => 'timed out waiting for the volume', 'count' => 2, 'last_seen' => now()->subMinutes(3)->toIso8601String()],
        ],
        'events_total' => 5,
    ]));

    $assessment = assessPredictionServer();

    expect($assessment->problems)->toBe([
        'event:cosmo-worker:FailedCreate' => 'cosmo-worker: FailedCreate (12×) — exceeded quota: default-zgkql, requested: limits.memory=15Gi',
        'event:orca-worker:FailedScheduling' => 'orca-worker: FailedScheduling (3×) — 0/80 nodes are available.',
    ])->and($assessment->warnings)->toBe([
        'db: FailedMount (2×) — timed out waiting for the volume',
        'cosmo-worker: 6 of 8 replicas ready.',
    ]);
});

test('a rollout that waited for the quota a moment is no problem once its component runs', function () {
    // The API rollout of 2026-11-27: eight attempts to create the new pod
    // failed while the old one still held the quota, then it started.
    fetchedServerStats(predictionServerPayload([
        'components' => [
            ['name' => 'api', 'kind' => 'Deployment', 'desired' => 1, 'ready' => 1, 'available' => 1, 'updated' => 1, 'rollout_stalled' => false],
        ],
        'events' => [quotaEvent('replicaset/api-768fccc8cd')],
        'events_total' => 1,
    ]));

    $assessment = assessPredictionServer();

    expect($assessment->problems)->toBe([])
        ->and($assessment->status)->toBe(HealthCheckResult::STATUS_WARNING)
        ->and($assessment->warnings)->toBe(['api: FailedCreate (12×) — exceeded quota: default-zgkql, requested: limits.memory=15Gi']);
});

test('pods a rollout replaces one by one are no problem while the rollout goes on', function () {
    fetchedServerStats(predictionServerPayload([
        'components' => [
            ['name' => 'orca-worker', 'kind' => 'Deployment', 'desired' => 144, 'ready' => 143, 'available' => 143, 'updated' => 40, 'rollout_stalled' => false],
        ],
        'events' => [quotaEvent('replicaset/orca-worker-5d8f7c9b6')],
    ]));

    expect(assessPredictionServer()->problems)->toBe([]);
});

test('a stalled rollout, or a server that does not say what it updated, keeps the quota a problem', function (array $component) {
    fetchedServerStats(predictionServerPayload([
        'components' => [['name' => 'orca-worker', 'kind' => 'Deployment', 'desired' => 144, 'ready' => 143, ...$component]],
        'events' => [quotaEvent('replicaset/orca-worker-5d8f7c9b6')],
    ]));

    expect(assessPredictionServer()->problems)->toHaveKey('event:orca-worker:FailedCreate');
})->with([
    'stalled rollout' => [['updated' => 40, 'rollout_stalled' => true]],
    'no updated count' => [['rollout_stalled' => false]],
]);

test('an announced quota problem ends once the rollout has brought its component back', function () {
    predictionServerMonitor();
    $this->travelTo(now()->startOfMinute());
    $short = fn (): array => predictionServerPayload([
        'components' => [['name' => 'cosmo-worker', 'kind' => 'Deployment', 'desired' => 8, 'ready' => 6, 'updated' => 8, 'rollout_stalled' => false]],
        'events' => [quotaEvent()],
    ]);

    fetchedServerStats($short());
    $this->artisan(CheckPredictionServerHealth::class)->assertSuccessful();
    $this->travel(CheckPredictionServerHealth::CONFIRM_MINUTES)->minutes();
    fetchedServerStats($short());
    $this->artisan(CheckPredictionServerHealth::class)->assertSuccessful();

    // The quota is free again: the component runs, the event is still recent.
    foreach ([1, 2] as $check) {
        $this->travel(5)->minutes();
        fetchedServerStats(predictionServerPayload(['events' => [quotaEvent()]]));
        $this->artisan(CheckPredictionServerHealth::class)->assertSuccessful();
    }

    expect(UserNotification::query()->orderBy('id')->pluck('title')->all())
        ->toBe(['Prediction server: 1 problem', 'Prediction server works again']);
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
        'activity' => [
            'optimization-orca' => ['queued' => 0, 'running' => 140, 'stale_running' => 2, 'last_completed_at' => now()->toIso8601String()],
            'cosmo' => ['queued' => 3, 'running' => 8, 'stale_running' => 0, 'last_completed_at' => now()->toIso8601String()],
        ],
    ]));

    expect(assessPredictionServer()->problems)->toBe([
        'stuck:optimization-orca' => 'Step optimization-orca: 2 running items without a sign of life from the worker.',
    ]);
});

test('an older server is judged by the age of the heartbeats it lists', function () {
    fetchedServerStats(predictionServerPayload(payload: [
        'activity' => [],
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
        'stuck:optimization-orca' => 'Step optimization-orca: 2 running items without a sign of life from the worker.',
        'stuck:cosmo' => 'Step cosmo: 1 running item without a sign of life from the worker.',
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

    expect(UserNotification::query()->count())->toBe(0);

    // Forgotten after a quarter of an hour without it.
    $this->travel(15)->minutes();
    fetchedServerStats(predictionServerPayload());
    $this->artisan(CheckPredictionServerHealth::class)->assertSuccessful();

    expect(predictionServerAlertState())->toBeNull();
});

test('a new kind of problem is announced, a lasting one again after a day, and the end of it too', function () {
    predictionServerMonitor();

    fetchedServerStats(imagePullOutagePayload());
    $this->artisan(CheckPredictionServerHealth::class);
    $this->travel(5)->minutes();
    fetchedServerStats(imagePullOutagePayload());
    $this->artisan(CheckPredictionServerHealth::class);
    expect(UserNotification::query()->count())->toBe(1);

    // The quota joins the outage: announced once it lasted five minutes.
    $withQuota = function (): array {
        $payload = imagePullOutagePayload();
        $payload['cluster']['events'] = [quotaEvent('replicaset/orca-worker-66c48d779d')];

        return $payload;
    };
    $this->travel(5)->minutes();
    fetchedServerStats($withQuota());
    $this->artisan(CheckPredictionServerHealth::class);
    expect(UserNotification::query()->count())->toBe(1);

    $this->travel(5)->minutes();
    fetchedServerStats($withQuota());
    $this->artisan(CheckPredictionServerHealth::class);
    expect(UserNotification::query()->count())->toBe(2)
        ->and(UserNotification::query()->latest('id')->first()->title)->toBe('Prediction server still: 5 problems');

    // A day later it still lasts.
    $this->travel(1)->days();
    fetchedServerStats($withQuota());
    $this->artisan(CheckPredictionServerHealth::class);
    expect(UserNotification::query()->count())->toBe(3);

    // Fixed: announced once a second check finds it fine too.
    $this->travel(5)->minutes();
    fetchedServerStats(predictionServerPayload());
    $this->artisan(CheckPredictionServerHealth::class);
    expect(UserNotification::query()->count())->toBe(3);

    $this->travel(5)->minutes();
    fetchedServerStats(predictionServerPayload());
    $this->artisan(CheckPredictionServerHealth::class);

    $resolved = UserNotification::query()->latest('id')->first();
    expect(UserNotification::query()->count())->toBe(4)
        ->and($resolved->title)->toBe('Prediction server works again')
        ->and($resolved->body)->toBe('The problems announced at 2026-11-27 10:05 are gone.')
        ->and(predictionServerAlertState())->toBeNull();
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

test('the dashboard widget is shown to the system monitors its notifications send there, and to nobody else', function () {
    seedFilamentPredictionPermissions();

    $this->actingAs(createFilamentPredictionUser([PermissionEnums::SYSTEM_MONITOR]));
    expect(PredictionServerHealthWidget::canView())->toBeTrue();

    $this->actingAs(createFilamentPredictionUser([PermissionEnums::ADMIN_PANEL]));
    expect(PredictionServerHealthWidget::canView())->toBeFalse();
});

test('a problem that comes and goes is announced once, and its end only when it stays away', function () {
    predictionServerMonitor();

    // Crashing, running for a moment, crashing again.
    foreach ([true, false, true, false, true, false, false] as $broken) {
        fetchedServerStats($broken ? imagePullOutagePayload() : predictionServerPayload());
        $this->artisan(CheckPredictionServerHealth::class)->assertSuccessful();
        $this->travel(5)->minutes();
    }

    expect(UserNotification::query()->orderBy('id')->pluck('title')->all())->toBe([
        'Prediction server: 4 problems',
        'Prediction server works again',
    ]);
});

test('the alert survives the cache being cleared on a restart', function () {
    predictionServerMonitor();
    fetchedServerStats(imagePullOutagePayload());
    $this->artisan(CheckPredictionServerHealth::class);
    $this->travel(5)->minutes();
    fetchedServerStats(imagePullOutagePayload());
    $this->artisan(CheckPredictionServerHealth::class);

    $this->artisan('cache:clear');
    $this->travel(5)->minutes();
    fetchedServerStats(imagePullOutagePayload());
    $this->artisan(CheckPredictionServerHealth::class);

    expect(UserNotification::query()->count())->toBe(1);
});

test('switching remote predictions off during an announced outage says the problems are no longer watched', function () {
    predictionServerMonitor();
    fetchedServerStats(imagePullOutagePayload());
    $this->artisan(CheckPredictionServerHealth::class);
    $this->travel(5)->minutes();
    fetchedServerStats(imagePullOutagePayload());
    $this->artisan(CheckPredictionServerHealth::class);

    Config::set(Config::KEY_REMOTE_PREDICTION_ENABLED, '0');
    $this->artisan(CheckPredictionServerHealth::class);

    expect(UserNotification::query()->latest('id')->first()->title)->toBe('Prediction server no longer watched')
        ->and(predictionServerAlertState())->toBeNull();
});

test('a malformed answer from the server does not break the check', function () {
    fetchedServerStats(predictionServerPayload(
        [
            'components' => [['name' => ['x' => 1], 'desired' => 'many'], ['name' => 'api', 'kind' => ['Deployment'], 'desired' => 1, 'ready' => 1]],
            'problems' => [['pod' => ['a'], 'component' => null, 'reason' => ['ImagePullBackOff'], 'message' => ['x'], 'since' => ['2026']], 'nonsense'],
            'problems_total' => ['many'],
            'events' => [['reason' => ['FailedCreate'], 'object' => 7, 'message' => null, 'count' => 'x', 'last_seen' => 'not a time'], 'nonsense'],
        ],
        ['activity' => ['cosmo' => ['queued' => ['x'], 'running' => null, 'stale_running' => 'x', 'last_completed_at' => 'yesterday-ish']], 'running' => 'nonsense'],
    ));

    $assessment = assessPredictionServer();

    expect($assessment->components)->toBe([['name' => 'api', 'kind' => '', 'desired' => 1, 'ready' => 1, 'rollout_stalled' => false]])
        ->and($assessment->problems)->toBe([])
        ->and($assessment->warnings)->toBe(['unknown: 1 pod unknown']);

    $this->artisan(CheckPredictionServerHealth::class)->assertSuccessful();
});

test('markup from the server reaches the notification and the dashboard as text', function () {
    predictionServerMonitor();
    seedFilamentPredictionPermissions();
    $payload = imagePullOutagePayload();
    $payload['cluster']['problems'][2]['message'] = '<script>alert(1)</script><img src=x onerror=alert(2)>';

    fetchedServerStats($payload);
    $this->artisan(CheckPredictionServerHealth::class);
    $this->travel(5)->minutes();
    fetchedServerStats($payload);
    $this->artisan(CheckPredictionServerHealth::class);

    $message = UserNotification::query()->sole()->email_message;
    expect($message)->not->toContain('<script>')
        ->and($message)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');

    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTIONS_MANAGE]));
    Livewire::test(PredictionServerHealthWidget::class)
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;');
});

test('times are shown in the application time zone', function () {
    config()->set('app.timezone', 'Europe/Prague');
    date_default_timezone_set('Europe/Prague');

    try {
        fetchedServerStats(imagePullOutagePayload());

        expect(assessPredictionServer()->problems['step:optimization-turbomole'])
            ->toContain('last finished 2026-11-16 14:59');
    } finally {
        config()->set('app.timezone', 'UTC');
        date_default_timezone_set('UTC');
    }
});

test('the public statistics leave the cluster and the queues per step out', function () {
    fetchedServerStats(imagePullOutagePayload());

    $this->getJson('/api/predictions/server-stats')
        ->assertOk()
        ->assertJsonMissingPath('data.remote.payload.cluster')
        ->assertJsonMissingPath('data.remote.payload.activity')
        ->assertJsonPath('data.remote.payload.queue.cosmo', 3);
});
