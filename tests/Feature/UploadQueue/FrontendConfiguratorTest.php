<?php

require_once __DIR__.'/upload_pipeline_helpers.php';

use App\Enums\PermissionEnums;
use App\Models\Permission;
use App\Models\UploadQueue;
use App\Models\User;
use App\Services\UploadQueueFrontendConfigurator;
use App\ValueObjects\MethodParameters;
use App\ValueObjects\UploadQueueConfig;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    fakePublicStorageForTests();
    // A root of this test process only: the shared fake root is emptied
    // whenever another test process fakes the public disk.
    config()->set('filesystems.disks.public.root', storage_path('framework/testing/disks/public_'.getmypid()));
    Storage::forgetDisk('public');
    fakeUploadChemistryServices(invalidSmiles: ['not-a-smiles']);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory(config('filesystems.disks.public.root'));
});

function frontendConfigurator(): UploadQueueFrontendConfigurator
{
    return app(UploadQueueFrontendConfigurator::class);
}

/**
 * An uploaded, not yet configured passive upload of the given CSV.
 *
 * @param  array<int, string|null>  $columns
 * @param  array<string, mixed>  $attributes
 */
function createUploadForFrontendConfiguration(
    string $csv,
    array $columns = [],
    int $type = UploadQueue::TYPE_PASSIVE_DATASET,
    array $attributes = [],
): UploadQueue {
    return createConfiguredUpload(
        $csv,
        $columns,
        $type,
        [UploadQueueConfig::QUICK_VALIDATION_OK => false],
        ['state' => UploadQueue::STATE_UPLOADED, ...$attributes],
    );
}

test('the preview pages through the file from the requested line and counts every line', function () {
    $csv = "SMILES,Gpen\n".implode('', array_map(fn (int $i): string => "C{$i},-{$i}\n", range(1, 8)));
    $record = createUploadForFrontendConfiguration($csv);

    $preview = frontendConfigurator()->preview($record, ',', skipFirstRow: 1, startLine: 4, limit: 3);

    expect($preview['ok'])->toBeTrue()
        ->and($preview['errors'])->toBe([])
        ->and($preview['preview_rows'])->toBe([['C3', '-3'], ['C4', '-4'], ['C5', '-5']])
        ->and($preview['total_rows'])->toBe(9)
        ->and($preview['start_line'])->toBe(4);
});

test('the preview includes the header row when it is not skipped', function () {
    $record = createUploadForFrontendConfiguration("SMILES,Gpen\nCCO,-1\n");

    $preview = frontendConfigurator()->preview($record, ',', skipFirstRow: 0);

    expect($preview['preview_rows'])->toBe([['SMILES', 'Gpen'], ['CCO', '-1']]);
});

test('the preview clamps its start line and row limit', function (int $startLine, int $limit, int $expectedStartLine, int $expectedRows) {
    $csv = "SMILES,Gpen\n".implode('', array_map(fn (int $i): string => "C{$i},-{$i}\n", range(1, 15)));
    $record = createUploadForFrontendConfiguration($csv);

    $preview = frontendConfigurator()->preview($record, ',', 1, $startLine, $limit);

    expect($preview['start_line'])->toBe($expectedStartLine)
        ->and($preview['preview_rows'])->toHaveCount($expectedRows)
        ->and($preview['preview_rows'][0])->toBe(['C1', '-1']);
})->with([
    'start line below one' => [-5, 5, 1, 5],
    'limit above ten' => [1, 50, 1, 10],
    'limit below one' => [1, 0, 1, 1],
]);

test('the preview reads tab separated files', function (string $separator) {
    $record = createUploadForFrontendConfiguration("SMILES\tGpen\nCCO\t-1\n");

    $preview = frontendConfigurator()->preview($record, $separator);

    expect($preview['preview_rows'])->toBe([['CCO', '-1']]);
})->with(['escaped tab' => '\t', 'tab keyword' => 'tab', 'tab character' => "\t"]);

