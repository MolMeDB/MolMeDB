<?php

use App\Enums\PermissionEnums;
use App\Filament\Clusters\Settings\Resources\Filesystems\FilesystemResource;
use App\Filament\Clusters\Settings\Resources\Filesystems\Pages\CreateFilesystem;
use App\Filament\Clusters\Settings\Resources\Filesystems\Pages\EditFilesystem;
use App\Filament\Clusters\Settings\Resources\Filesystems\Pages\FilesystemActivities;
use App\Filament\Clusters\Settings\Resources\Filesystems\Pages\ListFilesystems;
use App\Models\Filesystem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SshCredential;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * Storage disks point at hosts reached with SSH credentials: settings viewers
 * list them, settings editors configure them, and only SSH credential managers
 * create credentials, also from the disk form.
 */

beforeEach(function () {
    collect(PermissionEnums::cases())->each(fn (PermissionEnums $permission) => Permission::query()->firstOrCreate(
        ['name' => $permission->value, 'guard_name' => 'web'],
        ['description' => $permission->description()],
    ));

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () {
    File::deleteDirectory(storage_path('framework/testing/filesystem-resource-test'));
});

/**
 * @param  array<int, PermissionEnums>  $permissions
 */
function filesystemPanelUser(array $permissions = []): User
{
    $role = Role::query()->create(['name' => 'Filesystem role '.fake()->unique()->numberBetween(1, 1_000_000), 'guard_name' => 'web']);
    $role->givePermissionTo([PermissionEnums::ADMIN_PANEL, ...$permissions]);

    $user = User::factory()->create();
    $user->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

/**
 * The migrations create a disk of every service type; this reconfigures the
 * one of the given type as a remote disk of its own.
 *
 * @param  array<string, mixed>  $attributes
 */
function createConfigurableFilesystem(array $attributes = [], int $type = Filesystem::TYPE_EXPORTS): Filesystem
{
    $filesystem = Filesystem::query()->where('type', $type)->firstOrFail();

    $filesystem->update([
        'name' => 'Exports',
        'scope_id' => null,
        'driver' => Filesystem::DRIVER_SFTP,
        'host' => 'storage.example.org',
        'port' => 22,
        'root_path' => '/exports',
        ...$attributes,
    ]);

    return $filesystem->refresh();
}

test('settings viewers list disks without any way to change them', function () {
    $filesystem = createConfigurableFilesystem();

    $this->actingAs(filesystemPanelUser([PermissionEnums::SETTINGS_VIEW]));

    Livewire::test(ListFilesystems::class)
        ->searchTable('Exports')
        ->assertCanSeeTableRecords([$filesystem])
        ->assertTableActionHidden('edit', $filesystem)
        ->assertTableBulkActionHidden(DeleteBulkAction::class);

    Livewire::test(EditFilesystem::class, ['record' => $filesystem->getKey()])->assertForbidden();
});

test('a panel user without the settings permissions cannot see disks or their history', function () {
    $filesystem = createConfigurableFilesystem();

    $this->actingAs(filesystemPanelUser([PermissionEnums::SSH_CREDENTIALS_MANAGE]));

    Livewire::test(ListFilesystems::class)->assertForbidden();
    Livewire::test(FilesystemActivities::class, ['record' => $filesystem->getKey()])->assertForbidden();
});

test('settings editors change a disk and its root path is stored as an absolute path', function () {
    $filesystem = createConfigurableFilesystem();

    $this->actingAs(filesystemPanelUser([PermissionEnums::SETTINGS_EDIT]));

    Livewire::test(EditFilesystem::class, ['record' => $filesystem->getKey()])
        ->assertFormSet(['name' => 'Exports', 'host' => 'storage.example.org'])
        ->fillForm(['name' => 'Weekly exports', 'host' => 'backup.example.org', 'root_path' => 'data/exports/'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($filesystem->refresh())
        ->name->toBe('Weekly exports')
        ->host->toBe('backup.example.org')
        ->root_path->toBe('/data/exports');
});

test('a remote disk needs a host and port and a name no other disk has', function () {
    createConfigurableFilesystem(['name' => 'Backups'], Filesystem::TYPE_RDF_STORAGE);
    $filesystem = createConfigurableFilesystem();

    $this->actingAs(filesystemPanelUser([PermissionEnums::SETTINGS_EDIT]));

    Livewire::test(EditFilesystem::class, ['record' => $filesystem->getKey()])
        ->fillForm(['name' => 'Backups', 'host' => null, 'port' => null])
        ->call('save')
        ->assertHasFormErrors(['name' => 'unique', 'host' => 'required', 'port' => 'required']);

    expect($filesystem->refresh()->name)->toBe('Exports');
});

test('each service type can be configured only once', function () {
    createConfigurableFilesystem();

    $this->actingAs(filesystemPanelUser([PermissionEnums::SETTINGS_EDIT]));

    Livewire::test(CreateFilesystem::class)
        ->fillForm([
            'type' => Filesystem::TYPE_EXPORTS,
            'name' => 'Second exports',
            'driver' => Filesystem::DRIVER_LOCAL,
            'root_path' => '/second',
        ])
        ->call('create')
        ->assertHasFormErrors(['type']);

    expect(Filesystem::query()->where('name', 'Second exports')->exists())->toBeFalse();
});

test('the built-in public and private disks cannot be reconfigured', function () {
    $public = Filesystem::query()->where('type', Filesystem::TYPE_PUBLIC)->firstOrFail();
    $originalRoot = $public->root_path;

    $this->actingAs(filesystemPanelUser([PermissionEnums::SETTINGS_EDIT]));

    Livewire::test(EditFilesystem::class, ['record' => $public->getKey()])
        ->assertFormFieldIsDisabled('name')
        ->assertFormFieldIsDisabled('root_path')
        ->set('data.name', 'Hijacked')
        ->set('data.root_path', '/etc')
        ->call('save')
        ->assertNotified('The built-in disks cannot be reconfigured');

    expect($public->refresh())
        ->name->not->toBe('Hijacked')
        ->root_path->toBe($originalRoot);
});

test('testing the connection of a working disk reports success', function () {
    $filesystem = createConfigurableFilesystem(['driver' => Filesystem::DRIVER_LOCAL, 'host' => null]);
    $root = storage_path('framework/testing/filesystem-resource-test');
    config()->set('filesystems.disks.'.$filesystem->systemName, ['driver' => 'local', 'root' => $root]);

    $this->actingAs(filesystemPanelUser([PermissionEnums::SETTINGS_EDIT]));

    Livewire::test(EditFilesystem::class, ['record' => $filesystem->getKey()])
        ->callAction('test-connection')
        ->assertNotified('Connection test successful');

    expect(File::files($root))->toBe([]);
});

test('testing the connection of a disk that is not set up reports the failure', function () {
    $filesystem = createConfigurableFilesystem();

    $this->actingAs(filesystemPanelUser([PermissionEnums::SETTINGS_EDIT]));

    Livewire::test(EditFilesystem::class, ['record' => $filesystem->getKey()])
        ->callAction('test-connection')
        ->assertNotified('Connection test failed');
});

test('the edit page links to the disk history, which settings viewers may read', function () {
    $filesystem = createConfigurableFilesystem();
    $filesystem->update(['host' => 'moved.example.org']);

    $this->actingAs(filesystemPanelUser([PermissionEnums::SETTINGS_EDIT]));

    Livewire::test(EditFilesystem::class, ['record' => $filesystem->getKey()])
        ->assertActionHasUrl('history', FilesystemResource::getUrl('activities', ['record' => $filesystem]));

    $this->actingAs(filesystemPanelUser([PermissionEnums::SETTINGS_VIEW]));

    Livewire::test(FilesystemActivities::class, ['record' => $filesystem->getKey()])
        ->assertOk()
        ->assertSee('moved.example.org');
});

test('settings editors delete a disk from its page and in bulk', function () {
    $exports = createConfigurableFilesystem();
    $backups = createConfigurableFilesystem(['name' => 'Backups'], Filesystem::TYPE_RDF_STORAGE);

    $this->actingAs(filesystemPanelUser([PermissionEnums::SETTINGS_EDIT]));

    Livewire::test(EditFilesystem::class, ['record' => $exports->getKey()])
        ->callAction(DeleteAction::class);

    Livewire::test(ListFilesystems::class)
        ->callTableBulkAction(DeleteBulkAction::class, [$backups]);

    expect(Filesystem::query()->whereKey([$exports->id, $backups->id])->exists())->toBeFalse();
});

test('settings editors without the SSH credentials permission cannot create a credential from the disk form', function () {
    $filesystem = createConfigurableFilesystem();

    $this->actingAs(filesystemPanelUser([PermissionEnums::SETTINGS_EDIT]));

    Livewire::test(EditFilesystem::class, ['record' => $filesystem->getKey()])
        ->assertFormComponentActionHidden('ssh_credential_id', 'create');

    expect(SshCredential::query()->count())->toBe(0);
});

test('SSH credential managers create a credential from the disk form and it is selected', function () {
    $filesystem = createConfigurableFilesystem();

    $this->actingAs(filesystemPanelUser([PermissionEnums::SETTINGS_EDIT, PermissionEnums::SSH_CREDENTIALS_MANAGE]));

    Livewire::test(EditFilesystem::class, ['record' => $filesystem->getKey()])
        ->callFormComponentAction('ssh_credential_id', 'create', data: [
            'name' => 'Exports key',
            'type' => SshCredential::AUTH_TYPE_PASSWORD,
            'username' => 'exporter',
            'password' => 'export-secret',
        ])
        ->assertHasNoFormComponentActionErrors()
        ->call('save')
        ->assertHasNoFormErrors();

    $credential = SshCredential::query()->where('name', 'Exports key')->firstOrFail();

    expect($filesystem->refresh()->ssh_credential_id)->toBe($credential->id)
        ->and($credential->password)->toBe('export-secret');
});

test('the built-in public and private disks cannot be deleted', function () {
    $public = Filesystem::query()->where('type', Filesystem::TYPE_PUBLIC)->firstOrFail();

    expect($public->delete())->toBeFalse()
        ->and(Filesystem::query()->whereKey($public->id)->exists())->toBeTrue();
});
