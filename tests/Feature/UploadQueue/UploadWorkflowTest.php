<?php

require_once __DIR__.'/upload_pipeline_helpers.php';

use App\Jobs\ProcessUploadQueueRecord;
use App\Models\Category;
use App\Models\Dataset;
use App\Models\Identifier;
use App\Models\InteractionActive;
use App\Models\InteractionPassive;
use App\Models\Protein;
use App\Models\ProteinIdentifier;
use App\Models\QueuedNotification;
use App\Models\Structure;
use App\Models\UploadQueue;
use App\Models\User;
use App\Services\LabUploadAdminDigestQueue;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * An upload from the laboratory form to the imported interactions, the way an
 * uploader and an administrator take it: the file is posted to the API,
 * configured, validated and sent to the queue, the worker validates it in
 * detail, an administrator approves it and the worker imports it. Both kinds
 * of interactions go the whole way, also through a failure and back.
 *
 * The worker is run by hand where the queue would run it, so every step sees
 * the record as it is between two jobs. RDKit answers with the SMILES it was
 * given, which keeps the stored structures valid molecules: on PostgreSQL
 * they go through the Bingo index like in production.
 */

beforeEach(function () {
    fakePublicStorageForTests();
    fakeUploadChemistryServices(invalidSmiles: ['not-a-smiles'], canonicalPrefix: '');
    config()->set('services.turnstile.enabled', false);

    Queue::fake([ProcessUploadQueueRecord::class]);
});

// The last compound is the one whose insert failed the upload in issue #17.
const WORKFLOW_PASSIVE_CSV = "Name,SMILES,Gpen,+/- Gpen,LogK,Temperature\n".
    "Ethanol,CCO,-12.345,0.2,1.5,25\n".
    "Benzene,c1ccccc1,-8.1,,0.9,25\n".
    "Azinphos-ethyl,CCOP(=S)(OCC)SCn1nnc2ccccc2c1=O,-3.3,0.1,2.25,37\n";

const WORKFLOW_PASSIVE_COLUMNS = ['name', 'smiles', 'g_pen', 'g_pen_acc', 'logk', 'temperature'];

const WORKFLOW_ACTIVE_CSV = "Name,SMILES,Target,Protein,Type,IC50,+/- IC50\n".
    "Ethanol,CCO,P08183,P-glycoprotein 1,Inhibitor,0.5,0.1\n".
    "Benzene,c1ccccc1,P08183,P-glycoprotein 1,Non-inhibitor,12.25,\n".
    "Azinphos-ethyl,CCOP(=S)(OCC)SCn1nnc2ccccc2c1=O,Q9UNQ0,BCRP,,3.75,0.25\n";

const WORKFLOW_ACTIVE_COLUMNS = ['name', 'smiles', 'active_target', 'protein_name', 'interaction_type', 'Ic50', 'ic50_acc'];

const WORKFLOW_SMILES = ['CCO', 'CCOP(=S)(OCC)SCn1nnc2ccccc2c1=O', 'c1ccccc1'];

/**
 * Posts the file through the laboratory form and returns the record it made.
 */
function submitUpload(User $uploader, int $type, string $csv): UploadQueue
{
    $publication = createApiPublication(['identifier' => '4001', 'identifier_source' => 'MED']);

    $response = test()->actingAs($uploader)
        ->post(apiRoutePath('api/lab/upload'), [
            'dataset_type' => $type,
            'dataset_name' => 'Workflow dataset',
            'method_id' => $type === Dataset::TYPE_PASSIVE ? createApiMethod()->id : null,
            'membrane_id' => $type === Dataset::TYPE_PASSIVE ? createApiMembrane()->id : null,
            'publication_pmid' => '4001',
            'turnstile_token' => 'test-token',
            'file' => UploadedFile::fake()->createWithContent('interactions.csv', $csv),
        ])
        ->assertCreated()
        ->assertJsonPath('data.publication_id', $publication->id);

    return UploadQueue::query()->findOrFail($response->json('data.upload_queue_id'));
}

/**
 * Saves the column mapping and sends the record to the queue.
 *
 * @param  array<int, string>  $columns
 */
