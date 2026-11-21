<?php

use App\Enums\PermissionEnums;
use App\Enums\RoleEnums;
use App\Filament\Clusters\Settings\Resources\Filesystems\FilesystemResource;
use App\Filament\Clusters\Settings\Resources\Filesystems\Pages\CreateFilesystem;
use App\Filament\Clusters\Settings\Resources\Filesystems\Pages\ListFilesystems;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\DocumentArticles\Pages\ListDocumentArticles;
use App\Filament\Resources\FeedbackSubmissionResource\Pages\ListFeedbackSubmissions;
use App\Filament\Resources\NotificationTemplateResource\Pages\CreateNotificationTemplate;
use App\Filament\Resources\NotificationTemplateResource\Pages\ListNotificationTemplates;
use App\Filament\Resources\PredictionMethods\Pages\ListPredictionMethods;
use App\Filament\Resources\Predictions\Pages\ListPredictions;
use App\Filament\Resources\PredictionStatsResource\Pages\ListPredictionStats;
use App\Filament\Resources\PredictionStructures\Pages\ListPredictionStructures;
use App\Filament\Widgets\LatestActivityLogsWidget;
use App\Filament\Widgets\PredictionLiveStatsWidget;
use App\Models\FeedbackSubmission;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * Filament allows every ability that a model's policy does not define, and
 * every resource whose model has no policy at all. These tests walk all
 * resources registered in the admin panel, so a resource added later without
 * a complete policy fails here.
 */

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    collect(PermissionEnums::cases())->each(function (PermissionEnums $permissionEnum): void {
        Permission::query()->firstOrCreate([
            'name' => $permissionEnum->value,
            'guard_name' => 'web',
        ], [
            'description' => $permissionEnum->description(),
        ]);
    });

    collect(RoleEnums::cases())->each(function (RoleEnums $roleEnum): void {
        Role::query()->firstOrCreate([
            'name' => $roleEnum->value,
            'guard_name' => 'web',
        ]);
    });

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * A user of a custom role that may enter the admin panel and has the given
 * permissions on top of it.
 *
 * @param  array<int, PermissionEnums>  $permissions
 */
