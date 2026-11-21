<?php

use App\Enums\PermissionEnums;
use App\Models\Permission;
use App\Models\User;
use Filament\Facades\Filament;

/*
 * The panel's e-mail verification prompt is for unverified users; a verified
 * user who opens it is sent on into the panel.
 */

function panelUser(bool $verified): User
{
    Permission::query()->firstOrCreate(
        ['name' => PermissionEnums::ADMIN_PANEL->value, 'guard_name' => 'web'],
        ['description' => PermissionEnums::ADMIN_PANEL->description()],
    );
    $factory = $verified ? User::factory() : User::factory()->unverified();
    $user = $factory->create();
    $user->givePermissionTo(PermissionEnums::ADMIN_PANEL->value);

    return $user;
}

test('a verified user opening the verification prompt is sent into the panel', function () {
    $this->actingAs(panelUser(verified: true))
        ->get(route('filament.admin.auth.email-verification.prompt'))
        ->assertRedirect(Filament::getPanel('admin')->getUrl());
});

test('an unverified user sees the verification prompt', function () {
    $this->actingAs(panelUser(verified: false))
        ->get(route('filament.admin.auth.email-verification.prompt'))
        ->assertOk();
});
