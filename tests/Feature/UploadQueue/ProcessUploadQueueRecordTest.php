<?php

require_once __DIR__.'/upload_pipeline_helpers.php';

use App\Jobs\ProcessUploadQueueRecord;
use App\Models\InteractionActive;
use App\Models\InteractionPassive;
use App\Models\QueuedNotification;
use App\Models\Structure;
use App\Models\UploadQueue;
use App\Models\User;
use App\Services\LabUploadAdminDigestQueue;
use App\Services\UploadQueueDetailedValidator;
use App\Services\UploadQueueDuplicateInteractionChecker;
use App\ValueObjects\UploadQueueConfig;

/*
 * The upload worker end to end: detailed validation, the stop for the
 * administrators' review, the import and the error paths, with the chemistry
 * services faked (see fakeUploadChemistryServices()).
 */

beforeEach(function () {
    fakePublicStorageForTests();
    fakeUploadChemistryServices(invalidSmiles: ['not-a-smiles']);
});

const PASSIVE_UPLOAD_CSV = "SMILES,Gpen,+/- Gpen,LogK,Temperature\nCCO,-12.345,0.2,1.5,25\nc1ccccc1,-8.1,,0.9,25\n";

const PASSIVE_UPLOAD_COLUMNS = ['smiles', 'g_pen', 'g_pen_acc', 'logk', 'temperature'];

/**
 * @return array<string, true>
 */
function approvedUploadConfig(): array
{
    return [
        UploadQueueConfig::DETAILED_VALIDATION_OK => true,
        UploadQueueConfig::ADMIN_REVIEW_APPROVED => true,
    ];
}

/**
 * Every log message of the record, since a status update log entry follows
 * the error that changed the state.
 */
function uploadLogMessages(UploadQueue $record): string
{
    return $record->logs->pluck('message')->implode("\n");
}

test('a valid passive upload passes detailed validation and waits for the administrators', function () {
    $record = createConfiguredUpload(PASSIVE_UPLOAD_CSV, PASSIVE_UPLOAD_COLUMNS);

    ProcessUploadQueueRecord::dispatchSync($record->id);

    $record->refresh();

    expect($record->state)->toBe(UploadQueue::STATE_REVIEW_REQUIRED)
        ->and($record->config->detailedValidationPassed())->toBeTrue()
        ->and(InteractionPassive::query()->count())->toBe(0)
        ->and(QueuedNotification::query()
            ->where('group_key', LabUploadAdminDigestQueue::GROUP)
            ->where('event', LabUploadAdminDigestQueue::EVENT_REVIEW_REQUIRED)
            ->where('notifiable_id', $record->id)
            ->exists())->toBeTrue();
});

test('an approved passive upload imports every row into its dataset', function () {
    $record = createConfiguredUpload(PASSIVE_UPLOAD_CSV, PASSIVE_UPLOAD_COLUMNS, config: approvedUploadConfig());

    ProcessUploadQueueRecord::dispatchSync($record->id);

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_DONE);

    $interactions = InteractionPassive::query()->where('dataset_id', $record->dataset_id)->with('structure')->get();

    expect($interactions)->toHaveCount(2)
        ->and($interactions->pluck('structure.canonical_smiles')->sort()->values()->all())
        ->toBe(['canon:CCO', 'canon:c1ccccc1']);

    $ethanol = $interactions->firstWhere('structure.canonical_smiles', 'canon:CCO');

    expect((float) $ethanol->gpen)->toBe(-12.35)
        ->and((float) $ethanol->gpen_accuracy)->toBe(0.2)
        ->and((float) $ethanol->temperature)->toBe(25.0);
});

test('an approved active upload imports IC50 values with their accuracy', function () {
    $protein = createApiProtein(['uniprot_id' => 'P08183']);
    $record = createConfiguredUpload(
        "SMILES,Target,Ic50,+/- Ic50\nCCO,P08183,0.5,0.1\n",
        ['smiles', 'active_target', 'Ic50', 'ic50_acc'],
        UploadQueue::TYPE_ACTIVE_DATASET,
        config: approvedUploadConfig(),
    );

    ProcessUploadQueueRecord::dispatchSync($record->id);

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_DONE);

    $interaction = InteractionActive::query()->where('dataset_id', $record->dataset_id)->sole();

    expect($interaction->protein_id)->toBe($protein->id)
        ->and((float) $interaction->ic50)->toBe(0.5)
        ->and((float) $interaction->ic50_accuracy)->toBe(0.1);
});

