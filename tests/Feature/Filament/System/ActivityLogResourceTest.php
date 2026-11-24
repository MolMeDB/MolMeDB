<?php

use App\Enums\PermissionEnums;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\ActivityLogs\Pages\ViewActivityLog;
use App\Filament\Widgets\LatestActivityLogsWidget;
use App\Models\BaseModel;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SshCredential;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/*
 * The activity log records what every user changed, so only holders of the
 * settings permissions read it, and it never stores credentials.
 */

beforeEach(function () {
    collect(PermissionEnums::cases())->each(fn (PermissionEnums $permission) => Permission::query()->firstOrCreate(
        ['name' => $permission->value, 'guard_name' => 'web'],
        ['description' => $permission->description()],
    ));

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * @param  array<int, PermissionEnums>  $permissions
 */
function activityLogPanelUser(array $permissions = []): User
{
    $role = Role::query()->create(['name' => 'Activity role '.fake()->unique()->numberBetween(1, 1_000_000), 'guard_name' => 'web']);
    $role->givePermissionTo([PermissionEnums::ADMIN_PANEL, ...$permissions]);

    $user = User::factory()->create();
    $user->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

function logActivity(string $logName, string $description, ?string $event = null): Activity
{
    $logger = activity($logName);

    if ($event !== null) {
        $logger->event($event);
    }

    return $logger->log($description);
}

test('the log list shows system entries by default and every entry once the filter is cleared', function () {
    $systemEntry = logActivity(BaseModel::ACTIVITY_LOG_SYSTEM, 'Backup disk reconfigured', 'updated');
    $defaultEntry = logActivity('default', 'Structure renamed', 'updated');

    $this->actingAs(activityLogPanelUser([PermissionEnums::SETTINGS_VIEW]));

    Livewire::test(ListActivityLogs::class)
        ->assertCanSeeTableRecords([$systemEntry])
        ->assertCanNotSeeTableRecords([$defaultEntry])
        ->removeTableFilter('log_name')
        ->assertCanSeeTableRecords([$systemEntry, $defaultEntry])
        ->filterTable('event', 'updated')
        ->assertCanSeeTableRecords([$systemEntry, $defaultEntry]);
});

test('the log list filters by causer, subject and creation date', function () {
    $causer = User::factory()->create();
    $credential = SshCredential::query()->create(['name' => 'Backup', 'username' => 'deploy', 'type' => SshCredential::AUTH_TYPE_PASSWORD, 'password' => 'p']);

    $causedEntry = activity(BaseModel::ACTIVITY_LOG_SYSTEM)->causedBy($causer)->performedOn($credential)->log('Credential rotated');
    $oldEntry = logActivity(BaseModel::ACTIVITY_LOG_SYSTEM, 'Old entry');
    $oldEntry->forceFill(['created_at' => now()->subYear()])->save();

    $this->actingAs(activityLogPanelUser([PermissionEnums::SETTINGS_VIEW]));

    Livewire::test(ListActivityLogs::class)
        ->filterTable('causer_type', User::class)
        ->assertCanSeeTableRecords([$causedEntry])
        ->assertCanNotSeeTableRecords([$oldEntry])
        ->removeTableFilter('causer_type')
        ->filterTable('subject_type', SshCredential::class)
        ->assertCanSeeTableRecords([$causedEntry])
        ->removeTableFilter('subject_type')
        ->filterTable('created_at', ['created_from' => now()->subDay()->toDateString(), 'created_until' => now()->toDateString()])
        ->assertCanSeeTableRecords([$causedEntry])
        ->assertCanNotSeeTableRecords([$oldEntry]);
});

test('the log filter options list the logged values, with class names shortened', function () {
    $credential = SshCredential::query()->create(['name' => 'Backup', 'username' => 'deploy', 'type' => SshCredential::AUTH_TYPE_PASSWORD, 'password' => 'p']);
    activity('default')->performedOn($credential)->event('created')->log('Created');

    expect(ActivityLogResource::distinctOptions('log_name'))->toHaveKeys(['default', BaseModel::ACTIVITY_LOG_SYSTEM])
        ->and(ActivityLogResource::classOptions('subject_type'))->toMatchArray([SshCredential::class => 'SshCredential'])
        ->and(ActivityLogResource::formatMorph(SshCredential::class, 7))->toBe('SshCredential #7')
        ->and(ActivityLogResource::formatMorph(null, 7))->toBe('-')
        ->and(ActivityLogResource::formatProperties(collect()))->toBe('')
        ->and(ActivityLogResource::formatProperties(['old' => ['name' => 'Ä']]))->toContain('"name": "Ä"')
        ->and(ActivityLogResource::propertiesToArray('not properties'))->toBe([]);
});

test('settings viewers open a log entry with its subject, causer and properties', function () {
    $causer = User::factory()->create();
    $entry = activity(BaseModel::ACTIVITY_LOG_SYSTEM)
        ->causedBy($causer)
        ->withProperties(['attributes' => ['host' => 'storage.example.org']])
        ->log('Disk moved');

    $this->actingAs(activityLogPanelUser([PermissionEnums::SETTINGS_VIEW]));

    Livewire::test(ViewActivityLog::class, ['record' => $entry->getKey()])
        ->assertOk()
        ->assertSee('Disk moved')
        ->assertSee('User #'.$causer->id)
        ->assertSee('storage.example.org');
});

test('a panel user without the settings permissions cannot list or open log entries', function () {
    $entry = logActivity(BaseModel::ACTIVITY_LOG_SYSTEM, 'Disk moved');

    $this->actingAs(activityLogPanelUser());

    Livewire::test(ListActivityLogs::class)->assertForbidden();
    Livewire::test(ViewActivityLog::class, ['record' => $entry->getKey()])->assertForbidden();
});

test('the log list offers viewing entries but no way to change or delete them', function () {
    $entry = logActivity(BaseModel::ACTIVITY_LOG_SYSTEM, 'Disk moved');

    $this->actingAs(activityLogPanelUser([PermissionEnums::SETTINGS_EDIT]));

    Livewire::test(ListActivityLogs::class)
        ->assertTableActionVisible('view', $entry)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');
});

test('changing an SSH credential never writes its secrets into the log', function () {
    $credential = SshCredential::query()->create([
        'name' => 'Backup',
        'username' => 'deploy',
        'type' => SshCredential::AUTH_TYPE_KEY,
        'password' => 'plain-password',
        'private_key' => 'private-key-material',
        'passphrase' => 'key-passphrase',
    ]);
    $credential->update(['password' => 'new-password', 'username' => 'deployer']);

    $logged = Activity::query()->where('subject_type', SshCredential::class)->get()
        ->map(fn (Activity $activity): string => json_encode($activity->properties))
        ->implode(' ');

    expect($logged)->toContain('deployer')
        ->not->toContain('password')
        ->not->toContain('private-key-material')
        ->not->toContain('key-passphrase');
});

test('the dashboard widget lists the latest system entries and switches to all entries', function () {
    $systemEntry = logActivity(BaseModel::ACTIVITY_LOG_SYSTEM, 'Backup disk reconfigured');
    $defaultEntry = logActivity('default', 'Structure renamed');

    $this->actingAs(activityLogPanelUser([PermissionEnums::SETTINGS_VIEW]));

    Livewire::test(LatestActivityLogsWidget::class)
        ->assertCanSeeTableRecords([$systemEntry])
        ->assertCanNotSeeTableRecords([$defaultEntry])
        ->callTableAction('allLogs')
        ->assertSet('scope', 'all')
        ->assertCanSeeTableRecords([$systemEntry, $defaultEntry])
        ->callTableAction('systemLogs')
        ->assertSet('scope', BaseModel::ACTIVITY_LOG_SYSTEM)
        ->assertCanNotSeeTableRecords([$defaultEntry])
        ->assertTableActionHasUrl('openLogs', ActivityLogResource::getUrl('index'));
});

test('the dashboard widget shows at most the eight newest entries', function () {
    $entries = collect(range(1, 9))->map(function (int $index): Activity {
        $entry = logActivity(BaseModel::ACTIVITY_LOG_SYSTEM, "Entry {$index}");
        $entry->forceFill(['created_at' => now()->addMinutes($index)])->save();

        return $entry;
    });

    $this->actingAs(activityLogPanelUser([PermissionEnums::SETTINGS_VIEW]));

    Livewire::test(LatestActivityLogsWidget::class)
        ->assertCanSeeTableRecords($entries->slice(1))
        ->assertCanNotSeeTableRecords([$entries->first()]);
});