function configureAndEnqueueUpload(User $uploader, UploadQueue $record, array $columns): void
{
    test()->actingAs($uploader)
        ->postJson(apiRoutePath("api/lab/upload/{$record->id}/configure/validate"), [
            'separator' => ',',
            'skip_first_row' => 1,
            'attributes' => $columns,
        ])
        ->assertOk()
        ->assertJsonPath('data.config.quick_validation_ok', true);

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_CONFIGURED);

    test()->actingAs($uploader)
        ->postJson(apiRoutePath("api/lab/upload/{$record->id}/enqueue"))
        ->assertOk()
        ->assertJsonPath('data.state', UploadQueue::STATE_PENDING);
}

/**
 * Runs the job the queue holds for the record, the way a worker would.
 */
function runUploadWorker(UploadQueue $record): ProcessUploadQueueRecord
{
    Queue::assertPushed(ProcessUploadQueueRecord::class, fn (ProcessUploadQueueRecord $job): bool => $job->uniqueId() === (string) $record->id);

    $job = (new ProcessUploadQueueRecord($record->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

/**
 * The state of the record as its uploader sees it in the list of uploads.
 */
function uploadStateShownToUploader(User $uploader, UploadQueue $record): mixed
{
    $uploads = test()->actingAs($uploader)
        ->getJson(apiRoutePath('api/lab/upload/my-uploads'))
        ->assertOk()
        ->json('data');

    return collect($uploads)->firstWhere('id', $record->id)['state'] ?? null;
}

/**
 * From the form to the administrators' review.
 *
 * @param  array<int, string>  $columns
 */
function takeUploadToReview(User $uploader, int $type, string $csv, array $columns): UploadQueue
{
    $record = submitUpload($uploader, $type, $csv);

    expect($record->state)->toBe(UploadQueue::STATE_UPLOADED);

    test()->actingAs($uploader)
        ->getJson(apiRoutePath("api/lab/upload/{$record->id}/configure/preview").'?separator=,&skip_first_row=1')
        ->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.preview_rows.0.0', 'Ethanol');

    configureAndEnqueueUpload($uploader, $record, $columns);
    runUploadWorker($record);

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_REVIEW_REQUIRED)
        ->and($record->config->detailedValidationPassed())->toBeTrue();

    return $record;
}

function approveUploadAndImport(UploadQueue $record): ProcessUploadQueueRecord
{
    $record->refresh()->approveAdminReview(User::factory()->create()->id);

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_PENDING);

    return runUploadWorker($record);
}

/**
 * @return array<int, string>
 */
function importedSmiles(UploadQueue $record): array
{
    $model = (int) $record->type === UploadQueue::TYPE_ACTIVE_DATASET ? InteractionActive::class : InteractionPassive::class;

    return $model::query()
        ->where('dataset_id', $record->dataset_id)
        ->with('structure')
        ->get()
        ->pluck('structure.canonical_smiles')
        ->sort()
        ->values()
        ->all();
}

function adminDigestEvents(UploadQueue $record): array
{
    return QueuedNotification::query()
        ->where('group_key', LabUploadAdminDigestQueue::GROUP)
        ->where('notifiable_id', $record->id)
        ->orderBy('id')
        ->pluck('event')
        ->all();
}

/**
 * Makes the insert of the compound fail like a broken structure index does,
 * until the returned switch is turned off.
 */
function failStructureInsertOf(string $smiles): object
{
    $switch = new stdClass;
    $switch->on = true;

    Structure::creating(function (Structure $structure) use ($smiles, $switch): void {
        if ($switch->on && $structure->canonical_smiles === $smiles) {
            throw new QueryException(
                'pgsql',
                'insert into "structures" ("canonical_smiles") values (?) returning "id"',
                [$smiles],
                new PDOException('SQLSTATE[XX000]: Internal error: 7 ERROR:  error: bingo buffer: internal error: corrupted block 32365 data len is -8'),
            );
        }
    });

    return $switch;
}

dataset('upload kinds', [
    'passive interactions' => [Dataset::TYPE_PASSIVE, WORKFLOW_PASSIVE_CSV, WORKFLOW_PASSIVE_COLUMNS],
    'active interactions' => [Dataset::TYPE_ACTIVE, WORKFLOW_ACTIVE_CSV, WORKFLOW_ACTIVE_COLUMNS],
]);

test('an upload goes from the form through the review to the imported interactions', function (int $type, string $csv, array $columns) {
    $uploader = User::factory()->create();

    $record = takeUploadToReview($uploader, $type, $csv, $columns);

    // Nothing is stored before an administrator has seen the data.
    expect(Structure::query()->count())->toBe(0)
        ->and(Protein::query()->count())->toBe(0)
        ->and(importedSmiles($record))->toBe([])
        ->and(uploadStateShownToUploader($uploader, $record))->toBe(UploadQueue::STATE_REVIEW_REQUIRED)
        ->and(adminDigestEvents($record))->toBe([
            LabUploadAdminDigestQueue::EVENT_NEW_SUBMISSION,
            LabUploadAdminDigestQueue::EVENT_REVIEW_REQUIRED,
        ]);

    approveUploadAndImport($record);

    $record->refresh();

    expect($record->state)->toBe(UploadQueue::STATE_DONE)
        ->and($record->config->processingProgress())->toBe([])
        ->and(uploadStateShownToUploader($uploader, $record))->toBe(UploadQueue::STATE_DONE)
        ->and(importedSmiles($record))->toBe(WORKFLOW_SMILES)
        ->and(Structure::query()->count())->toBe(3)
        ->and(Structure::query()->whereNull('identifier')->count())->toBe(0);

    $names = Identifier::query()
        ->where('type', Identifier::TYPE_NAME)
        ->where('source_type', Dataset::class)
        ->where('source_id', $record->dataset_id)
        ->pluck('value')
        ->sort()
        ->values()
        ->all();

    expect($names)->toBe(['Azinphos-ethyl', 'Benzene', 'Ethanol']);

    $logged = $record->logs->pluck('message')->implode("\n");

    expect($logged)
        ->toContain('Upload request created from frontend laboratory form.')
        ->toContain('Quick frontend validation passed and configuration was saved.')
        ->toContain('Record was sent to pending upload queue by user.')
        ->toContain('Detailed validation passed. Record is ready for import.')
        ->toContain('Automatic validation passed. Upload data is waiting for administrator review.')
        ->toContain('Upload data was approved by administrator and returned to processing queue.')
        ->toContain('All interactions successfully imported.');
})->with('upload kinds');

test('imported passive interactions carry the values of their rows', function () {
    $uploader = User::factory()->create();
    $record = takeUploadToReview($uploader, Dataset::TYPE_PASSIVE, WORKFLOW_PASSIVE_CSV, WORKFLOW_PASSIVE_COLUMNS);
    approveUploadAndImport($record);

    $interactions = InteractionPassive::query()->where('dataset_id', $record->dataset_id)->with('structure')->get();
    $ethanol = $interactions->firstWhere('structure.canonical_smiles', 'CCO');
    $benzene = $interactions->firstWhere('structure.canonical_smiles', 'c1ccccc1');

    expect($interactions)->toHaveCount(3)
        ->and((float) $ethanol->gpen)->toBe(-12.35)
        ->and((float) $ethanol->gpen_accuracy)->toBe(0.2)
        ->and((float) $ethanol->logk)->toBe(1.5)
        ->and((float) $ethanol->temperature)->toBe(25.0)
        ->and($benzene->gpen_accuracy)->toBeNull()
        ->and($interactions->pluck('publication_id')->unique()->all())->toBe([$record->config['source_publication_id']]);
});

test('imported active interactions carry their values, proteins and categories', function () {
    $uploader = User::factory()->create();
    $inhibitor = Category::query()
        ->where('type', Category::TYPE_ACTIVE_INTERACTION)
        ->where('title', 'Inhibitor')
        ->firstOrFail();
    $known = createApiProtein(['uniprot_id' => 'P08183']);

    $record = takeUploadToReview($uploader, Dataset::TYPE_ACTIVE, WORKFLOW_ACTIVE_CSV, WORKFLOW_ACTIVE_COLUMNS);
    approveUploadAndImport($record);

    $interactions = InteractionActive::query()->where('dataset_id', $record->dataset_id)->with('structure')->get();
    $ethanol = $interactions->firstWhere('structure.canonical_smiles', 'CCO');
    $azinphos = $interactions->firstWhere('structure.canonical_smiles', 'CCOP(=S)(OCC)SCn1nnc2ccccc2c1=O');
    $created = Protein::query()->where('uniprot_id', 'Q9UNQ0')->sole();

    expect($interactions)->toHaveCount(3)
        ->and(Protein::query()->count())->toBe(2)
        ->and($ethanol->protein_id)->toBe($known->id)
        ->and($ethanol->category_id)->toBe($inhibitor->id)
        ->and((float) $ethanol->ic50)->toBe(0.5)
        ->and((float) $ethanol->ic50_accuracy)->toBe(0.1)
        ->and($azinphos->protein_id)->toBe($created->id)
        ->and((float) $azinphos->ic50)->toBe(3.75)
        ->and((float) $azinphos->ic50_accuracy)->toBe(0.25)
        ->and(Category::query()->findOrFail($azinphos->category_id)->title)->toBe('Unassigned')
        ->and(ProteinIdentifier::query()
            ->where('type', ProteinIdentifier::TYPE_NAME)
            ->orderBy('value')
            ->pluck('value')
            ->all())->toBe(['BCRP', 'P-glycoprotein 1']);
});

test('a database error while storing a structure fails the upload, which then finishes on a second run', function (int $type, string $csv, array $columns) {
    $uploader = User::factory()->create();
    $record = takeUploadToReview($uploader, $type, $csv, $columns);
    $brokenIndex = failStructureInsertOf('CCOP(=S)(OCC)SCn1nnc2ccccc2c1=O');

    $job = approveUploadAndImport($record);

    $record->refresh();
    $job->assertFailed();

    // The rows before the failing one are in, the failing one left nothing behind.
    expect($record->state)->toBe(UploadQueue::STATE_ERROR)
        ->and(importedSmiles($record))->toBe(['CCO', 'c1ccccc1'])
        ->and(Structure::query()->where('canonical_smiles', 'CCOP(=S)(OCC)SCn1nnc2ccccc2c1=O')->exists())->toBeFalse()
        ->and(uploadStateShownToUploader($uploader, $record))->toBe(UploadQueue::STATE_ERROR);

    // The uploader is told what happened without the SQL, the administrators get it all.
    $shownToUploader = json_encode(test()->actingAs($uploader)->getJson(apiRoutePath('api/lab/upload/my-uploads'))->json('data'));
    $digest = QueuedNotification::query()
        ->where('group_key', LabUploadAdminDigestQueue::GROUP)
        ->where('event', LabUploadAdminDigestQueue::EVENT_PROCESSING_ERROR)
        ->where('notifiable_id', $record->id)
        ->sole();

    expect($shownToUploader)
        ->toContain('database error')
        ->not->toContain('bingo')
        ->not->toContain('insert into')
        ->and($digest->data['message'])->toContain('bingo buffer');

    // Once the database is repaired, the same file is validated and sent again.
    $brokenIndex->on = false;

    configureAndEnqueueUpload($uploader, $record, $columns);
    runUploadWorker($record);

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_REVIEW_REQUIRED)
        ->and($record->config->adminReviewApproved())->toBeFalse();

    approveUploadAndImport($record);

    $model = $type === Dataset::TYPE_ACTIVE ? InteractionActive::class : InteractionPassive::class;

    // The rows of the first run are not imported twice.
    expect($record->refresh()->state)->toBe(UploadQueue::STATE_DONE)
        ->and(importedSmiles($record))->toBe(WORKFLOW_SMILES)
        ->and($model::query()->where('dataset_id', $record->dataset_id)->count())->toBe(3)
        ->and(Structure::query()->count())->toBe(3);
})->with('upload kinds');