test('the preview keeps a saved mapping that fits the file and offers each type to one column only', function () {
    $record = createUploadForFrontendConfiguration("SMILES,Gpen,Note\nCCO,-1,x\n", [' smiles ', 'g_pen', '']);

    $preview = frontendConfigurator()->preview($record, ',');

    expect($preview['column_mapping'])->toBe(['smiles', 'g_pen', 'ignore'])
        ->and($preview['column_type_options'])->toHaveKeys(['ignore', 'smiles', 'g_pen', 'temperature'])
        ->and($preview['column_type_options'])->not->toHaveKey('active_target')
        ->and($preview['column_valid_types'][0])->toHaveKeys(['ignore', 'smiles'])
        ->and($preview['column_valid_types'][0])->not->toHaveKey('g_pen')
        ->and($preview['column_valid_types'][2])->toHaveKeys(['ignore', 'comment'])
        ->and($preview['column_valid_types'][2])->not->toHaveKeys(['smiles', 'g_pen']);
});

test('the preview ignores a saved mapping that does not fit the file', function () {
    $record = createUploadForFrontendConfiguration("SMILES,Gpen,Note\nCCO,-1,x\n", ['smiles', 'g_pen']);

    $preview = frontendConfigurator()->preview($record, ',');

    expect($preview['column_mapping'])->toBe(['ignore', 'ignore', 'ignore']);
});

test('the preview of an unreadable file fails but still offers the column types', function (Closure $breakUpload) {
    $record = createUploadForFrontendConfiguration("SMILES,Gpen\nCCO,-1\n");
    $breakUpload($record);

    $preview = frontendConfigurator()->preview($record->refresh(), ',', startLine: 3);

    expect($preview['ok'])->toBeFalse()
        ->and($preview['errors'])->toBe(['Cannot read uploaded file.'])
        ->and($preview['preview_rows'])->toBe([])
        ->and($preview['total_rows'])->toBe(0)
        ->and($preview['start_line'])->toBe(3)
        ->and($preview['column_type_options'])->toHaveKey('smiles');
})->with([
    'file without a disk' => [fn (UploadQueue $record) => $record->file->update(['storage' => ' '])],
    'file without a path' => [fn (UploadQueue $record) => $record->file->update(['path' => ''])],
    'file deleted from the disk' => [fn (UploadQueue $record) => Storage::disk('public')->delete($record->file->path)],
]);

test('a disk that cannot be opened is logged and reported as an unreadable file', function () {
    $record = createUploadForFrontendConfiguration("SMILES,Gpen\nCCO,-1\n");
    $record->file->update(['storage' => 'missing-disk']);

    Log::shouldReceive('channel')->with('upload')->andReturnSelf();
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['record_id'] === $record->id
            && $context['disk'] === 'missing-disk');

    $result = frontendConfigurator()->validateConfiguration($record->refresh(), ',', 1, ['smiles', 'g_pen']);

    expect($result)->toBe([
        'ok' => false,
        'errors' => ['Cannot read uploaded file.'],
        'warnings' => [],
        'config' => null,
    ]);
});

test('a valid file passes the quick validation with a configuration ready to be saved', function () {
    $record = createUploadForFrontendConfiguration("SMILES;Gpen;Note\nCCO; -1.5 ;whatever\nCCN;-2;anything\n");

    $result = frontendConfigurator()->validateConfiguration($record, ';', 1, [' smiles ', 'g_pen', null]);

    expect($result['ok'])->toBeTrue()
        ->and($result['errors'])->toBe([])
        ->and($result['warnings'])->toBe([])
        ->and($result['config'][UploadQueueConfig::SEPARATOR])->toBe(';')
        ->and($result['config'][UploadQueueConfig::SKIP_FIRST_ROW])->toBe(1)
        ->and($result['config'][UploadQueueConfig::ATTRIBUTES])->toBe(['smiles', 'g_pen', 'ignore'])
        ->and($result['config'][UploadQueueConfig::QUICK_VALIDATION_OK])->toBeTrue()
        ->and($result['config'][UploadQueueConfig::VALIDATED_ROWS])->toBe(2)
        ->and($result['config'])->toHaveKeys([UploadQueueConfig::VALIDATED_AT, UploadQueueConfig::QUICK_VALIDATION_AT]);
});

