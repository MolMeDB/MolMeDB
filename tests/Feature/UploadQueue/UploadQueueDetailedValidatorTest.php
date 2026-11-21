<?php

require_once __DIR__.'/upload_pipeline_helpers.php';

use App\Jobs\ProcessUploadQueueRecord;
use App\Models\UploadQueue;
use App\Rules\UploadFile\ActiveInteractions\ColumnTarget;
use App\Rules\UploadFile\Identifiers\ColumnSmiles;
use App\Services\UploadQueueDetailedValidator;
use App\ValueObjects\UploadQueueConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/*
 * The detailed validation of an uploaded CSV on its own: the file and column
 * checks, the header detection for records without a manual configuration,
 * the error limits, duplicates of interactions already in the dataset and
 * the checkpoints of a validation that ran out of time.
 */

beforeEach(function () {
    fakePublicStorageForTests();
    fakeUploadChemistryServices(invalidSmiles: ['not-a-smiles']);
});

/**
 * @return array<string, mixed>
 */
function validateUpload(UploadQueue $record, ?DateTimeInterface $deadline = null): array
{
    return app(UploadQueueDetailedValidator::class)->validate($record, $deadline);
}

/**
 * An upload whose columns have not been configured, so the validator reads
 * them from the CSV header.
 */
function createUnconfiguredUpload(string $csv, int $type = UploadQueue::TYPE_PASSIVE_DATASET): UploadQueue
{
    $record = createConfiguredUpload($csv, [], $type);
    $record->config = UploadQueueConfig::fromArray([
        'source_publication_id' => $record->config['source_publication_id'],
    ]);
    $record->save();

    return $record->refresh();
}

/**
 * Imports the CSV into a new passive dataset and returns that record.
 */
function importPassiveUpload(string $csv): UploadQueue
{
    $record = createConfiguredUpload($csv, ['smiles', 'g_pen', 'temperature'], config: [
        UploadQueueConfig::DETAILED_VALIDATION_OK => true,
        UploadQueueConfig::ADMIN_REVIEW_APPROVED => true,
    ]);

    ProcessUploadQueueRecord::dispatchSync($record->id);

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_DONE);

    return $record;
}

test('a file without storage fails before anything is read', function () {
    $record = createConfiguredUpload("SMILES,Gpen\nCCO,-1\n", ['smiles', 'g_pen']);
    $record->file->update(['storage' => '']);

    expect(validateUpload($record->refresh()))->toBe([
        'ok' => false,
        'errors' => ['Uploaded file storage is not configured.'],
        'warnings' => [],
        'config' => null,
    ]);
});

test('a file without a path fails as not found', function () {
    $record = createConfiguredUpload("SMILES,Gpen\nCCO,-1\n", ['smiles', 'g_pen']);
    $record->file->update(['path' => '']);

    expect(validateUpload($record->refresh())['errors'])->toBe(['Uploaded file was not found on storage.']);
});

test('a file missing from its disk cannot be opened', function () {
    $record = createConfiguredUpload("SMILES,Gpen\nCCO,-1\n", ['smiles', 'g_pen']);
    Storage::disk('public')->delete($record->file->path);

    $result = validateUpload($record);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->toBe(['Cannot open uploaded file.']);
});

test('a disk that throws while opening the file is logged and reported as unreadable', function () {
    config()->set('filesystems.disks.throwing', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/disks/throwing'),
        'throw' => true,
    ]);
    $record = createConfiguredUpload("SMILES,Gpen\nCCO,-1\n", ['smiles', 'g_pen']);
    $record->file->update(['storage' => 'throwing', 'path' => 'missing/upload.csv']);

    $channel = Mockery::spy();
    Log::partialMock()->shouldReceive('channel')->with('upload')->andReturn($channel);

    $result = validateUpload($record->refresh());

    expect($result['errors'])->toBe(['Cannot open uploaded file.']);
    $channel->shouldHaveReceived('warning')
        ->once()
        ->with('Failed to open uploaded file for detailed validation.', Mockery::on(
            fn (array $context): bool => $context['record_id'] === $record->id
                && $context['disk'] === 'throwing'
                && $context['path'] === 'missing/upload.csv'
                && $context['exception'] !== '',
        ));
});

