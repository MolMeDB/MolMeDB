<?php

use App\Enums\NotificationType;
use App\Enums\PermissionEnums;
use App\Models\ApplicationError;
use App\Models\Permission;
use App\Models\QueuedNotification;
use App\Models\Role;
use App\Models\User;
use App\Services\ApplicationErrorRecorder;
use App\Services\NotificationBatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/*
 * Errors the application reports are counted per day and kind, so that one
 * that happens in a request (and so fails no job and no scheduled task)
 * reaches the system monitors: at once when it keeps repeating, and with the
 * daily system health report otherwise.
 */

function userWhoMonitorsTheSystem(): User
{
    Permission::query()->firstOrCreate(
        ['name' => PermissionEnums::SYSTEM_MONITOR->value, 'guard_name' => 'web'],
        ['description' => PermissionEnums::SYSTEM_MONITOR->description()],
    );
    $role = Role::query()->create(['name' => 'Monitors '.fake()->unique()->numberBetween(1, 1_000_000), 'guard_name' => 'web']);
    $role->givePermissionTo(PermissionEnums::SYSTEM_MONITOR->value);
    $user = User::factory()->create();
    $user->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

/**
 * The error that went unnoticed on production for months: a write to
 * `structures` refused by a corrupted Bingo index.
 */
function corruptedIndexError(int $block = 32365): QueryException
{
    return new QueryException(
        'pgsql',
        'update "structures" set "molfile_3d" = ?, "updated_at" = ? where "id" = ?',
        ["\n     RDKit          3D\n\n 24 23  0  0 SECRET-MOLFILE", '2026-08-11 11:32:44', 1916],
        new PDOException("SQLSTATE[XX000]: Internal error: 7 ERROR:  error: bingo buffer: internal error: corrupted block {$block} data len is -8"),
    );
}

test('a reported error is counted with where it happened', function () {
    app(ApplicationErrorRecorder::class)->record(new RuntimeException('The export could not be written.'));

    $error = ApplicationError::query()->sole();

    expect($error->exception)->toBe(RuntimeException::class)
        ->and($error->message)->toBe('The export could not be written.')
        ->and($error->occurrences)->toBe(1)
        ->and($error->day->toDateString())->toBe(now()->toDateString())
        ->and($error->location)->toStartWith('tests/Feature/SystemHealth/ApplicationErrorRecorderTest.php:')
        ->and($error->context)->toStartWith('console: ');
});

test('the same error with other numbers in it is the same kind of error', function () {
    $recorder = app(ApplicationErrorRecorder::class);

    $recorder->record(corruptedIndexError(32365));
    $recorder->record(corruptedIndexError(118));
    $recorder->record(new RuntimeException('Something else.'));

    expect(ApplicationError::query()->count())->toBe(2)
        ->and(ApplicationError::query()->where('exception', QueryException::class)->sole()->occurrences)->toBe(2);
});

test('a failed query is kept without its SQL and the values in it', function () {
    app(ApplicationErrorRecorder::class)->record(corruptedIndexError());

    $error = ApplicationError::query()->sole();

    expect($error->message)->toBe('SQLSTATE[XX000]: Internal error: 7 ERROR: error: bingo buffer: internal error: corrupted block 32365 data len is -8')
        ->and(json_encode($error->getAttributes()))->not->toContain('SECRET-MOLFILE')->not->toContain('molfile_3d');
});

test('errors are counted for each day apart', function () {
    $recorder = app(ApplicationErrorRecorder::class);

    $recorder->record(new RuntimeException('Still broken.'));
    $this->travel(1)->days();
    $recorder->record(new RuntimeException('Still broken.'));

    expect(ApplicationError::query()->count())->toBe(2)
        ->and(ApplicationError::query()->pluck('occurrences')->all())->toBe([1, 1]);
});

test('what the exception handler reports is counted', function () {
    report(new RuntimeException('Reported from the code.'));

    expect(ApplicationError::query()->sole()->message)->toBe('Reported from the code.');
});

test('a page that does not exist is not an error of the application', function () {
    $this->get('/this-page-does-not-exist-'.fake()->uuid())->assertNotFound();

    expect(ApplicationError::query()->count())->toBe(0);
});

test('an error that keeps repeating is told to the system monitors once', function () {
    $monitor = userWhoMonitorsTheSystem();
    $recorder = app(ApplicationErrorRecorder::class);

    $queued = fn (): int => QueuedNotification::query()
        ->where('group_key', NotificationBatcher::groupKey(NotificationType::SYSTEM_ADMIN_JOB_FAILED))
        ->where('notifiable_id', $monitor->id)
        ->count();

    foreach (range(1, ApplicationErrorRecorder::ALERT_AFTER_OCCURRENCES - 1) as $ignored) {
        $recorder->record(corruptedIndexError());
    }

    expect($queued())->toBe(0);

    $recorder->record(corruptedIndexError());
    $recorder->record(corruptedIndexError());

    $error = ApplicationError::query()->sole();
    $activity = Activity::query()->where('event', 'application_error_repeated')->sole();

    expect($queued())->toBe(1)
        ->and($error->occurrences)->toBe(ApplicationErrorRecorder::ALERT_AFTER_OCCURRENCES + 1)
        ->and($error->notified_at)->not->toBeNull()
        ->and($activity->description)->toContain('corrupted block 32365')
        ->and($activity->properties['severity'])->toBe('error');
});

test('an error that cannot be counted does not raise another one', function () {
    Schema::drop('application_errors');

    app(ApplicationErrorRecorder::class)->record(new RuntimeException('The table is gone.'));

    expect(Schema::hasTable('application_errors'))->toBeFalse();
});
