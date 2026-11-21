<?php

require_once __DIR__.'/upload_pipeline_helpers.php';

use App\Livewire\UploadQueueConfigure;
use App\Models\UploadQueue;
use App\ValueObjects\MethodParameters;
use App\ValueObjects\UploadQueueConfig;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

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

const CONFIGURE_PASSIVE_CSV = "SMILES,Gpen,Comment\nCCO,-12.3,first\nc1ccccc1,-8.1,second\nCCN,-7.0,third\n";

const CONFIGURE_PASSIVE_COLUMNS = ['smiles', 'g_pen', 'comment'];

/**
 * A passive upload of CONFIGURE_PASSIVE_CSV, or of the given CSV, uploaded
 * but not configured yet.
 *
 * @param  array<int, string|null>  $columns
 * @param  array<string, mixed>  $config
 */
function createUploadToConfigure(
    string $csv = CONFIGURE_PASSIVE_CSV,
    array $columns = CONFIGURE_PASSIVE_COLUMNS,
    int $type = UploadQueue::TYPE_PASSIVE_DATASET,
    array $config = [],
): UploadQueue {
    return createConfiguredUpload($csv, $columns, $type, $config, ['state' => UploadQueue::STATE_UPLOADED]);
}

test('the form offers only the column types of a passive upload and loads its saved mapping', function () {
    $record = createUploadToConfigure();

    $component = Livewire::test(UploadQueueConfigure::class, ['record' => $record])
        ->assertOk()
        ->assertSet('skipFirstRow', 1)
        ->assertSet('separator', ',')
        ->assertSet('columnMapping', CONFIGURE_PASSIVE_COLUMNS)
        ->assertSet('totalRows', 4)
        ->assertSet('previewRows', [
            ['CCO', '-12.3', 'first'],
            ['c1ccccc1', '-8.1', 'second'],
            ['CCN', '-7.0', 'third'],
        ])
        ->assertSet('errorMessages', []);

    $types = $component->get('validColumnTypes');

    expect(array_key_first($types))->toBe(UploadQueueConfigure::IGNORE_COLUMN)
        ->and($types)->toHaveKeys(['smiles', 'g_pen', 'logperm', 'x_min'])
        ->and($types)->not->toHaveKeys(['active_target', 'ec50', 'interaction_type']);
});

test('the form offers only the column types of an active upload', function () {
    $record = createUploadToConfigure(
        "SMILES,Target,IC50\nCCO,P08183,0.5\n",
        ['smiles', 'active_target', 'Ic50'],
        UploadQueue::TYPE_ACTIVE_DATASET,
    );

    $types = Livewire::test(UploadQueueConfigure::class, ['record' => $record])->get('validColumnTypes');

    expect($types)->toHaveKeys(['ignore', 'smiles', 'active_target', 'Ic50', 'protein_name', 'interaction_type'])
        ->and($types)->not->toHaveKeys(['g_pen', 'logperm']);
});

test('a column type picked for one column is not offered to the other columns', function () {
    $record = createUploadToConfigure();

    $validTypes = Livewire::test(UploadQueueConfigure::class, ['record' => $record])->get('columnValidTypes');

    expect($validTypes)->toHaveCount(3)
        ->and($validTypes[0])->toHaveKeys(['ignore', 'smiles'])
        ->and($validTypes[0])->not->toHaveKeys(['g_pen', 'comment'])
        ->and($validTypes[1])->toHaveKeys(['ignore', 'g_pen'])
        ->and($validTypes[1])->not->toHaveKeys(['smiles', 'comment'])
        ->and($validTypes[2])->toHaveKeys(['ignore', 'comment', 'logperm']);
});

test('a saved mapping that no longer fits the file is replaced by an empty one', function () {
    $record = createUploadToConfigure(columns: ['smiles', 'g_pen']);

    Livewire::test(UploadQueueConfigure::class, ['record' => $record])
        ->assertSet('columnMapping', [null, null, null]);
});

