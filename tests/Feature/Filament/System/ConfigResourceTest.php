<?php

use App\Enums\PermissionEnums;
use App\Filament\Clusters\Settings\Resources\Configs\ConfigResource;
use App\Filament\Clusters\Settings\Resources\Configs\Pages\CreateConfig;
use App\Filament\Clusters\Settings\Resources\Configs\Pages\EditConfig;
use App\Filament\Clusters\Settings\Resources\Configs\Pages\ListConfigs;
use App\Models\Config;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * Application configs hold the remote prediction service token: config
 * viewers see every other value, editors change them.
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
function configPanelUser(array $permissions = []): User
{
    $role = Role::query()->create(['name' => 'Config role '.fake()->unique()->numberBetween(1, 1_000_000), 'guard_name' => 'web']);
    $role->givePermissionTo([PermissionEnums::ADMIN_PANEL, ...$permissions]);

    $user = User::factory()->create();
    $user->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

test('config viewers see values but the remote prediction token stays masked', function () {
    Config::set(Config::KEY_FEEDBACK_EMAIL_FALLBACK, 'feedback@example.org');
    Config::set(Config::KEY_REMOTE_PREDICTION_TOKEN, 'tok_secret_value_123');

    $this->actingAs(configPanelUser([PermissionEnums::CONFIG_VIEW]));

    Livewire::test(ListConfigs::class)
        ->assertOk()
        ->assertSee('feedback@example.org')
        ->assertSee(Config::KEY_REMOTE_PREDICTION_TOKEN)
        ->assertDontSee('tok_secret_value_123');
});

test('searching the configs cannot reveal the remote prediction token', function () {
    Config::set(Config::KEY_FEEDBACK_EMAIL_FALLBACK, 'feedback@example.org');
    Config::set(Config::KEY_REMOTE_PREDICTION_TOKEN, 'tok_secret_value_123');
    $token = Config::query()->findOrFail(Config::KEY_REMOTE_PREDICTION_TOKEN);
    $fallback = Config::query()->findOrFail(Config::KEY_FEEDBACK_EMAIL_FALLBACK);

    $this->actingAs(configPanelUser([PermissionEnums::CONFIG_VIEW]));

    Livewire::test(ListConfigs::class)
        ->searchTable('tok_secret')
        ->assertCanNotSeeTableRecords([$token])
        ->searchTable('feedback@')
        ->assertCanSeeTableRecords([$fallback])
        ->searchTable('remote_prediction:token')
        ->assertCanSeeTableRecords([$token]);
});

test('config viewers cannot create, edit or delete configs', function () {
    Config::set(Config::KEY_FEEDBACK_EMAIL_FALLBACK, 'feedback@example.org');
    $config = Config::query()->findOrFail(Config::KEY_FEEDBACK_EMAIL_FALLBACK);

    $this->actingAs(configPanelUser([PermissionEnums::CONFIG_VIEW]));

    Livewire::test(ListConfigs::class)
        ->assertActionHidden('create')
        ->assertTableActionHidden('edit', $config)
        ->assertTableBulkActionHidden(DeleteBulkAction::class);

    Livewire::test(CreateConfig::class)->assertForbidden();
    Livewire::test(EditConfig::class, ['record' => $config->getKey()])->assertForbidden();
});

test('a panel user without a config permission cannot list configs', function () {
    $this->actingAs(configPanelUser([PermissionEnums::SETTINGS_EDIT]));

    Livewire::test(ListConfigs::class)->assertForbidden();

    expect(ConfigResource::canAccess())->toBeFalse();
});

test('config editors create a config', function () {
    $this->actingAs(configPanelUser([PermissionEnums::CONFIG_EDIT]));

    Livewire::test(CreateConfig::class)
        ->fillForm(['key' => Config::KEY_PREDICTION_ADMIN_EMAIL_FALLBACK, 'value' => 'predictions@example.org'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Config::get(Config::KEY_PREDICTION_ADMIN_EMAIL_FALLBACK))->toBe('predictions@example.org');
});

test('a config key can be used only once and is required', function () {
    Config::set(Config::KEY_FEEDBACK_EMAIL_FALLBACK, 'feedback@example.org');

    $this->actingAs(configPanelUser([PermissionEnums::CONFIG_EDIT]));

    Livewire::test(CreateConfig::class)
        ->fillForm(['key' => Config::KEY_FEEDBACK_EMAIL_FALLBACK, 'value' => 'other@example.org'])
        ->call('create')
        ->assertHasFormErrors(['key' => 'unique']);

    Livewire::test(CreateConfig::class)
        ->fillForm(['key' => '', 'value' => 'x'])
        ->call('create')
        ->assertHasFormErrors(['key' => 'required']);

    expect(Config::get(Config::KEY_FEEDBACK_EMAIL_FALLBACK))->toBe('feedback@example.org')
        ->and(Config::query()->whereKey('custom:renamed')->exists())->toBeFalse();
});

test('the remote prediction URL must be an https URL', function (string $url, bool $accepted) {
    Config::query()->whereKey(Config::KEY_REMOTE_PREDICTION_URL)->delete();
    $this->actingAs(configPanelUser([PermissionEnums::CONFIG_EDIT]));

    $component = Livewire::test(CreateConfig::class)
        ->fillForm(['key' => Config::KEY_REMOTE_PREDICTION_URL, 'value' => $url])
        ->call('create');

    if ($accepted) {
        $component->assertHasNoFormErrors();
        expect(Config::get(Config::KEY_REMOTE_PREDICTION_URL))->toBe($url);
    } else {
        $component->assertHasFormErrors(['value']);
        expect(Config::get(Config::KEY_REMOTE_PREDICTION_URL))->toBeNull();
    }
})->with([
    'https' => ['https://predictions.example.org/api', true],
    'plain http' => ['http://predictions.example.org/api', false],
    'not a URL' => ['predictions', false],
]);

test('config editors change a value but not the key', function () {
    Config::set(Config::KEY_FEEDBACK_EMAIL_FALLBACK, 'feedback@example.org');
    $config = Config::query()->findOrFail(Config::KEY_FEEDBACK_EMAIL_FALLBACK);

    $this->actingAs(configPanelUser([PermissionEnums::CONFIG_EDIT]));

    Livewire::test(EditConfig::class, ['record' => $config->getKey()])
        ->assertFormSet(['key' => Config::KEY_FEEDBACK_EMAIL_FALLBACK, 'value' => 'feedback@example.org'])
        ->assertFormFieldIsDisabled('key')
        ->fillForm(['value' => 'new-feedback@example.org'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Config::get(Config::KEY_FEEDBACK_EMAIL_FALLBACK))->toBe('new-feedback@example.org');
});

test('a key sent for the disabled key field on the edit page is ignored', function () {
    Config::set(Config::KEY_FEEDBACK_EMAIL_FALLBACK, 'feedback@example.org');

    $this->actingAs(configPanelUser([PermissionEnums::CONFIG_EDIT]));

    Livewire::test(EditConfig::class, ['record' => Config::KEY_FEEDBACK_EMAIL_FALLBACK])
        ->set('data.key', 'custom:renamed')
        ->call('save');

    expect(Config::get(Config::KEY_FEEDBACK_EMAIL_FALLBACK))->toBe('feedback@example.org')
        ->and(Config::query()->whereKey('custom:renamed')->exists())->toBeFalse();
});

test('config editors delete a config from its page and in bulk', function () {
    Config::set(Config::KEY_FEEDBACK_EMAIL_FALLBACK, 'feedback@example.org');
    Config::set(Config::KEY_PREDICTION_ADMIN_EMAIL_FALLBACK, 'predictions@example.org');
    Config::set(Config::KEY_LAB_UPLOAD_ADMIN_EMAIL_FALLBACK, 'uploads@example.org');

    $this->actingAs(configPanelUser([PermissionEnums::CONFIG_EDIT]));

    Livewire::test(EditConfig::class, ['record' => Config::KEY_FEEDBACK_EMAIL_FALLBACK])
        ->callAction(DeleteAction::class);

    Livewire::test(ListConfigs::class)
        ->callTableBulkAction(DeleteBulkAction::class, [Config::KEY_PREDICTION_ADMIN_EMAIL_FALLBACK]);

    expect(Config::query()->whereKey([Config::KEY_FEEDBACK_EMAIL_FALLBACK, Config::KEY_PREDICTION_ADMIN_EMAIL_FALLBACK])->exists())->toBeFalse()
        ->and(Config::get(Config::KEY_LAB_UPLOAD_ADMIN_EMAIL_FALLBACK))->toBe('uploads@example.org');
});

test('the remote prediction URL stays https when it is edited', function () {
    Config::set(Config::KEY_REMOTE_PREDICTION_URL, 'https://predictions.example.org/api');

    $this->actingAs(configPanelUser([PermissionEnums::CONFIG_EDIT]));

    Livewire::test(EditConfig::class, ['record' => Config::KEY_REMOTE_PREDICTION_URL])
        ->fillForm(['value' => 'http://attacker.example.org'])
        ->call('save')
        ->assertHasFormErrors(['value']);

    expect(Config::get(Config::KEY_REMOTE_PREDICTION_URL))->toBe('https://predictions.example.org/api');
});

test('the https rule of the remote prediction URL cannot be avoided by a crafted key', function () {
    Config::set(Config::KEY_REMOTE_PREDICTION_URL, 'https://predictions.example.org/api');

    $this->actingAs(configPanelUser([PermissionEnums::CONFIG_EDIT]));

    Livewire::test(EditConfig::class, ['record' => Config::KEY_REMOTE_PREDICTION_URL])
        ->set('data.key', 'something_else')
        ->set('data.value', 'http://attacker.example.org')
        ->call('save')
        ->assertHasFormErrors(['value']);

    expect(Config::get(Config::KEY_REMOTE_PREDICTION_URL))->toBe('https://predictions.example.org/api');
});

test('the edit page never sends the remote prediction token to the browser', function () {
    Config::set(Config::KEY_REMOTE_PREDICTION_TOKEN, 'tok_secret_value_123');

    $this->actingAs(configPanelUser([PermissionEnums::CONFIG_EDIT]));

    $page = Livewire::test(EditConfig::class, ['record' => Config::KEY_REMOTE_PREDICTION_TOKEN])
        ->assertOk()
        ->assertFormSet(['key' => Config::KEY_REMOTE_PREDICTION_TOKEN, 'value' => null])
        ->assertDontSee('tok_secret_value_123');

    expect(json_encode($page->snapshot))->not->toContain('tok_secret_value_123');
});

test('saving the remote prediction token with an empty field keeps the stored one', function () {
    Config::set(Config::KEY_REMOTE_PREDICTION_TOKEN, 'tok_secret_value_123');

    $this->actingAs(configPanelUser([PermissionEnums::CONFIG_EDIT]));

    Livewire::test(EditConfig::class, ['record' => Config::KEY_REMOTE_PREDICTION_TOKEN])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Config::get(Config::KEY_REMOTE_PREDICTION_TOKEN))->toBe('tok_secret_value_123');
});

test('a new remote prediction token replaces the stored one and is not shown back', function () {
    Config::set(Config::KEY_REMOTE_PREDICTION_TOKEN, 'tok_secret_value_123');

    $this->actingAs(configPanelUser([PermissionEnums::CONFIG_EDIT]));

    $page = Livewire::test(EditConfig::class, ['record' => Config::KEY_REMOTE_PREDICTION_TOKEN])
        ->fillForm(['value' => 'tok_replacement_456'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Config::get(Config::KEY_REMOTE_PREDICTION_TOKEN))->toBe('tok_replacement_456');

    Livewire::test(EditConfig::class, ['record' => Config::KEY_REMOTE_PREDICTION_TOKEN])
        ->assertFormSet(['value' => null])
        ->assertDontSee('tok_replacement_456');
});