test('values of ignored columns are not validated', function () {
    $record = createUploadForFrontendConfiguration("SMILES,Gpen,Junk\nCCO,-1,not-a-number\n");

    $result = frontendConfigurator()->validateConfiguration($record, ',', 1, ['smiles', 'g_pen', 'ignore']);

    expect($result['ok'])->toBeTrue();
});

test('the quick validation requires the columns every upload of its type needs', function (int $type, array $mapping, string $error) {
    $record = createUploadForFrontendConfiguration("A,B,C\nCCO,1,2\n", type: $type);

    expect(frontendConfigurator()->validateConfiguration($record, ',', 1, $mapping))->toBe([
        'ok' => false,
        'errors' => [$error],
        'warnings' => [],
        'config' => null,
    ]);
})->with([
    'no SMILES' => [UploadQueue::TYPE_PASSIVE_DATASET, ['name', 'g_pen', ''], 'Column SMILES is required.'],
    'passive without an interaction value' => [UploadQueue::TYPE_PASSIVE_DATASET, ['smiles', 'g_pen_acc', 'temperature'], 'At least one passive interaction value column is required (Gpen, Gwat, LogK, LogPerm or Xmin).'],
    'active without a target' => [UploadQueue::TYPE_ACTIVE_DATASET, ['smiles', 'Ic50', null], 'Column Target (Uniprot ID) is required for active interactions.'],
    'active without an interaction value' => [UploadQueue::TYPE_ACTIVE_DATASET, ['smiles', 'active_target', 'ic50_acc'], 'At least one active interaction value column is required (EC50, IC50, Ki or Km).'],
]);

test('the quick validation reports every invalid line with its number', function () {
    $record = createUploadForFrontendConfiguration("SMILES,Gpen\nnot-a-smiles,-1\nCCO,high\nCCN,-2,extra\nCCC,-3\n");

    $result = frontendConfigurator()->validateConfiguration($record, ',', 1, ['smiles', 'g_pen']);

    expect($result['ok'])->toBeFalse()
        ->and($result['config'])->toBeNull()
        ->and($result['errors'])->toBe([
            'Line 2: Column SMILES contains invalid SMILES string.',
            'Line 3: Column Gpen must be a number.',
            'Line 4: number of values does not match configured column count.',
        ]);
});

test('the quick validation stops after twenty errors', function (string $badLine) {
    $record = createUploadForFrontendConfiguration("SMILES,Gpen\n".str_repeat($badLine, 30));

    $result = frontendConfigurator()->validateConfiguration($record, ',', 0, ['smiles', 'g_pen']);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->toHaveCount(20)
        ->and(end($result['errors']))->toStartWith('Line 20: ');
})->with([
    'invalid values' => ["CCO,high\n"],
    'wrong number of values' => ["CCO\n"],
]);

test('values outside the limits of the dataset method pass with one warning', function () {
    $record = createUploadForFrontendConfiguration("SMILES,LogPerm\nCCO,-3\nCCN,-9\nCCC,-10\n");
    $record->dataset->method->update([
        'parameters' => new MethodParameters(['alert_limits' => ['logperm' => ['min' => -8, 'max' => 0]]]),
    ]);

    $result = frontendConfigurator()->validateConfiguration($record->refresh(), ',', 1, ['smiles', 'logperm']);

    expect($result['ok'])->toBeTrue()
        ->and($result['warnings'])->toBe(['Some values for column LogPerm are out of method limits.']);
});

test('the warnings of a dataset without a method are skipped', function () {
    $record = createUploadForFrontendConfiguration("SMILES,LogPerm\nCCO,-30\n");
    $record->dataset->forceFill(['method_id' => null])->saveQuietly();

    $result = frontendConfigurator()->validateConfiguration($record->refresh(), ',', 1, ['smiles', 'logperm']);

    expect($result['ok'])->toBeTrue()
        ->and($result['warnings'])->toBe([]);
});