test('a saved column type the upload type does not know leaves its column unmapped', function () {
    $record = createUploadToConfigure(columns: ['smiles', 'active_target', 'comment']);

    Livewire::test(UploadQueueConfigure::class, ['record' => $record])
        ->assertSet('columnMapping', ['smiles', null, 'comment']);
});

test('a saved mapping with unmapped columns is shown also when the file is missing', function () {
    $record = createUploadToConfigure(columns: ['smiles', null, 'g_pen']);
    Storage::disk('public')->delete($record->file->path);

    $component = Livewire::test(UploadQueueConfigure::class, ['record' => $record])
        ->assertOk()
        ->assertSet('columnMapping', ['smiles', null, 'g_pen'])
        ->assertSet('errorMessages', ['Uploaded file not found on remote server.']);

    expect($component->get('columnValidTypes'))->toHaveCount(3);
});

test('the saved separator is used to read the file and an unknown one falls back to a comma', function () {
    $semicolon = createUploadToConfigure("SMILES;Gpen\nCCO;-1.5\n", ['smiles', 'g_pen'], config: [UploadQueueConfig::SEPARATOR => ';']);
    $unknown = createUploadToConfigure("SMILES,Gpen\nCCO,-1.5\n", ['smiles', 'g_pen'], config: [UploadQueueConfig::SEPARATOR => '|']);

    Livewire::test(UploadQueueConfigure::class, ['record' => $semicolon])
        ->assertSet('separator', ';')
        ->assertSet('previewRows', [['CCO', '-1.5']]);

    Livewire::test(UploadQueueConfigure::class, ['record' => $unknown])
        ->assertSet('separator', ',')
        ->assertSet('previewRows', [['CCO', '-1.5']]);
});

test('the preview shows at most six rows but counts every line of the file', function () {
    $csv = "SMILES,Gpen\n".implode('', array_map(fn (int $i): string => "C{$i},-{$i}.0\n", range(1, 9)));
    $record = createUploadToConfigure($csv, ['smiles', 'g_pen']);

    $component = Livewire::test(UploadQueueConfigure::class, ['record' => $record])
        ->assertSet('totalRows', 10);

    expect($component->get('previewRows'))->toHaveCount(6)
        ->and($component->get('previewRows')[0])->toBe(['C1', '-1.0']);
});

test('an upload whose file is missing shows an error instead of the preview', function () {
    $record = createUploadToConfigure();
    Storage::disk('public')->delete($record->file->path);

    Livewire::test(UploadQueueConfigure::class, ['record' => $record])
        ->assertOk()
        ->assertSet('previewRows', [])
        ->assertSet('errorMessages', ['Uploaded file not found on remote server.'])
        ->call('validateColumns')
        ->assertSet('isValidated', false)
        ->assertSet('errorMessages', ['Uploaded file not found on remote server.']);
});

test('including the header row shows it in the preview and keeps the mapping', function () {
    $record = createUploadToConfigure();

    $component = Livewire::test(UploadQueueConfigure::class, ['record' => $record])
        ->call('validateColumns')
        ->assertSet('isValidated', true)
        ->set('skipFirstRow', 0)
        ->assertSet('isValidated', false)
        ->assertSet('columnMapping', CONFIGURE_PASSIVE_COLUMNS);

    expect($component->get('previewRows')[0])->toBe(['SMILES', 'Gpen', 'Comment']);
});

test('changing the separator re-reads the file and resets a mapping that no longer fits', function () {
    $record = createUploadToConfigure();

    Livewire::test(UploadQueueConfigure::class, ['record' => $record])
        ->set('separator', ';')
        ->assertSet('isValidated', false)
        ->assertSet('previewRows', [['CCO,-12.3,first'], ['c1ccccc1,-8.1,second'], ['CCN,-7.0,third']])
        ->assertSet('columnMapping', [null]);
});