test('a valid upload returns its configuration with the number of validated rows', function () {
    $record = createConfiguredUpload(
        "SMILES,Note,Gpen\nCCO,first,-12.3\n\nc1ccccc1,second,-8.1\n",
        ['smiles', ' ', 'g_pen'],
        config: [UploadQueueConfig::PROCESSING_PROGRESS => ['phase' => 'detailed_validation', 'config_hash' => 'outdated']],
    );

    $result = validateUpload($record);

    expect($result['ok'])->toBeTrue()
        ->and($result['errors'])->toBe([])
        ->and($result['row_errors'])->toBe([])
        ->and($result['warnings'])->toBe([])
        ->and($result['config'][UploadQueueConfig::SEPARATOR])->toBe(',')
        ->and($result['config'][UploadQueueConfig::SKIP_FIRST_ROW])->toBe(1)
        ->and($result['config'][UploadQueueConfig::ATTRIBUTES])->toBe(['smiles', 'ignore', 'g_pen'])
        ->and($result['config'][UploadQueueConfig::DETAILED_VALIDATION_OK])->toBeTrue()
        ->and($result['config'][UploadQueueConfig::VALIDATED_ROWS])->toBe(2)
        ->and($result['config'])->not->toHaveKey(UploadQueueConfig::PROCESSING_PROGRESS)
        ->and($record->refresh()->config->processingProgress())->toBe([]);
});

test('a file without a header row needs no manual configuration to fail', function () {
    $record = createUnconfiguredUpload("\n  \n");

    expect(validateUpload($record)['errors'])->toBe(['File must contain a header and at least one data row.']);
});

test('without a manual configuration the separator and columns are read from the header', function (string $separator) {
    $csv = implode("\n", [
        '',
        implode($separator, ['SMILES', 'Unknown column', 'Gpen', '+/- Gpen', 'log k', 'temperature']),
        implode($separator, ['CCO', 'x', '-12.3', '0.2', '1.5', '25']),
        implode($separator, ['CCN', 'y', 'low', '0.2', '1.5', '25']),
    ])."\n";
    $record = createUnconfiguredUpload($csv);

    $result = validateUpload($record);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->toBe(['Line 4: Column Gpen must be a number.']);

    $valid = createUnconfiguredUpload(implode("\n", array_slice(explode("\n", $csv), 0, 3))."\n");
    $result = validateUpload($valid);

    expect($result['ok'])->toBeTrue()
        ->and($result['config'][UploadQueueConfig::SEPARATOR])->toBe($separator)
        ->and($result['config'][UploadQueueConfig::SKIP_FIRST_ROW])->toBe(1)
        ->and($result['config'][UploadQueueConfig::ATTRIBUTES])->toBe(['smiles', 'ignore', 'g_pen', 'g_pen_acc', 'logk', 'temperature']);
})->with([
    'comma' => ',',
    'semicolon' => ';',
    'tab' => "\t",
]);

test('a header without the SMILES column fails', function () {
    $record = createUnconfiguredUpload("Name,Gpen\nethanol,-1\n");

    expect(validateUpload($record)['errors'])->toBe(['Column '.ColumnSmiles::$label.' is required.']);
});

test('active uploads need a target and at least one interaction value column', function (array $columns, string $error) {
    $record = createConfiguredUpload(
        implode(',', $columns)."\n".implode(',', array_fill(0, count($columns), '1'))."\n",
        $columns,
        UploadQueue::TYPE_ACTIVE_DATASET,
    );

    expect(validateUpload($record)['errors'])->toBe([$error]);
})->with([
    'no target' => [['smiles', 'Ic50'], 'Column '.ColumnTarget::$label.' is required for active interactions.'],
    'no value' => [['smiles', 'active_target'], 'At least one active interaction value column is required (EC50, IC50, Ki or Km).'],
]);

