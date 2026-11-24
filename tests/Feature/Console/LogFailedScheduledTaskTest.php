<?php

use App\Enums\PermissionEnums;
use App\Models\BaseModel;
use App\Models\Permission;
use App\Models\QueuedNotification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/*
 * A failed scheduled task is written to the system activity log (throttled
 * per task) and reported to the holders of the system monitor permission
 * through the batched job-failure notification.
 */

beforeEach(function () {
    Notification::fake();
});

function createScheduledTaskMonitor(): User
{
    Permission::query()->firstOrCreate(
        ['name' => PermissionEnums::SYSTEM_MONITOR->value, 'guard_name' => 'web'],
        ['description' => PermissionEnums::SYSTEM_MONITOR->description()],
    );
    $role = Role::query()->create(['name' => 'Monitor '.Str::random(8), 'guard_name' => 'web']);
    $role->givePermissionTo(PermissionEnums::SYSTEM_MONITOR->value);
    $user = User::factory()->create();
    $user->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

function failScheduledTask(string $description, int $exitCode, Throwable $exception): void
{
    $task = app(Schedule::class)->command('db:dump')->description($description);
    $task->exitCode = $exitCode;

    event(new ScheduledTaskFailed($task, $exception));
}

test('a failed scheduled task is logged and reported to system monitors', function () {
    $monitor = createScheduledTaskMonitor();
    $bystander = User::factory()->create();

    failScheduledTask('Full database backup', 1, new RuntimeException('pg_dump: error: connection refused'));

    $activity = Activity::query()->where('event', 'scheduled_task_failed')->sole();

    expect($activity->log_name)->toBe(BaseModel::ACTIVITY_LOG_SYSTEM)
        ->and($activity->description)->toBe('Scheduled task failed: Full database backup.')
        ->and($activity->properties->all())->toMatchArray([
            'severity' => 'error',
            'task' => 'Full database backup',
            'exit_code' => 1,
            'exception' => RuntimeException::class,
            'error' => 'pg_dump: error: connection refused',
        ]);

    $queued = QueuedNotification::query()->get();

    expect($queued)->toHaveCount(1)
        ->and($queued->first()->notifiable_id)->toBe($monitor->id)
        ->and($queued->first()->event)->toBe('system.admin.job_failed')
        ->and($queued->first()->data)->toBe([
            'label' => 'Scheduled task: Full database backup',
            'error' => 'pg_dump: error: connection refused',
        ])
        ->and(QueuedNotification::query()->where('notifiable_id', $bystander->id)->exists())->toBeFalse();
});

test('repeated failures of a task are logged once but every one is reported', function () {
    createScheduledTaskMonitor();

    failScheduledTask('Full database backup', 1, new RuntimeException('first'));
    failScheduledTask('Full database backup', 1, new RuntimeException('second'));
    failScheduledTask('Sitemap', 2, new RuntimeException('third'));

    expect(Activity::query()->where('event', 'scheduled_task_failed')->pluck('properties')->map(fn ($properties) => $properties['error'])->sort()->values()->all())
        ->toBe(['first', 'third'])
        ->and(QueuedNotification::query()->count())->toBe(3);
});

test('a long error is shortened in the log and the notification', function () {
    createScheduledTaskMonitor();

    failScheduledTask('Full database backup', 1, new RuntimeException(str_repeat('x', 1500)));

    $error = Activity::query()->where('event', 'scheduled_task_failed')->sole()->properties['error'];

    expect($error)->toBe(str_repeat('x', 1000).'...')
        ->and(QueuedNotification::query()->sole()->data['error'])->toBe($error);
});