test('a file that fails the detailed validation is replaced by a corrected one and imported', function (int $type, string $csv, array $columns) {
    $uploader = User::factory()->create();
    $brokenCsv = str_replace('c1ccccc1', 'not-a-smiles', $csv);
    $record = submitUpload($uploader, $type, $brokenCsv);

    // The quick validation of the form reads the first rows only; here it is
    // the worker that meets the row the chemistry service rejects.
    $record->config = $record->config->merge([
        'separator' => ',',
        'skip_first_row' => 1,
        'attributes' => $columns,
        'quick_validation_ok' => true,
    ]);
    $record->state = UploadQueue::STATE_CONFIGURED;
    $record->save();

    test()->actingAs($uploader)
        ->postJson(apiRoutePath("api/lab/upload/{$record->id}/enqueue"))
        ->assertOk();

    try {
        runUploadWorker($record);
    } catch (RuntimeException) {
        // A sync queue lets the failure of the job through.
    }

    $record->refresh();

    expect($record->state)->toBe(UploadQueue::STATE_ERROR)
        ->and($record->logs->pluck('message')->implode("\n"))->toContain('Line 3')
        ->and(Structure::query()->count())->toBe(0);

    test()->actingAs($uploader)
        ->post(apiRoutePath("api/lab/upload/{$record->id}/reupload"), [
            'file' => UploadedFile::fake()->createWithContent('corrected.csv', $csv),
        ])
        ->assertOk()
        ->assertJsonPath('data.state', UploadQueue::STATE_UPLOADED);

    configureAndEnqueueUpload($uploader, $record, $columns);
    runUploadWorker($record);
    approveUploadAndImport($record);

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_DONE)
        ->and(importedSmiles($record))->toBe(WORKFLOW_SMILES);
})->with('upload kinds');