test('validation stops after twenty errors', function (string $badRow) {
    $record = createConfiguredUpload("SMILES,Gpen\n".str_repeat($badRow."\n", 25), ['smiles', 'g_pen']);

    $result = validateUpload($record);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->toHaveCount(20)
        ->and($result['errors'][19])->toStartWith('Line 21: ');
})->with([
    'wrong column count' => 'CCO,-1,extra',
    'invalid value' => 'CCO,low',
]);

test('a row repeating an interaction with other values is a conflict and is skipped', function () {
    $imported = importPassiveUpload("SMILES,Gpen,Temperature\nCCO,-12.345,25\n");
    $record = createConfiguredUpload(
        "SMILES,Gpen,Temperature\nCCO,-1.5,25\nCCN,-2,25\n",
        ['smiles', 'g_pen', 'temperature'],
        dataset: $imported->dataset,
    );

    $result = validateUpload($record);

    expect($result['ok'])->toBeTrue()
        ->and($result['warnings'])->toBe([])
        ->and($result['row_errors'])->toHaveCount(1)
        ->and($result['row_errors'][0])->toStartWith('Line 2: interaction already exists in database as ID ')
        ->and($result['row_errors'][0])->toContain('different values after rounding to 2 decimals (gpen: DB=-12.35, upload=-1.5)')
        ->and($result['config'][UploadQueueConfig::VALIDATED_ROWS])->toBe(2);
});

test('an upload of only conflicting rows has nothing to import and lists the conflicts', function () {
    $imported = importPassiveUpload("SMILES,Gpen,Temperature\nCCO,-12.345,25\n");
    $record = createConfiguredUpload(
        "SMILES,Gpen,Temperature\nCCO,-1.5,25\n",
        ['smiles', 'g_pen', 'temperature'],
        dataset: $imported->dataset,
    );

    $result = validateUpload($record);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->toHaveCount(2)
        ->and($result['errors'][0])->toContain('There is nothing to import.')
        ->and($result['errors'][1])->toContain('gpen: DB=-12.35, upload=-1.5');
});

test('a row repeating an interaction with the same values is skipped with a warning', function () {
    $imported = importPassiveUpload("SMILES,Gpen,Temperature\nCCO,-12.345,25\n");
    $record = createConfiguredUpload(
        "SMILES,Gpen,Temperature\nCCO,-12.35,25\nCCN,-2,25\n",
        ['smiles', 'g_pen', 'temperature'],
        dataset: $imported->dataset,
    );

    $result = validateUpload($record);

    expect($result['ok'])->toBeTrue()
        ->and($result['row_errors'])->toBe([])
        ->and($result['warnings'])->toHaveCount(1)
        ->and($result['warnings'][0])->toEndWith('with the same values. Row will be skipped during import.');
});

test('a duplicate row that hits the deadline is checkpointed and the validation resumes after it', function () {
    $imported = importPassiveUpload("SMILES,Gpen,Temperature\nCCO,-12.345,25\n");
    $record = createConfiguredUpload(
        "SMILES,Gpen,Temperature\nCCO,-12.35,25\nCCN,-2,25\n",
        ['smiles', 'g_pen', 'temperature'],
        dataset: $imported->dataset,
    );

    $deferred = validateUpload($record, now()->subSecond());
    $progress = $record->refresh()->config->processingProgress();

    expect($deferred)->toMatchArray([
        'ok' => false,
        'deferred' => true,
        'next_line' => 3,
        'validated_rows' => 1,
        'processed_rows' => 1,
    ])
        ->and($progress['phase'])->toBe('detailed_validation')
        ->and($progress['next_line'])->toBe(3)
        ->and($progress['skipped_duplicate_rows'])->toBe(1)
        ->and($progress['warnings'])->toHaveCount(1);

    $resumed = validateUpload($record);

    expect($resumed['ok'])->toBeTrue()
        ->and($resumed['warnings'])->toHaveCount(1)
        ->and($resumed['config'][UploadQueueConfig::VALIDATED_ROWS])->toBe(2)
        ->and($record->refresh()->config->processingProgress())->toBe([]);
});