test('changing the mapping invalidates a previous validation and updates the offered types', function () {
    $record = createUploadToConfigure();

    $component = Livewire::test(UploadQueueConfigure::class, ['record' => $record])
        ->call('validateColumns')
        ->assertSet('isValidated', true)
        ->set('columnMapping.2', 'logperm')
        ->assertSet('isValidated', false);

    expect($component->get('columnValidTypes')[0])->not->toHaveKey('logperm')
        ->and($component->get('columnValidTypes')[0])->toHaveKey('comment')
        ->and($component->get('columnValidTypes')[2])->toHaveKey('logperm');
});

test('validation requires the columns every upload of its type needs', function (int $type, array $mapping, string $error) {
    $record = createUploadToConfigure("A,B,C\nCCO,1,2\n", [null, null, null], $type);

    Livewire::test(UploadQueueConfigure::class, ['record' => $record])
        ->set('columnMapping', $mapping)
        ->call('validateColumns')
        ->assertSet('isValidated', false)
        ->assertSet('errorMessages', [$error]);
})->with([
    'nothing mapped' => [UploadQueue::TYPE_PASSIVE_DATASET, [], 'Column SMILES is required.'],
    'passive without SMILES' => [UploadQueue::TYPE_PASSIVE_DATASET, ['name', 'g_pen', 'ignore'], 'Column SMILES is required.'],
    'passive without an interaction value' => [UploadQueue::TYPE_PASSIVE_DATASET, ['smiles', 'g_pen_acc', 'comment'], 'At least one passive interaction column is required.'],
    'active without a target' => [UploadQueue::TYPE_ACTIVE_DATASET, ['smiles', 'Ic50', 'ignore'], 'Column target is required.'],
    'active without an interaction value' => [UploadQueue::TYPE_ACTIVE_DATASET, ['smiles', 'active_target', 'ic50_acc'], 'At least one active interaction column is required.'],
]);

test('a valid file passes the validation and can be saved', function () {
    $record = createUploadToConfigure();

    Livewire::test(UploadQueueConfigure::class, ['record' => $record])
        ->call('validateColumns')
        ->assertSet('isValidated', true)
        ->assertSet('errorMessages', [])
        ->assertSet('warningMessages', [])
        ->call('save')
        ->assertNotified('Settings saved');

    $record->refresh();

    expect($record->state)->toBe(UploadQueue::STATE_CONFIGURED)
        ->and($record->config->separator())->toBe(',')
        ->and($record->config->skipFirstRow())->toBe(1)
        ->and($record->config->attributes())->toBe(CONFIGURE_PASSIVE_COLUMNS);
});

test('saving stores the separator, header setting and mapping picked in the form', function () {
    $record = createUploadToConfigure("CCO;-1.5\nCCN;-2.5\n", [null], config: [UploadQueueConfig::SKIP_FIRST_ROW => 1]);

    Livewire::test(UploadQueueConfigure::class, ['record' => $record])
        ->set('separator', ';')
        ->set('skipFirstRow', 0)
        ->set('columnMapping', ['smiles', 'g_pen'])
        ->call('validateColumns')
        ->assertSet('isValidated', true)
        ->call('save');

    $record->refresh();

    expect($record->config->separator())->toBe(';')
        ->and($record->config->skipFirstRow())->toBe(0)
        ->and($record->config->attributes())->toBe(['smiles', 'g_pen']);
});

test('validation stops at the first invalid line and moves the preview to it', function () {
    $csv = "SMILES,Gpen,Comment\nCCO,-1,a\nCCN,-2,b\nCCC,-3,c\nCCCC,high,d\nCCCCC,-5,e\n";
    $record = createUploadToConfigure($csv);

    $component = Livewire::test(UploadQueueConfigure::class, ['record' => $record])
        ->call('validateColumns')
        ->assertSet('isValidated', false)
        ->assertSet('errorMessages', ['Line 5: Column Gpen must be a number.'])
        ->assertSet('startLine', 3)
        ->assertSet('columnMapping', CONFIGURE_PASSIVE_COLUMNS);

    expect($component->get('previewRows')[0])->toBe(['CCN', '-2', 'b']);
});