test('rows with invalid values fail the upload and name the offending lines', function () {
    $record = createConfiguredUpload(
        "SMILES,Gpen\nCCO,-12.3\nnot-a-smiles,-8.1\nCCN,low\n",
        ['smiles', 'g_pen'],
    );

    ProcessUploadQueueRecord::dispatchSync($record->id);

    $record->refresh();
    $message = uploadLogMessages($record);

    expect($record->state)->toBe(UploadQueue::STATE_ERROR)
        ->and($message)->toContain('Line 3: Column SMILES contains invalid SMILES string.')
        ->and($message)->toContain('Line 4: Column Gpen must be a number.')
        ->and(InteractionPassive::query()->count())->toBe(0);
});

test('an upload whose every row is invalid reports the line errors', function () {
    $record = createConfiguredUpload("SMILES,Gpen\nnot-a-smiles,-8.1\nCCN,low\n", ['smiles', 'g_pen']);

    ProcessUploadQueueRecord::dispatchSync($record->id);

    $record->refresh();

    expect($record->state)->toBe(UploadQueue::STATE_ERROR)
        ->and(uploadLogMessages($record))->toContain('Line 2: Column SMILES contains invalid SMILES string.')
        ->and(uploadLogMessages($record))->toContain('Line 3: Column Gpen must be a number.')
        ->and(uploadLogMessages($record))->not->toContain('File does not contain rows for validation.');
});

test('an upload without data rows fails', function () {
    $record = createConfiguredUpload("SMILES,Gpen\n", ['smiles', 'g_pen']);

    ProcessUploadQueueRecord::dispatchSync($record->id);

    $record->refresh();

    expect($record->state)->toBe(UploadQueue::STATE_ERROR)
        ->and(uploadLogMessages($record))->toContain('File does not contain rows for validation.');
});

test('an upload without any interaction value column fails', function () {
    $record = createConfiguredUpload("SMILES,Temperature\nCCO,25\n", ['smiles', 'temperature']);

    ProcessUploadQueueRecord::dispatchSync($record->id);

    $record->refresh();

    expect($record->state)->toBe(UploadQueue::STATE_ERROR)
        ->and(uploadLogMessages($record))->toContain('At least one passive interaction value column is required');
});

test('rows whose column count differs from the header fail the upload', function () {
    $record = createConfiguredUpload("SMILES,Gpen\nCCO,-12.3,extra\n", ['smiles', 'g_pen']);

    ProcessUploadQueueRecord::dispatchSync($record->id);

    $record->refresh();

    expect($record->state)->toBe(UploadQueue::STATE_ERROR)
        ->and(uploadLogMessages($record))->toContain('Line 2: number of values does not match header column count.');
});

test('an upload that only repeats interactions already in the dataset fails with nothing to import', function () {
    $first = createConfiguredUpload(PASSIVE_UPLOAD_CSV, PASSIVE_UPLOAD_COLUMNS, config: approvedUploadConfig());
    ProcessUploadQueueRecord::dispatchSync($first->id);

    $repeat = createConfiguredUpload(PASSIVE_UPLOAD_CSV, PASSIVE_UPLOAD_COLUMNS, dataset: $first->dataset);
    ProcessUploadQueueRecord::dispatchSync($repeat->id);

    $repeat->refresh();

    expect($repeat->state)->toBe(UploadQueue::STATE_ERROR)
        ->and(uploadLogMessages($repeat))->toContain('There is nothing to import.')
        ->and(InteractionPassive::query()->where('dataset_id', $first->dataset_id)->count())->toBe(2);
});

test('records that are not pending or running are left alone', function (int $state) {
    $record = createConfiguredUpload(PASSIVE_UPLOAD_CSV, PASSIVE_UPLOAD_COLUMNS, attributes: ['state' => $state]);

    ProcessUploadQueueRecord::dispatchSync($record->id);

    expect($record->refresh()->state)->toBe($state);
})->with([
    'review required' => UploadQueue::STATE_REVIEW_REQUIRED,
    'done' => UploadQueue::STATE_DONE,
    'canceled' => UploadQueue::STATE_CANCELED,
]);