test('only whoever may access an upload may preview and validate its configuration', function () {
    $record = createUploadForFrontendConfiguration("SMILES,Gpen\nCCO,-1\n", attributes: ['guest_token' => 'guest-secret']);
    $owner = User::query()->findOrFail($record->user_id);
    $stranger = User::factory()->create();
    $preview = apiRoutePath("api/lab/upload/{$record->id}/configure/preview");
    $validate = apiRoutePath("api/lab/upload/{$record->id}/configure/validate");
    $config = ['separator' => ',', 'skip_first_row' => 1, 'attributes' => ['smiles', 'g_pen']];

    $this->getJson($preview)->assertForbidden();
    $this->getJson($preview.'?guest_token=wrong')->assertForbidden();
    $this->getJson($preview.'?guest_token=guest-secret')->assertOk()->assertJsonPath('data.preview_rows', [['CCO', '-1']]);
    $this->postJson($validate, [...$config, 'guest_token' => 'wrong'])->assertForbidden();
    expect($record->refresh()->state)->toBe(UploadQueue::STATE_UPLOADED);

    $this->actingAs($stranger)->getJson($preview)->assertForbidden();
    $this->actingAs($stranger)->postJson($validate, $config)->assertForbidden();
    expect($record->refresh()->state)->toBe(UploadQueue::STATE_UPLOADED);

    $this->actingAs($owner)->getJson($preview)->assertOk();
    $this->actingAs($owner)->postJson($validate, $config)->assertOk();
    expect($record->refresh()->state)->toBe(UploadQueue::STATE_CONFIGURED);
});

test('a guest validates the configuration of their upload with its token', function () {
    $record = createUploadForFrontendConfiguration("SMILES,Gpen\nCCO,-1\n", attributes: ['guest_token' => 'guest-secret']);

    $this->postJson(apiRoutePath("api/lab/upload/{$record->id}/configure/validate"), [
        'separator' => ',',
        'skip_first_row' => 1,
        'attributes' => ['smiles', 'g_pen'],
        'guest_token' => 'guest-secret',
    ])->assertOk();

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_CONFIGURED)
        ->and($record->config->attributes())->toBe(['smiles', 'g_pen']);
});

test('upload queue managers may configure the uploads of other users', function () {
    $record = createUploadForFrontendConfiguration("SMILES,Gpen\nCCO,-1\n");
    Permission::query()->firstOrCreate(
        ['name' => PermissionEnums::UPLOAD_QUEUE_MANAGE_ALL->value, 'guard_name' => 'web'],
        ['description' => PermissionEnums::UPLOAD_QUEUE_MANAGE_ALL->description()],
    );
    $manager = User::factory()->create();
    $manager->givePermissionTo(PermissionEnums::UPLOAD_QUEUE_MANAGE_ALL->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($manager->refresh())
        ->postJson(apiRoutePath("api/lab/upload/{$record->id}/configure/validate"), [
            'separator' => ',',
            'skip_first_row' => 1,
            'attributes' => ['smiles', 'g_pen'],
        ])
        ->assertOk();

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_CONFIGURED);
});

test('the configuration of an upload that is already being processed cannot be loaded or changed', function () {
    $record = createUploadForFrontendConfiguration("SMILES,Gpen\nCCO,-1\n", attributes: ['state' => UploadQueue::STATE_PENDING]);
    $owner = User::query()->findOrFail($record->user_id);

    $this->actingAs($owner)
        ->getJson(apiRoutePath("api/lab/upload/{$record->id}/configure/preview"))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('record');

    $this->actingAs($owner)
        ->postJson(apiRoutePath("api/lab/upload/{$record->id}/configure/validate"), [
            'separator' => ',',
            'skip_first_row' => 1,
            'attributes' => ['smiles', 'g_pen'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('record');

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_PENDING);
});

test('a failed quick validation through the endpoint is logged on the upload and keeps its state', function () {
    $record = createUploadForFrontendConfiguration("SMILES,Gpen\nCCO,high\n");
    $owner = User::query()->findOrFail($record->user_id);

    $this->actingAs($owner)
        ->postJson(apiRoutePath("api/lab/upload/{$record->id}/configure/validate"), [
            'separator' => ',',
            'skip_first_row' => 1,
            'attributes' => ['smiles', 'g_pen'],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.config', ['Line 2: Column Gpen must be a number.']);

    $record->refresh();

    expect($record->state)->toBe(UploadQueue::STATE_UPLOADED)
        ->and($record->config->quickValidationPassed())->toBeFalse()
        ->and($record->logs->contains(fn ($log): bool => $log->message === 'Quick frontend validation failed.'))->toBeTrue();
});
