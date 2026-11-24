<?php

use App\Enums\PermissionEnums;
use App\Filament\Resources\FeedbackSubmissionResource;
use App\Filament\Resources\FeedbackSubmissionResource\Pages\ListFeedbackSubmissions;
use App\Filament\Resources\FeedbackSubmissionResource\Pages\ViewFeedbackSubmission;
use App\Models\FeedbackSubmission;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * Feedback carries visitors' e-mail and IP addresses: only holders of the
 * settings permissions read it, and only settings editors change its state.
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
function feedbackPanelUser(array $permissions = []): User
{
    $role = Role::query()->create(['name' => 'Feedback role '.fake()->unique()->numberBetween(1, 1_000_000), 'guard_name' => 'web']);
    $role->givePermissionTo([PermissionEnums::ADMIN_PANEL, ...$permissions]);

    $user = User::factory()->create();
    $user->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createFeedbackSubmission(array $attributes = []): FeedbackSubmission
{
    return FeedbackSubmission::query()->create([
        'email' => 'visitor@example.org',
        'message' => 'The search is slow.',
        'context' => 'search',
        'state' => FeedbackSubmission::STATE_NEW,
        'ip_address' => '203.0.113.7',
        'user_agent' => 'Mozilla/5.0 Test',
        ...$attributes,
    ]);
}

test('settings viewers list feedback, search it by e-mail and filter it by state and sender', function () {
    $signedInSender = User::factory()->create(['name' => 'Known Sender']);
    $guestFeedback = createFeedbackSubmission(['email' => 'guest@example.org']);
    $userFeedback = createFeedbackSubmission(['email' => 'member@example.org', 'user_id' => $signedInSender->id, 'state' => FeedbackSubmission::STATE_READ]);

    $this->actingAs(feedbackPanelUser([PermissionEnums::SETTINGS_VIEW]));

    Livewire::test(ListFeedbackSubmissions::class)
        ->assertCanSeeTableRecords([$guestFeedback, $userFeedback])
        ->searchTable('guest@')
        ->assertCanSeeTableRecords([$guestFeedback])
        ->assertCanNotSeeTableRecords([$userFeedback])
        ->searchTable(null)
        ->filterTable('state', FeedbackSubmission::STATE_READ)
        ->assertCanSeeTableRecords([$userFeedback])
        ->assertCanNotSeeTableRecords([$guestFeedback])
        ->removeTableFilter('state')
        ->filterTable('user_id', false)
        ->assertCanSeeTableRecords([$guestFeedback])
        ->assertCanNotSeeTableRecords([$userFeedback])
        ->filterTable('user_id', true)
        ->assertCanSeeTableRecords([$userFeedback])
        ->assertCanNotSeeTableRecords([$guestFeedback]);
});

test('settings viewers open feedback with the sender e-mail, IP address and user agent', function () {
    $feedback = createFeedbackSubmission();

    $this->actingAs(feedbackPanelUser([PermissionEnums::SETTINGS_VIEW]));

    Livewire::test(ViewFeedbackSubmission::class, ['record' => $feedback->getKey()])
        ->assertOk()
        ->assertSee('visitor@example.org')
        ->assertSee('203.0.113.7')
        ->assertSee('Mozilla/5.0 Test')
        ->assertSee('Guest');
});

test('a panel user without the settings permissions can neither list nor open feedback', function () {
    $feedback = createFeedbackSubmission();

    $this->actingAs(feedbackPanelUser([PermissionEnums::USERS_VIEW]));

    Livewire::test(ListFeedbackSubmissions::class)->assertForbidden();
    Livewire::test(ViewFeedbackSubmission::class, ['record' => $feedback->getKey()])->assertForbidden();

    expect(FeedbackSubmissionResource::canAccess())->toBeFalse()
        ->and($feedback->refresh()->state)->toBe(FeedbackSubmission::STATE_NEW);
});

test('opening feedback marks it as read for settings editors', function () {
    $feedback = createFeedbackSubmission();

    $this->actingAs(feedbackPanelUser([PermissionEnums::SETTINGS_EDIT]));

    Livewire::test(ViewFeedbackSubmission::class, ['record' => $feedback->getKey()])->assertOk();

    expect($feedback->refresh()->state)->toBe(FeedbackSubmission::STATE_READ)
        ->and($feedback->read_at)->not->toBeNull();
});

test('opening feedback does not change its state for those who may only read it', function () {
    $feedback = createFeedbackSubmission();

    $this->actingAs(feedbackPanelUser([PermissionEnums::SETTINGS_VIEW]));

    Livewire::test(ViewFeedbackSubmission::class, ['record' => $feedback->getKey()])->assertOk();

    expect($feedback->refresh()->state)->toBe(FeedbackSubmission::STATE_NEW)
        ->and($feedback->read_at)->toBeNull();
});

test('the mark-as-read action disappears once feedback is read', function () {
    $feedback = createFeedbackSubmission(['state' => FeedbackSubmission::STATE_READ]);

    $this->actingAs(feedbackPanelUser([PermissionEnums::SETTINGS_EDIT]));

    Livewire::test(ListFeedbackSubmissions::class)
        ->assertTableActionHidden('markAsRead', $feedback)
        ->assertTableActionVisible('view', $feedback);
});

test('the navigation badge counts unread feedback', function () {
    expect(FeedbackSubmissionResource::getNavigationBadge())->toBeNull();

    createFeedbackSubmission();
    createFeedbackSubmission();
    createFeedbackSubmission(['state' => FeedbackSubmission::STATE_READ]);

    expect(FeedbackSubmissionResource::getNavigationBadge())->toBe('2')
        ->and(FeedbackSubmissionResource::getNavigationBadgeColor())->toBe('danger');
});
