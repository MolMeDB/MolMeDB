<?php

require_once __DIR__.'/api_test_helpers.php';

use App\Jobs\ProcessUploadQueueRecord;
use App\Models\Category;
use App\Models\Dataset;
use App\Models\File;
use App\Models\InteractionActive;
use App\Models\Protein;
use App\Models\ProteinIdentifier;
use App\Models\UploadQueue;
use App\Models\User;
use App\Rules\UploadFile\ActiveInteractions\ColumnInteractionType;
use App\Rules\UploadFile\ActiveInteractions\ColumnProteinName;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    prepareApiEndpointTestEnvironment();
    fakePublicStorageForTests();

    Http::fake([
        'https://rest.uniprot.org/uniprotkb/*' => fn ($request) => Http::response([
            'primaryAccession' => basename(parse_url($request->url(), PHP_URL_PATH)),
        ], 200),
    ]);
});

afterEach(function () {
    resetApiRouteCdkDepictState();
    resetApiRouteRdkitState();
});

/**
 * @return array<int, string>
 */
function interactionTypeErrors(mixed $value): array
{
    $errors = [];
    ColumnInteractionType::make()->validate_fast('interaction_type', $value, function (string $message) use (&$errors): void {
        $errors[] = $message;
    });

    return $errors;
}

/**
 * Stores an active upload with the given CSV content in the given state, linked to a publication like the upload form does.
 */
function createActiveUploadRecord(User $user, string $csv, int $state): UploadQueue
{
    $dataset = createApiDataset(['owner' => $user, 'type' => Dataset::TYPE_ACTIVE]);
    $path = 'upload_queue/active/'.uniqid('active_', true).'.csv';

    Storage::disk('public')->put($path, $csv);

    $file = File::query()->create([
        'path' => $path,
        'name' => basename($path),
        'type' => File::TYPE_UPLOAD_ACTIVE,
        'storage' => 'public',
        'mime' => 'text/csv',
        'hash' => md5($csv),
    ]);

    return UploadQueue::query()->create([
        'type' => Dataset::TYPE_ACTIVE,
        'state' => $state,
        'file_id' => $file->id,
        'dataset_id' => $dataset->id,
        'user_id' => $user->id,
        'config' => ['source_publication_id' => createApiPublication()->id],
    ]);
}

/**
 * Runs a configured active upload through the whole pipeline: quick validation via API,
 * detailed validation, admin approval and import.
 *
 * @param  array<int, string>  $attributes
 */
function importActiveUpload(string $csv, array $attributes): UploadQueue
{
    $user = User::factory()->create();
    $record = createActiveUploadRecord($user, $csv, UploadQueue::STATE_UPLOADED);

    test()->actingAs($user)
        ->postJson(apiRoutePath("api/lab/upload/{$record->id}/configure/validate"), [
            'separator' => ';',
            'skip_first_row' => 1,
            'attributes' => $attributes,
        ])
        ->assertOk();

    test()->actingAs($user)
        ->postJson(apiRoutePath("api/lab/upload/{$record->id}/enqueue"))
        ->assertOk();

    $record->refresh();
    expect($record->state)->toBe(UploadQueue::STATE_REVIEW_REQUIRED);

    // The sync queue would run the import inside approveAdminReview() before it finishes
    // writing its own log entry, so the job is caught here and run once approval is saved.
    Queue::fake();
    $record->approveAdminReview();
    Queue::assertPushed(ProcessUploadQueueRecord::class);

    app()->call([new ProcessUploadQueueRecord($record->id), 'handle']);

    return $record->refresh();
}

/**
 * @return array<int, string>
 */
function proteinNames(string $uniprotId): array
{
    return ProteinIdentifier::query()
        ->whereRelation('protein', 'uniprot_id', $uniprotId)
        ->where('type', ProteinIdentifier::TYPE_NAME)
        ->orderBy('id')
        ->pluck('value')
        ->all();
}

function activeCategoryTitle(InteractionActive $interaction): string
{
    return Category::query()->findOrFail($interaction->category_id)->title;
}