test('an upload the administrators reject can be corrected and sent again', function (int $type, string $csv, array $columns) {
    $uploader = User::factory()->create();
    $record = takeUploadToReview($uploader, $type, $csv, $columns);

    $record->rejectAdminReview('The values are in the wrong units.', User::factory()->create()->id);

    $record->refresh();

    expect($record->state)->toBe(UploadQueue::STATE_ERROR)
        ->and($record->logs->pluck('message')->implode("\n"))->toContain('The values are in the wrong units.')
        ->and(importedSmiles($record))->toBe([]);

    test()->actingAs($uploader)
        ->post(apiRoutePath("api/lab/upload/{$record->id}/reupload"), [
            'file' => UploadedFile::fake()->createWithContent('corrected.csv', $csv),
        ])
        ->assertInvalid(['file']);

    // The file that was turned down is not accepted a second time.
    $corrected = str_replace('25', '30', $csv);

    test()->actingAs($uploader)
        ->post(apiRoutePath("api/lab/upload/{$record->id}/reupload"), [
            'file' => UploadedFile::fake()->createWithContent('corrected.csv', $corrected),
        ])
        ->assertOk();

    configureAndEnqueueUpload($uploader, $record, $columns);
    runUploadWorker($record);

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_REVIEW_REQUIRED)
        ->and($record->config->adminReviewApproved())->toBeFalse();

    approveUploadAndImport($record);

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_DONE)
        ->and(importedSmiles($record))->toBe(WORKFLOW_SMILES);
})->with('upload kinds');

