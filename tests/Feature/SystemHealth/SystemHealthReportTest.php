<?php

require_once __DIR__.'/../Predictions/prediction_helpers.php';

use App\Console\Commands\Cron\SendSystemHealthReport;
use App\Enums\NotificationDeliveryMode;
use App\Enums\NotificationType;
use App\Enums\PermissionEnums;
use App\Models\ApplicationError;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\SystemActivityLogger;
use App\Services\SystemHealth\HealthCheckResult;
use App\Services\SystemHealth\SystemHealthReport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Modules\PredictionWorkers\Models\Prediction;
use Spatie\Permission\PermissionRegistrar;

/*
 * The daily system health report: what the application reported as errors
 * the day before, what failed, and the state of the parts that fail without
 * anybody being told (the prediction import, the structure search index,
 * logs and disk space). It goes to the users who monitor the system.
 */

beforeEach(function () {
    Notification::fake();
    $this->travelTo('2026-11-23 07:30:00');
});

function healthReportMonitor(): User
{
    Permission::query()->firstOrCreate(
        ['name' => PermissionEnums::SYSTEM_MONITOR->value, 'guard_name' => 'web'],
        ['description' => PermissionEnums::SYSTEM_MONITOR->description()],
    );
    $role = Role::query()->create(['name' => 'Health monitors '.fake()->unique()->numberBetween(1, 1_000_000), 'guard_name' => 'web']);
    $role->givePermissionTo(PermissionEnums::SYSTEM_MONITOR->value);
    $user = User::factory()->create();
    $user->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

function healthCheck(string $label): HealthCheckResult
{
    $results = app(SystemHealthReport::class)->run(now()->subDay());

    return collect($results)->sole(fn (HealthCheckResult $result): bool => $result->label === $label);
}

test('the report is sent to the users who monitor the system, and to nobody else', function () {
    $monitor = healthReportMonitor();
    $other = User::factory()->create();
    ApplicationError::factory()->seenOn('2026-11-22', 3)->create(['message' => 'The export could not be written.']);

    $this->artisan(SendSystemHealthReport::class)->assertSuccessful();

    $notification = UserNotification::query()->where('user_id', $monitor->id)->sole();

    expect($notification->title)->toStartWith('System health 2026-11-22: ')
        ->and($notification->email_subject)->toStartWith('MolMeDB: system health 2026-11-22 — ')
        ->and($notification->body)->toContain('Application errors: 3 errors of 1 kind reported.')
        ->and($notification->email_message)
        ->toContain('<table')
        ->toContain('Application errors')
        ->toContain('3× RuntimeException: The export could not be written.')
        ->toContain('Prediction import')
        ->toContain('Logs and disk space')
        ->and($notification->emailed_at)->not->toBeNull()
        ->and(UserNotification::query()->where('user_id', $other->id)->count())->toBe(0);
});

test('the report is sent on a day without anything to report', function () {
    $monitor = healthReportMonitor();

    $this->artisan(SendSystemHealthReport::class)->assertSuccessful();

    expect(UserNotification::query()->where('user_id', $monitor->id)->sole()->email_message)
        ->toContain('No error was reported.')
        ->toContain('Nothing failed.')
        ->toContain('0 predictions waiting for import');
});

test('the report is delivered at once, by e-mail, to those with the permission', function () {
    $type = NotificationType::SYSTEM_ADMIN_HEALTH_REPORT;

    expect($type->deliveryMode())->toBe(NotificationDeliveryMode::IMMEDIATE)
        ->and($type->permission())->toBe(PermissionEnums::SYSTEM_MONITOR)
        ->and($type->defaultEmail())->toBeTrue()
        ->and($type->group())->toBe('Administration – system health')
        ->and($type->label())->toBe('System admin: daily system health report')
        ->and(NotificationTemplate::query()->where('key', $type->value)->where('is_active', true)->exists())->toBeTrue();
});

test('it is scheduled every morning', function () {
    $event = collect(app(Schedule::class)->events())
        ->sole(fn ($event): bool => str_contains($event->command ?? '', 'cron:system-health-report'));

    expect($event->expression)->toBe('30 7 * * *');
});

test('errors of the day are listed by how often they happened', function () {
    ApplicationError::factory()->seenOn('2026-11-22', 2)->create(['message' => 'Seen twice.', 'location' => 'app/A.php:10', 'context' => 'GET api/a']);
    ApplicationError::factory()->seenOn('2026-11-22', 7)->create(['message' => 'Seen seven times.', 'location' => 'app/B.php:20', 'context' => null]);
    ApplicationError::factory()->seenOn('2026-11-21', 90)->create(['message' => 'The day before.']);
    ApplicationError::factory()->seenOn('2026-11-23', 90)->create(['message' => 'Today.']);

    $check = healthCheck('Application errors');

    expect($check->status)->toBe(HealthCheckResult::STATUS_WARNING)
        ->and($check->summary)->toBe('9 errors of 2 kinds reported.')
        ->and($check->details)->toBe([
            '7× RuntimeException: Seen seven times. — app/B.php:20',
            '2× RuntimeException: Seen twice. — app/A.php:10 [GET api/a]',
        ]);
});

test('an error that kept repeating is a problem, not a warning', function () {
    ApplicationError::factory()->seenOn('2026-11-22', 855)->create(['message' => 'bingo buffer: internal error: corrupted block 32365']);

    expect(healthCheck('Application errors')->status)->toBe(HealthCheckResult::STATUS_PROBLEM);
});

test('only the most frequent kinds of errors are listed', function () {
    foreach (range(1, 12) as $occurrences) {
        ApplicationError::factory()->seenOn('2026-11-22', $occurrences)->create();
    }

    $details = healthCheck('Application errors')->details;

    expect($details)->toHaveCount(11)
        ->and($details[0])->toStartWith('12× ')
        ->and($details[10])->toBe('… and 2 more kinds.');
});

test('failed jobs and scheduled tasks of the day are listed', function () {
    $logger = app(SystemActivityLogger::class);

    $this->travelTo('2026-11-22 03:00:00');
    $logger->log('scheduled_task_failed', 'Scheduled task failed: cron:predictions-worker.', [], SystemActivityLogger::SEVERITY_ERROR);
    $logger->log('scheduled_task_failed', 'Scheduled task failed: cron:predictions-worker.', [], SystemActivityLogger::SEVERITY_ERROR);
    $logger->log('queue_job_failed', 'Queue job failed: ProcessUploadQueueRecord.', [], SystemActivityLogger::SEVERITY_ERROR);
    $logger->log('backup_finished', 'Backup finished.', [], SystemActivityLogger::SEVERITY_INFO);
    $this->travelTo('2026-11-21 03:00:00');
    $logger->log('queue_job_failed', 'Queue job failed: TheDayBefore.', [], SystemActivityLogger::SEVERITY_ERROR);
    $this->travelTo('2026-11-23 07:30:00');

    $check = healthCheck('Failed jobs and scheduled tasks');

    expect($check->status)->toBe(HealthCheckResult::STATUS_PROBLEM)
        ->and($check->summary)->toBe('3 failures recorded.')
        ->and($check->details)->toBe([
            '2× Scheduled task failed: cron:predictions-worker.',
            '1× Queue job failed: ProcessUploadQueueRecord.',
        ]);
});

test('predictions that wait while nothing is imported are a problem', function () {
    createPredictionDataset(null, ['step' => Prediction::STEP_RESULT_PARSE, 'state' => Prediction::STATE_FINISHED]);
    createPredictionDataset(null, ['step' => Prediction::STEP_RESULT_PARSE, 'state' => Prediction::STATE_FINISHED]);
    createPredictionDataset(null, ['step' => Prediction::STEP_COSMO, 'state' => Prediction::STATE_RUNNING]);

    $check = healthCheck('Prediction import');

    expect($check->status)->toBe(HealthCheckResult::STATUS_PROBLEM)
        ->and($check->summary)->toBe('2 predictions waiting for import, 0 imported on 2026-11-22.');
});

test('predictions that wait while others are imported are a queue, not a problem', function () {
    createPredictionDataset(null, ['step' => Prediction::STEP_RESULT_PARSE, 'state' => Prediction::STATE_FINISHED]);
    $imported = createPredictionDataset(null, ['step' => Prediction::STEP_RESULT_DB_STORE, 'state' => Prediction::STATE_FINISHED])['prediction'];
    Prediction::query()->whereKey($imported->id)->update(['updated_at' => '2026-11-22 14:00:00']);

    $check = healthCheck('Prediction import');

    expect($check->status)->toBe(HealthCheckResult::STATUS_OK)
        ->and($check->summary)->toBe('1 prediction waiting for import, 1 imported on 2026-11-22.');
});

test('the structure search index is only checked where it exists', function () {
    $check = healthCheck('Structure search index');

    expect($check->status)->toBe(HealthCheckResult::STATUS_OK)
        ->and($check->summary)->toBe('Not checked: the database is not PostgreSQL.');
});

test('logs and disk space are reported', function () {
    expect(healthCheck('Logs and disk space')->summary)->toMatch('/^Logs take [\d.,]+ (B|KB|MB|GB), .+ free on the disk\.$/');
});

test('a check that cannot run is reported and the others still run', function () {
    Schema::drop('application_errors');

    $results = app(SystemHealthReport::class)->run(now()->subDay());

    expect($results)->toHaveCount(5)
        ->and($results[0]->label)->toBe('Application errors')
        ->and($results[0]->status)->toBe(HealthCheckResult::STATUS_WARNING)
        ->and($results[0]->summary)->toStartWith('Could not be checked: ')
        ->and($results[2]->summary)->toContain('waiting for import');
});

test('the status counts problems and warnings', function (array $statuses, string $expected) {
    $results = array_map(fn (string $status): HealthCheckResult => new HealthCheckResult('Check', $status, 'Summary.'), $statuses);

    expect(app(SystemHealthReport::class)->status($results))->toBe($expected);
})->with([
    'nothing' => [['ok', 'ok'], 'all checks passed'],
    'one problem' => [['ok', 'problem'], '1 problem'],
    'both' => [['problem', 'warning', 'problem', 'ok'], '2 problems, 1 warning'],
    'warnings' => [['warning', 'warning'], '2 warnings'],
]);

test('what the checks found is escaped in the e-mail', function () {
    $html = app(SystemHealthReport::class)->toHtml([
        HealthCheckResult::problem('Application errors', 'One <b>error</b>.', ['1× <script>alert(1)</script>']),
    ])->toHtml();

    expect($html)->toContain('One &lt;b&gt;error&lt;/b&gt;.')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>');
});

test('counted errors are kept for a month', function () {
    healthReportMonitor();
    ApplicationError::factory()->seenOn('2026-10-23')->create(['message' => 'Old.']);
    ApplicationError::factory()->seenOn('2026-10-24')->create(['message' => 'Kept.']);

    $this->artisan(SendSystemHealthReport::class)->assertSuccessful();

    expect(ApplicationError::query()->pluck('message')->all())->toBe(['Kept.']);
});
