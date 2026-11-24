<?php

use App\Enums\PermissionEnums;
use App\Filament\Resources\NotificationTemplateResource\Pages\CreateNotificationTemplate;
use App\Filament\Resources\NotificationTemplateResource\Pages\EditNotificationTemplate;
use App\Filament\Resources\NotificationTemplateResource\Pages\ListNotificationTemplates;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * Notification templates are sent to every user: settings viewers read them,
 * settings editors write them, one template per application-defined key.
 */

beforeEach(function () {
    collect(PermissionEnums::cases())->each(fn (PermissionEnums $permission) => Permission::query()->firstOrCreate(
        ['name' => $permission->value, 'guard_name' => 'web'],
        ['description' => $permission->description()],
    ));

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    NotificationTemplate::query()->delete();
});

/**
 * @param  array<int, PermissionEnums>  $permissions
 */
function notificationTemplatePanelUser(array $permissions = []): User
{
    $role = Role::query()->create(['name' => 'Template role '.fake()->unique()->numberBetween(1, 1_000_000), 'guard_name' => 'web']);
    $role->givePermissionTo([PermissionEnums::ADMIN_PANEL, ...$permissions]);

    $user = User::factory()->create();
    $user->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createNotificationTemplate(array $attributes = []): NotificationTemplate
{
    return NotificationTemplate::query()->create([
        'key' => NotificationTemplate::KEY_EXPORT_READY,
        'name' => 'Export ready for download',
        'is_active' => true,
        'notification_title' => 'Your export is ready',
        'notification_body' => 'Download it from {{ url }}.',
        ...$attributes,
    ]);
}

test('settings editors create a template and its name follows the chosen key', function () {
    $this->actingAs(notificationTemplatePanelUser([PermissionEnums::SETTINGS_EDIT]));

    Livewire::test(CreateNotificationTemplate::class)
        ->fillForm(['key' => NotificationTemplate::KEY_EXPORT_FAILED])
        ->assertFormSet(['name' => 'Export failed'])
        ->fillForm([
            'notification_title' => 'Export failed',
            'notification_body' => 'The export {{ name }} failed.',
            'email_subject' => 'Export failed',
            'email_message' => '<p>Sorry.</p>',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(NotificationTemplate::query()->where('key', NotificationTemplate::KEY_EXPORT_FAILED)->firstOrFail())
        ->name->toBe('Export failed')
        ->is_active->toBeTrue()
        ->notification_body->toBe('The export {{ name }} failed.');
});

test('a template needs a key defined by the application that no other template uses', function () {
    createNotificationTemplate();

    $this->actingAs(notificationTemplatePanelUser([PermissionEnums::SETTINGS_EDIT]));

    Livewire::test(CreateNotificationTemplate::class)
        ->fillForm(['key' => NotificationTemplate::KEY_EXPORT_READY, 'name' => 'Duplicate', 'notification_title' => 'T', 'notification_body' => 'B'])
        ->call('create')
        ->assertHasFormErrors(['key']);

    Livewire::test(CreateNotificationTemplate::class)
        ->fillForm(['key' => 'made.up.key', 'name' => 'Made up', 'notification_title' => 'T', 'notification_body' => 'B'])
        ->call('create')
        ->assertHasFormErrors(['key']);

    Livewire::test(CreateNotificationTemplate::class)
        ->fillForm(['key' => NotificationTemplate::KEY_EXPORT_FAILED, 'notification_title' => '', 'notification_body' => ''])
        ->call('create')
        ->assertHasFormErrors(['notification_title' => 'required', 'notification_body' => 'required']);

    expect(NotificationTemplate::query()->count())->toBe(1);
});

test('the key options leave out keys other templates already use', function () {
    $template = createNotificationTemplate();

    expect(NotificationTemplate::availableKeyOptions())->not->toHaveKey(NotificationTemplate::KEY_EXPORT_READY)
        ->and(NotificationTemplate::availableKeyOptions($template))->toHaveKey(NotificationTemplate::KEY_EXPORT_READY);
});

test('settings editors change a template but not its key', function () {
    $template = createNotificationTemplate();

    $this->actingAs(notificationTemplatePanelUser([PermissionEnums::SETTINGS_EDIT]));

    Livewire::test(EditNotificationTemplate::class, ['record' => $template->getKey()])
        ->assertFormSet(['key' => NotificationTemplate::KEY_EXPORT_READY, 'name' => 'Export ready for download'])
        ->assertFormFieldIsDisabled('key')
        ->fillForm(['notification_title' => 'Export finished', 'is_active' => false])
        ->set('data.key', NotificationTemplate::KEY_EXPORT_FAILED)
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($template->refresh())
        ->key->toBe(NotificationTemplate::KEY_EXPORT_READY)
        ->notification_title->toBe('Export finished')
        ->is_active->toBeFalse();
});

test('settings editors delete a template', function () {
    $template = createNotificationTemplate();

    $this->actingAs(notificationTemplatePanelUser([PermissionEnums::SETTINGS_EDIT]));

    Livewire::test(EditNotificationTemplate::class, ['record' => $template->getKey()])
        ->callAction(DeleteAction::class);

    expect(NotificationTemplate::query()->whereKey($template->id)->exists())->toBeFalse();
});

test('settings viewers list templates and filter them but cannot change them', function () {
    $active = createNotificationTemplate();
    $inactive = createNotificationTemplate(['key' => NotificationTemplate::KEY_EXPORT_FAILED, 'name' => 'Export failed', 'is_active' => false]);

    $this->actingAs(notificationTemplatePanelUser([PermissionEnums::SETTINGS_VIEW]));

    Livewire::test(ListNotificationTemplates::class)
        ->assertCanSeeTableRecords([$active, $inactive])
        ->filterTable('is_active', false)
        ->assertCanSeeTableRecords([$inactive])
        ->assertCanNotSeeTableRecords([$active])
        ->assertActionHidden('create')
        ->assertTableActionHidden('edit', $inactive);

    Livewire::test(EditNotificationTemplate::class, ['record' => $active->getKey()])->assertForbidden();
});

test('a panel user without the settings permissions cannot list templates', function () {
    $this->actingAs(notificationTemplatePanelUser([PermissionEnums::CONFIG_EDIT]));

    Livewire::test(ListNotificationTemplates::class)->assertForbidden();
});
