<?php

use App\Enums\PermissionEnums;
use App\Models\ApplicationError;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\ApplicationErrorRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/*
 * Errors the application reports are counted per day and kind, so that one
 * that happens in a request (and so fails no job and no scheduled task)
 * reaches the system monitors: at once when it keeps repeating, and with the
 * daily system health report otherwise.
 */

beforeEach(fn () => Notification::fake());

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
function corruptedIndexError(int $block = 32365, string $detail = ''): QueryException
{
    return new QueryException(
        'pgsql',
        'update "structures" set "molfile_3d" = ?, "updated_at" = ? where "id" = ?',
        ["\n     RDKit          3D\n\n 24 23  0  0 SECRET-MOLFILE", '2026-08-11 11:32:44', 1916],
        new PDOException("SQLSTATE[XX000]: Internal error: 7 ERROR:  error: bingo buffer: internal error: corrupted block {$block} data len is -8{$detail}"),
    );
}

test('a reported error is counted with where it happened', function () {
    app(ApplicationErrorRecorder::class)->record(new RuntimeException('The export could not be written.'));

    $error = ApplicationError::query()->sole();

    expect($error->exception)->toBe(RuntimeException::class)
        ->and($error->message)->toBe('The export could not be written.')
        ->and($error->occurrences)->toBe(1)
        ->and($error->day)->toBe(now()->toDateString())
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

test('what the database quotes from the row is not kept', function () {
    $recorder = app(ApplicationErrorRecorder::class);

    $recorder->record(new QueryException('pgsql', 'insert into "users" ("email") values (?)', ['jane@example.org'], new PDOException(
        "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"users_email_unique\"\nDETAIL:  Key (email)=(jane@example.org) already exists.",
    )));
    $recorder->record(new QueryException('pgsql', 'select * from "structures" where "id" = ?', ['MM-secret'], new PDOException(
        'SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type bigint: "MM-secret"',
    )));
    $recorder->record(new RuntimeException('Mail to john.doe@example.org was refused.'));

    $messages = ApplicationError::query()->orderBy('id')->pluck('message')->all();

    expect($messages)->toBe([
        'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "users_email_unique"',
        'SQLSTATE[22P02]: Invalid text representation: 7 ERROR: invalid input syntax for type bigint: "…"',
        'Mail to [e-mail] was refused.',
    ]);
});

test('an error with another identifier in it is the same kind of error', function () {
    $recorder = app(ApplicationErrorRecorder::class);

    $recorder->record(new RuntimeException('Export 0d9f6a52-7c1e-4b7e-9a51-0f4f5d1f9c11 failed at a3f9c0d2e4b6a8c1.'));
    $recorder->record(new RuntimeException('Export 7b1e2c33-0a4d-4f0e-8c2b-6d5e4f3a2b1c failed at 9e8d7c6b5a4f3e2d.'));

    expect(ApplicationError::query()->sole()->occurrences)->toBe(2);
});

test('a failed query is placed in the code of the application, not in the framework', function () {
    // As it comes from the database layer: created inside the framework,
    // called from a model of the application.
    $exception = corruptedIndexError();
    (new ReflectionProperty(Exception::class, 'file'))->setValue($exception, base_path('vendor/laravel/framework/src/Illuminate/Database/Connection.php'));
    (new ReflectionProperty(Exception::class, 'line'))->setValue($exception, 838);
    (new ReflectionProperty(Exception::class, 'trace'))->setValue($exception, [
        ['file' => base_path('vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php'), 'line' => 1290],
        ['file' => app_path('Models/Structure.php'), 'line' => 412],
        ['file' => app_path('Http/Controllers/StructureController.php'), 'line' => 77],
    ]);

    app(ApplicationErrorRecorder::class)->record($exception);

    expect(ApplicationError::query()->sole()->location)->toBe('app/Models/Structure.php:412');
});

test('an error in a request is kept with its route, not with the address', function () {
    Route::get('/_test/failing/{structure}', fn () => throw new RuntimeException('The page failed.'));

    $this->get('/_test/failing/MM00042?token=secret')->assertStatus(500);

    expect(ApplicationError::query()->sole()->context)->toBe('GET _test/failing/{structure}');
});

test('no more kinds of errors are kept for a day than fit a report', function () {
    ApplicationError::factory()->count(ApplicationErrorRecorder::MAX_KINDS_PER_DAY)->create();

    app(ApplicationErrorRecorder::class)->record(new RuntimeException('One kind too many.'));

    expect(ApplicationError::query()->count())->toBe(ApplicationErrorRecorder::MAX_KINDS_PER_DAY);
});

test('an error that keeps repeating is told to the system monitors once, by e-mail', function () {
    $monitor = userWhoMonitorsTheSystem();
    $recorder = app(ApplicationErrorRecorder::class);

    foreach (range(1, ApplicationErrorRecorder::ALERT_AFTER_OCCURRENCES - 1) as $ignored) {
        $recorder->record(corruptedIndexError());
    }

    expect(UserNotification::query()->where('user_id', $monitor->id)->count())->toBe(0);

    $recorder->record(corruptedIndexError());
    $recorder->record(corruptedIndexError());

    $notification = UserNotification::query()->where('user_id', $monitor->id)->sole();
    $error = ApplicationError::query()->sole();
    $activity = Activity::query()->where('event', 'application_error_repeated')->sole();

    expect($notification->title)->toBe('An application error keeps repeating')
        ->and($notification->body)->toContain('25× today: QueryException: ')->toContain('corrupted block 32365')
        ->and($notification->email_message)->toContain('tests/Feature/SystemHealth/ApplicationErrorRecorderTest.php:')
        ->and($notification->emailed_at)->not->toBeNull()
        ->and($error->occurrences)->toBe(ApplicationErrorRecorder::ALERT_AFTER_OCCURRENCES + 1)
        ->and($error->notified_at)->not->toBeNull()
        ->and($activity->description)->toContain('corrupted block 32365')
        ->and($activity->properties['severity'])->toBe('error');
});

test('an alert that could not be sent is sent with the next occurrence', function () {
    $monitor = userWhoMonitorsTheSystem();
    $recorder = app(ApplicationErrorRecorder::class);
    // A template without its variable cannot be rendered.
    $template = NotificationTemplate::query()->where('key', NotificationTemplate::KEY_SYSTEM_ADMIN_ERROR_REPEATED)->sole();
    NotificationTemplate::query()->whereKey($template->id)->delete();

    foreach (range(1, ApplicationErrorRecorder::ALERT_AFTER_OCCURRENCES) as $ignored) {
        $recorder->record(corruptedIndexError());
    }

    expect(UserNotification::query()->where('user_id', $monitor->id)->count())->toBe(0);

    NotificationTemplate::query()->create($template->getAttributes());
    ApplicationError::query()->update(['notified_at' => null]);
    $recorder->record(corruptedIndexError());

    expect(UserNotification::query()->where('user_id', $monitor->id)->count())->toBe(1);
});

test('an error that cannot be counted does not raise another one', function () {
    Schema::drop('application_errors');

    app(ApplicationErrorRecorder::class)->record(new RuntimeException('The table is gone.'));

    expect(Schema::hasTable('application_errors'))->toBeFalse();
});