test('an upload with UniChem identifiers waits while UniChem is unreachable', function () {
    fakeUploadChemistryServices(knownUnichemIds: ['702'], unichemReachable: false);
    $record = createConfiguredUpload("SMILES,PubChem,Gpen\nCCO,702,-12.3\n", ['smiles', 'pubchem', 'g_pen']);

    ProcessUploadQueueRecord::dispatchSync($record->id);

    $record->refresh();

    expect($record->state)->toBe(UploadQueue::STATE_RUNNING)
        ->and($record->config->detailedValidationPassed())->toBeFalse()
        ->and(uploadLogMessages($record))->toContain('UniChem API is not reachable');
});

test('reverting an imported upload removes the interactions of its dataset', function () {
    $record = createConfiguredUpload(PASSIVE_UPLOAD_CSV, PASSIVE_UPLOAD_COLUMNS, config: approvedUploadConfig());
    ProcessUploadQueueRecord::dispatchSync($record->id);

    $this->actingAs(User::factory()->create());
    $record->refresh()->revert();

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_CONFIGURED)
        ->and(InteractionPassive::withTrashed()->where('dataset_id', $record->dataset_id)->count())->toBe(0)
        ->and(Structure::query()->where('canonical_smiles', 'canon:CCO')->exists())->toBeTrue();
});

test('a validation resumed from a checkpoint keeps the errors found before it', function () {
    $record = createConfiguredUpload("SMILES,Gpen\nCCN,low\nCCO,-12.3\nCCC,-1\n", ['smiles', 'g_pen']);
    $validator = app(UploadQueueDetailedValidator::class);

    $first = $validator->validate($record, now()->subSecond());

    expect($first['deferred'] ?? false)->toBeTrue();

    $resumed = $validator->validate($record->refresh());

    expect($resumed['ok'])->toBeFalse()
        ->and($resumed['errors'])->toContain('Line 2: Column Gpen must be a number.');
});

test('the same compound in another membrane is not a duplicate', function () {
    $first = createConfiguredUpload(PASSIVE_UPLOAD_CSV, PASSIVE_UPLOAD_COLUMNS, config: approvedUploadConfig());
    ProcessUploadQueueRecord::dispatchSync($first->id);

    $publication = $first->dataset->publications()->first();
    $otherMembrane = createApiDataset(['publication' => $publication, 'method' => $first->dataset->method]);
    $sameMembraneAndMethod = createApiDataset([
        'publication' => $publication,
        'membrane' => $first->dataset->membrane,
        'method' => $first->dataset->method,
    ]);

    $intoOtherMembrane = createConfiguredUpload(PASSIVE_UPLOAD_CSV, PASSIVE_UPLOAD_COLUMNS, dataset: $otherMembrane);
    ProcessUploadQueueRecord::dispatchSync($intoOtherMembrane->id);

    $intoSameMembrane = createConfiguredUpload(PASSIVE_UPLOAD_CSV, PASSIVE_UPLOAD_COLUMNS, dataset: $sameMembraneAndMethod);
    ProcessUploadQueueRecord::dispatchSync($intoSameMembrane->id);

    expect($intoOtherMembrane->refresh()->state)->toBe(UploadQueue::STATE_REVIEW_REQUIRED)
        ->and($intoSameMembrane->refresh()->state)->toBe(UploadQueue::STATE_ERROR)
        ->and(uploadLogMessages($intoSameMembrane))->toContain('There is nothing to import.');
});

test('a row whose publication is not in the database yet is never a duplicate', function () {
    $first = createConfiguredUpload(PASSIVE_UPLOAD_CSV, PASSIVE_UPLOAD_COLUMNS, config: approvedUploadConfig());
    ProcessUploadQueueRecord::dispatchSync($first->id);

    $existing = InteractionPassive::query()->where('dataset_id', $first->dataset_id)->firstOrFail();

    $duplicate = app(UploadQueueDuplicateInteractionChecker::class)->check($first->refresh(), [
        'structure_id' => $existing->structure_id,
        'publication_id' => -1,
        'temperature' => $existing->temperature,
        'gpen' => 1.0,
    ], ['gpen']);

    expect($duplicate)->toBeNull();
});