test('interaction type accepts every category title regardless of case, spaces and hyphens', function (string $value) {
    expect(interactionTypeErrors($value))->toBe([]);
})->with([
    'Inhibitor',
    'non inhibitor',
    ' NON-INHIBITOR ',
    'noninhibitor',
    'Substrate + Noninhibitor',
    'substrate+non-inhibitor',
    'Non-substrate + inhibitor',
    'non substrate + non inhibitor',
    'N/A',
    'agonist',
]);

test('interaction type rejects unknown values and the former misspelled titles', function (string $value) {
    expect(interactionTypeErrors($value))->toBe(["Column Interaction type contains unknown interaction type: {$value}."]);
})->with(['blocker', 'Nonsubstate + inhibitor', 'Nonsubtrate + noninhibitor', 'NA']);

test('interaction type leaves empty values to the default category', function (mixed $value) {
    expect(interactionTypeErrors($value))->toBe([]);
})->with(['', '   ', null]);

test('interaction type resolves the category id of a title', function () {
    $category = Category::query()
        ->where('type', Category::TYPE_ACTIVE_INTERACTION)
        ->where('title', 'Non-substrate + non-inhibitor')
        ->firstOrFail();

    expect(ColumnInteractionType::make()->categoryId('nonsubstrate+noninhibitor'))->toBe($category->id)
        ->and(ColumnInteractionType::make()->categoryId('unknown'))->toBeNull();
});

test('migrations store the active interaction categories with corrected spelling', function () {
    $titles = Category::query()->where('type', Category::TYPE_ACTIVE_INTERACTION)->pluck('title');

    expect($titles)->toContain('Non-substrate + inhibitor', 'Non-substrate + non-inhibitor')
        ->not->toContain('Nonsubstate + inhibitor', 'Nonsubtrate + noninhibitor');
});

test('protein name accepts up to 255 characters', function () {
    $errors = [];
    $collect = function (string $message) use (&$errors): void {
        $errors[] = $message;
    };

    ColumnProteinName::make()->validate_fast('protein_name', str_repeat('a', 255), $collect);
    expect($errors)->toBe([]);

    ColumnProteinName::make()->validate_fast('protein_name', str_repeat('a', 256), $collect);
    expect($errors)->toBe(['Column Protein name must be a string with a maximum of 255 characters.']);
});

test('configure step offers interaction type and protein name for active uploads', function () {
    $user = User::factory()->create();
    $record = createActiveUploadRecord($user, "SMILES;Target\nCCO;P00533\n", UploadQueue::STATE_UPLOADED);

    $this->actingAs($user)
        ->getJson(apiRoutePath("api/lab/upload/{$record->id}/configure/preview").'?separator=;&skip_first_row=1')
        ->assertOk()
        ->assertJsonPath('data.column_type_options.interaction_type', 'Interaction type')
        ->assertJsonPath('data.column_type_options.protein_name', 'Protein name');
});