test('validation rejects SMILES the chemistry service does not accept', function () {
    $record = createUploadToConfigure("SMILES,Gpen\nnot-a-smiles,-1\n", ['smiles', 'g_pen']);

    Livewire::test(UploadQueueConfigure::class, ['record' => $record])
        ->call('validateColumns')
        ->assertSet('isValidated', false)
        ->assertSet('errorMessages', ['Line 2: Column SMILES contains invalid SMILES string.']);
});

test('validation warns once about values outside the limits of the dataset method', function () {
    $record = createUploadToConfigure("SMILES,LogPerm\nCCO,-3\nCCN,-9\nCCC,-10\n", ['smiles', 'logperm']);
    $record->dataset->method->update([
        'parameters' => new MethodParameters(['alert_limits' => ['logperm' => ['min' => -8, 'max' => 0]]]),
    ]);

    Livewire::test(UploadQueueConfigure::class, ['record' => $record->refresh()])
        ->call('validateColumns')
        ->assertSet('isValidated', true)
        ->assertSet('errorMessages', [])
        ->assertSet('warningMessages', ['logperm' => "Some values for column LogPerm are out of method's limits."]);
});

test('validation of an upload of an unsupported type fails loudly', function () {
    $record = createUploadToConfigure("SMILES,Gpen\nCCO,-1\n", ['smiles', 'g_pen']);
    $record->forceFill(['type' => 99])->saveQuietly();

    $component = Livewire::test(UploadQueueConfigure::class, ['record' => $record->refresh()])
        ->assertSet('validColumnTypes', ['ignore' => 'Ignore'])
        ->set('columnMapping', ['smiles', 'g_pen']);

    expect(fn () => $component->call('validateColumns'))->toThrow(Exception::class, 'Validators not implemented');
});

test('an empty file shows an empty preview and keeps the saved mapping', function () {
    $record = createUploadToConfigure('', ['smiles', 'g_pen']);

    $component = Livewire::test(UploadQueueConfigure::class, ['record' => $record])
        ->assertOk()
        ->assertSet('previewRows', [])
        ->assertSet('totalRows', 0)
        ->assertSet('columnMapping', ['smiles', 'g_pen']);

    expect($component->get('columnValidTypes'))->toHaveCount(2);
});

test('validation reports a line whose number of values differs from the mapping', function () {
    $record = createUploadToConfigure("SMILES,Gpen,Comment\nCCO,-1,a\nCCN,-2\n");

    Livewire::test(UploadQueueConfigure::class, ['record' => $record])
        ->call('validateColumns')
        ->assertSet('isValidated', false)
        ->assertSet('errorMessages', ['Line 3: number of values does not match configured column count.']);
});

test('validation of a dataset without a method skips the method limits', function () {
    $record = createUploadToConfigure("SMILES,LogPerm\nCCO,-3\n", ['smiles', 'logperm']);
    $record->dataset->forceFill(['method_id' => null])->saveQuietly();

    Livewire::test(UploadQueueConfigure::class, ['record' => $record->refresh()])
        ->call('validateColumns')
        ->assertSet('isValidated', true)
        ->assertSet('warningMessages', []);
});

test('a new validation drops the warnings of the previous one', function () {
    $record = createUploadToConfigure("SMILES,LogPerm,Gpen\nCCO,-10,-1\n", ['smiles', 'logperm', 'g_pen']);
    $record->dataset->method->update([
        'parameters' => new MethodParameters(['alert_limits' => ['logperm' => ['min' => -8, 'max' => 0]]]),
    ]);

    Livewire::test(UploadQueueConfigure::class, ['record' => $record->refresh()])
        ->call('validateColumns')
        ->assertSet('warningMessages', ['logperm' => "Some values for column LogPerm are out of method's limits."])
        ->set('columnMapping.1', 'ignore')
        ->call('validateColumns')
        ->assertSet('isValidated', true)
        ->assertSet('warningMessages', []);
});