function createPanelUser(array $permissions = []): User
{
    $role = Role::query()->create([
        'name' => 'Panel role '.fake()->unique()->numberBetween(1, 1_000_000),
        'guard_name' => 'web',
    ]);

    $role->givePermissionTo([
        PermissionEnums::ADMIN_PANEL->value,
        ...array_map(fn (PermissionEnums $permission): string => $permission->value, $permissions),
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$role]);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

/**
 * @return array<int, class-string<resource>>
 */
function adminPanelResources(): array
{
    return Filament::getPanel('admin')->getResources();
}

test('every admin panel resource model has a policy defining the abilities Filament checks', function () {
    $abilities = ['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny'];
    $gaps = [];

    foreach (adminPanelResources() as $resource) {
        $model = $resource::getModel();
        $policy = Gate::getPolicyFor($model);

        if ($policy === null) {
            $gaps[] = "{$resource}: no policy for {$model}";

            continue;
        }

        foreach ($abilities as $ability) {
            if (! method_exists($policy, $ability)) {
                $gaps[] = $resource.': '.$policy::class."::{$ability}() is missing";
            }
        }
    }

    expect($gaps)->toBe([]);
});

test('a panel user without any other permission cannot open or create in any resource', function () {
    $this->actingAs(createPanelUser());

    $allowed = [];

    foreach (adminPanelResources() as $resource) {
        if ($resource::canAccess()) {
            $allowed[] = "{$resource}: list";
        }

        if ($resource::canCreate()) {
            $allowed[] = "{$resource}: create";
        }
    }

    expect($allowed)->toBe([]);
});

test('a panel user without any other permission gets forbidden on every resource list page', function () {
    $this->actingAs(createPanelUser());

    foreach (adminPanelResources() as $resource) {
        $pages = $resource::getPages();

        if (! isset($pages['index'])) {
            continue;
        }

        Livewire::test($pages['index']->getPage())->assertForbidden();
    }
});

test('a guest opening an admin page is sent to the login', function () {
    $this->get(FilesystemResource::getUrl('index'))
        ->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
});

test('a signed-in user without the admin panel permission gets forbidden on admin pages', function () {
    $this->actingAs(User::factory()->create());

    $this->get(FilesystemResource::getUrl('index'))->assertForbidden();
});

test('settings viewers can list the settings and system resources but not create in them', function (string $listPage) {
    $this->actingAs(createPanelUser([PermissionEnums::SETTINGS_VIEW]));

    Livewire::test($listPage)->assertOk();
})->with([
    'filesystems' => ListFilesystems::class,
    'notification templates' => ListNotificationTemplates::class,
    'activity logs' => ListActivityLogs::class,
    'feedback submissions' => ListFeedbackSubmissions::class,
    'documentation articles' => ListDocumentArticles::class,
]);

test('settings viewers cannot create filesystems or notification templates', function (string $createPage) {
    $this->actingAs(createPanelUser([PermissionEnums::SETTINGS_VIEW]));

    Livewire::test($createPage)->assertForbidden();
})->with([
    'filesystems' => CreateFilesystem::class,
    'notification templates' => CreateNotificationTemplate::class,
]);

test('settings editors can create filesystems and notification templates', function (string $createPage) {
    $this->actingAs(createPanelUser([PermissionEnums::SETTINGS_EDIT]));

    Livewire::test($createPage)->assertOk();
})->with([
    'filesystems' => CreateFilesystem::class,
    'notification templates' => CreateNotificationTemplate::class,
]);

test('nobody can change or delete activity log entries', function () {
    $this->actingAs(createPanelUser([PermissionEnums::SETTINGS_VIEW, PermissionEnums::SETTINGS_EDIT]));

    $activity = activity()->log('Something happened');

    expect(Gate::allows('view', $activity))->toBeTrue()
        ->and(Gate::allows('update', $activity))->toBeFalse()
        ->and(Gate::allows('delete', $activity))->toBeFalse()
        ->and(Gate::allows('create', $activity::class))->toBeFalse();
});

test('prediction managers can list every prediction resource', function (string $listPage) {
    $this->actingAs(createPanelUser([PermissionEnums::PREDICTION_DATASET_MANAGE_ALL]));

    Livewire::test($listPage)->assertOk();
})->with([
    'predictions' => ListPredictions::class,
    'prediction methods' => ListPredictionMethods::class,
    'prediction structures' => ListPredictionStructures::class,
    'prediction stats' => ListPredictionStats::class,
]);

test('settings permissions do not open the prediction resources', function (string $listPage) {
    $this->actingAs(createPanelUser([PermissionEnums::SETTINGS_EDIT]));

    Livewire::test($listPage)->assertForbidden();
})->with([
    'predictions' => ListPredictions::class,
    'prediction methods' => ListPredictionMethods::class,
    'prediction structures' => ListPredictionStructures::class,
    'prediction stats' => ListPredictionStats::class,
]);

test('the permission migration creates missing settings and prediction permissions for administrators', function () {
    Permission::query()->where('name', PermissionEnums::SETTINGS_VIEW->value)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $admin = User::factory()->create();
    $admin->syncRoles([RoleEnums::ADMIN->value]);

    $migration = require database_path('migrations/2026_11_21_061026_ensure_settings_and_prediction_permissions_exist.php');
    $migration->up();

    expect($admin->refresh()->hasPermissionTo(PermissionEnums::SETTINGS_VIEW))->toBeTrue()
        ->and($admin->hasPermissionTo(PermissionEnums::SETTINGS_EDIT))->toBeTrue()
        ->and($admin->hasPermissionTo(PermissionEnums::PREDICTION_DATASET_MANAGE_ALL))->toBeTrue();
});

test('dashboard widgets follow the policies of the records they show', function () {
    $this->actingAs(createPanelUser());

    expect(LatestActivityLogsWidget::canView())->toBeFalse()
        ->and(PredictionLiveStatsWidget::canView())->toBeFalse();

    $this->actingAs(createPanelUser([PermissionEnums::SETTINGS_VIEW]));
    expect(LatestActivityLogsWidget::canView())->toBeTrue();

    $this->actingAs(createPanelUser([PermissionEnums::PREDICTION_DATASET_MANAGE_ALL]));
    expect(PredictionLiveStatsWidget::canView())->toBeTrue();
});

test('marking feedback as read needs the settings edit permission', function () {
    $feedback = FeedbackSubmission::query()->create([
        'email' => 'visitor@example.org',
        'message' => 'The search is slow.',
        'context' => 'search',
        'state' => FeedbackSubmission::STATE_NEW,
    ]);

    $this->actingAs(createPanelUser([PermissionEnums::SETTINGS_VIEW]));

    Livewire::test(ListFeedbackSubmissions::class)->assertTableActionHidden('markAsRead', $feedback);

    $this->actingAs(createPanelUser([PermissionEnums::SETTINGS_EDIT]));

    Livewire::test(ListFeedbackSubmissions::class)->callTableAction('markAsRead', $feedback);

    expect($feedback->refresh()->state)->toBe(FeedbackSubmission::STATE_READ);
});