test('a reverted import can be sent through the queue again', function (int $type, string $csv, array $columns) {
    $uploader = User::factory()->create();
    $record = takeUploadToReview($uploader, $type, $csv, $columns);
    approveUploadAndImport($record);

    test()->actingAs(User::factory()->create());
    $record->refresh()->revert();

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_CONFIGURED)
        ->and(importedSmiles($record))->toBe([])
        ->and(Identifier::query()->where('source_type', Dataset::class)->where('source_id', $record->dataset_id)->count())->toBe(0)
        ->and(Structure::query()->count())->toBe(3);

    test()->actingAs($uploader)
        ->postJson(apiRoutePath("api/lab/upload/{$record->id}/enqueue"))
        ->assertOk();
    runUploadWorker($record);

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_REVIEW_REQUIRED);

    approveUploadAndImport($record);

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_DONE)
        ->and(importedSmiles($record))->toBe(WORKFLOW_SMILES)
        ->and(Structure::query()->count())->toBe(3);
})->with('upload kinds');

test('an uploader takes a waiting upload back to change its configuration', function (int $type, string $csv, array $columns) {
    $uploader = User::factory()->create();
    $record = takeUploadToReview($uploader, $type, $csv, $columns);

    test()->actingAs($uploader)
        ->postJson(apiRoutePath("api/lab/upload/{$record->id}/revert"))
        ->assertOk()
        ->assertJsonPath('data.state', UploadQueue::STATE_CONFIGURED);

    configureAndEnqueueUpload($uploader, $record, $columns);
    runUploadWorker($record);
    approveUploadAndImport($record);

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_DONE)
        ->and(importedSmiles($record))->toBe(WORKFLOW_SMILES);
})->with('upload kinds');

test('the structures of an upload are found by a structure search', function (int $type, string $csv, array $columns) {
    $uploader = User::factory()->create();
    $record = takeUploadToReview($uploader, $type, $csv, $columns);
    approveUploadAndImport($record);

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_DONE)
        ->and(Structure::query()->containingSubstructure('P=S')->pluck('canonical_smiles')->all())
        ->toBe(['CCOP(=S)(OCC)SCn1nnc2ccccc2c1=O'])
        ->and(Structure::query()->exactMolecule('OCC')->pluck('canonical_smiles')->all())->toBe(['CCO'])
        ->and(Structure::query()->containingSubstructure('c1ccccc1')->count())->toBe(2);
})->with('upload kinds')->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The structure search needs PostgreSQL with Bingo.');