test('quick validation reports unknown interaction types with their line numbers', function () {
    $user = User::factory()->create();
    $record = createActiveUploadRecord(
        $user,
        "SMILES;Target;Type;Ki\nCCO;P00533;Inhibitor;1.5\nCCN;P00533;blocker;2.5\nCCC;P00533;;3.5\n",
        UploadQueue::STATE_UPLOADED,
    );

    $this->actingAs($user)
        ->postJson(apiRoutePath("api/lab/upload/{$record->id}/configure/validate"), [
            'separator' => ';',
            'skip_first_row' => 1,
            'attributes' => ['smiles', 'active_target', 'interaction_type', 'ki'],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.config', ['Line 3: Column Interaction type contains unknown interaction type: blocker.']);

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_UPLOADED);
});

test('quick validation rejects protein names longer than 255 characters', function () {
    $user = User::factory()->create();
    $record = createActiveUploadRecord(
        $user,
        "SMILES;Target;Protein;Ki\nCCO;P00533;".str_repeat('a', 256).";1.5\n",
        UploadQueue::STATE_UPLOADED,
    );

    $this->actingAs($user)
        ->postJson(apiRoutePath("api/lab/upload/{$record->id}/configure/validate"), [
            'separator' => ';',
            'skip_first_row' => 1,
            'attributes' => ['smiles', 'active_target', 'protein_name', 'ki'],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.config', ['Line 2: Column Protein name must be a string with a maximum of 255 characters.']);
});

test('imported active interactions take their category from the interaction type column', function () {
    $record = importActiveUpload(
        "SMILES;Charge;Target;Type;Ki\n".
        "CCO;0;P00533;Inhibitor;1.5\n".
        "CCO;1;P00533;non inhibitor;2.5\n".
        "CCO;2;P00533;NON-SUBSTRATE + INHIBITOR;3.5\n".
        "CCO;3;P00533;;4.5\n",
        ['smiles', 'charge', 'active_target', 'interaction_type', 'ki'],
    );

    expect($record->state)->toBe(UploadQueue::STATE_DONE);

    $titles = InteractionActive::query()
        ->where('dataset_id', $record->dataset_id)
        ->orderBy('charge')
        ->get()
        ->map(fn (InteractionActive $interaction): string => activeCategoryTitle($interaction))
        ->all();

    expect($titles)->toBe(['Inhibitor', 'Non-inhibitor', 'Non-substrate + inhibitor', 'Unassigned']);
});

test('active uploads without the new columns import into the default category', function () {
    $record = importActiveUpload(
        "SMILES;Target;Ki\nCCO;P00533;1.5\n",
        ['smiles', 'active_target', 'ki'],
    );

    $interaction = InteractionActive::query()->where('dataset_id', $record->dataset_id)->sole();

    expect($record->state)->toBe(UploadQueue::STATE_DONE)
        ->and(activeCategoryTitle($interaction))->toBe('Unassigned')
        ->and(proteinNames('P00533'))->toBe([]);
});

test('imported protein names are added to new and existing proteins without duplicates', function () {
    $existing = Protein::factory()->create(['uniprot_id' => 'P00519']);
    ProteinIdentifier::query()->create([
        'protein_id' => $existing->id,
        'value' => 'ABL1',
        'type' => ProteinIdentifier::TYPE_NAME,
        'state' => ProteinIdentifier::STATE_VALIDATED,
    ]);

    $record = importActiveUpload(
        "SMILES;Charge;Target;Protein;Ki\n".
        "CCO;0;P00533;EGFR;1.5\n".
        "CCO;1;P00533;Epidermal growth factor receptor;2.5\n".
        "CCO;2;P00533;egfr;3.5\n".
        "CCO;3;P00519;abl1;4.5\n".
        "CCO;4;P00519;Tyrosine-protein kinase ABL1;5.5\n".
        "CCO;5;P00519;;6.5\n",
        ['smiles', 'charge', 'active_target', 'protein_name', 'ki'],
    );

    expect($record->state)->toBe(UploadQueue::STATE_DONE)
        ->and(Protein::query()->count())->toBe(2)
        ->and(proteinNames('P00533'))->toBe(['EGFR', 'Epidermal growth factor receptor'])
        ->and(proteinNames('P00519'))->toBe(['ABL1', 'Tyrosine-protein kinase ABL1']);

    $added = ProteinIdentifier::query()->where('value', 'Tyrosine-protein kinase ABL1')->sole();

    expect($added->state)->toBe(ProteinIdentifier::STATE_NEW)
        ->and($added->source_type)->toBe(Dataset::class)
        ->and($added->source_id)->toBe($record->dataset_id);
});

test('protein names are not stored before the upload is approved', function () {
    $user = User::factory()->create();
    $record = createActiveUploadRecord($user, "SMILES;Target;Protein;Ki\nCCO;P00533;EGFR;1.5\n", UploadQueue::STATE_UPLOADED);

    $this->actingAs($user)
        ->postJson(apiRoutePath("api/lab/upload/{$record->id}/configure/validate"), [
            'separator' => ';',
            'skip_first_row' => 1,
            'attributes' => ['smiles', 'active_target', 'protein_name', 'ki'],
        ])
        ->assertOk();

    $this->actingAs($user)
        ->postJson(apiRoutePath("api/lab/upload/{$record->id}/enqueue"))
        ->assertOk();

    expect($record->refresh()->state)->toBe(UploadQueue::STATE_REVIEW_REQUIRED)
        ->and(Protein::query()->count())->toBe(0)
        ->and(ProteinIdentifier::query()->count())->toBe(0);
});
