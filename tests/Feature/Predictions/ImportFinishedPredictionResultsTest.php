<?php

require_once __DIR__.'/prediction_helpers.php';
require_once __DIR__.'/../UploadQueue/upload_pipeline_helpers.php';

use App\Jobs\ImportFinishedPredictionResults;
use App\Models\Dataset;
use App\Models\InteractionPassive;
use App\Models\Structure;
use App\Models\User;
use App\Services\PredictionResultImporter;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionFile;
use Modules\PredictionWorkers\Models\PredictionMethod;
use Modules\PredictionWorkers\Models\PredictionResult;

/*
 * Finished predictions become passive interactions (LogPerm) in a monthly
 * internal COSMO dataset of the mapped method and membrane.
 */

beforeEach(function () {
    fakeUploadChemistryServices(invalidSmiles: ['CC(']);

    $this->method = createApiMethod(['abbreviation' => 'COSMOperm']);
    $this->publication = createApiPublication();

    PredictionMethod::query()->findOrFail('cosmoperm')->update([
        'remote_id' => $this->method->id,
        'primary_publication_id' => $this->publication->id,
    ]);
});

/**
 * A finished prediction whose parsed result holds the given LogPerm.
 */
function createFinishedPrediction(float $logPerm = -4.567, string $smiles = 'CCO', array $attributes = []): Prediction
{
    $file = PredictionFile::query()->create([
        'type' => PredictionFile::TYPE_RESULT_COSMO_XML,
        'name' => 'cosmo.xml',
        'mime' => 'application/xml',
        'storage' => 'predictions',
        'path' => 'results/'.uniqid().'/cosmo.xml',
        'hash' => md5(uniqid('', true)),
    ]);
    $result = PredictionResult::query()->create([
        'file_id' => $file->id,
        'data' => [['solutes' => [['logPerm' => $logPerm]]]],
    ]);

    ['prediction' => $prediction, 'structure' => $structure] = createPredictionDataset(User::factory()->create(), [
        'state' => Prediction::STATE_FINISHED,
        'step' => Prediction::STEP_RESULT_PARSE,
        'result_id' => $result->id,
        ...$attributes,
    ]);
    $structure->update(['canonical_smiles' => $smiles]);

    return $prediction->refresh();
}

function importPrediction(Prediction $prediction): string
{
    return app(PredictionResultImporter::class)->import($prediction, PredictionMethod::query()->find($prediction->method_type));
}

test('a finished prediction is imported as a passive interaction', function () {
    $prediction = createFinishedPrediction();

    expect(importPrediction($prediction))->toBe('imported');

    $interaction = InteractionPassive::query()->with(['dataset', 'structure'])->sole();
    $membraneId = $prediction->predictionMembrane->remote_id;

    expect((float) $interaction->logperm)->toBe(-4.57)
        ->and($interaction->publication_id)->toBe($this->publication->id)
        ->and($interaction->structure->canonical_smiles)->toBe('canon:CCO')
        ->and($interaction->dataset->type)->toBe(Dataset::TYPE_PASSIVE_INTERNAL_COSMO)
        ->and($interaction->dataset->method_id)->toBe($this->method->id)
        ->and($interaction->dataset->membrane_id)->toBe($membraneId)
        ->and($prediction->refresh()->step)->toBe(Prediction::STEP_RESULT_DB_STORE)
        ->and($prediction->predictionStructure->remote_id)->toBe($interaction->structure_id);
});

test('a second prediction of the same molecule, membrane and temperature keeps one interaction', function () {
    $prediction = createFinishedPrediction();
    importPrediction($prediction);

    // A separate prediction structure whose SMILES canonicalizes to the same
    // MolMeDB structure, as happens when the two canonicalizers disagree.
    $repeat = createFinishedPrediction(-1.0, 'CCO', [
        'membrane_id' => $prediction->membrane_id,
    ]);

    expect(importPrediction($repeat))->toBe('duplicate')
        ->and(InteractionPassive::query()->count())->toBe(1)
        ->and((float) InteractionPassive::query()->sole()->logperm)->toBe(-4.57)
        ->and($repeat->refresh()->step)->toBe(Prediction::STEP_RESULT_DB_STORE)
        ->and($prediction->predictionStructure->refresh()->remote_id)->toBe(InteractionPassive::query()->sole()->structure_id);
});

test('a prediction is not imported without the method mapping', function (array $methodAttributes, string $message) {
    PredictionMethod::query()->findOrFail('cosmoperm')->update($methodAttributes);
    $prediction = createFinishedPrediction();

    expect(importPrediction($prediction))->toBe('error')
        ->and(InteractionPassive::query()->count())->toBe(0)
        ->and($prediction->refresh()->step)->toBe(Prediction::STEP_RESULT_PARSE)
        ->and(collect($prediction->logs)->pluck('message')->implode(' '))->toContain($message);
})->with([
    'no MolMeDB method' => [['remote_id' => null], 'has no real Method mapped'],
    'no primary reference' => [['primary_publication_id' => null], 'has no primary reference configured'],
]);

test('a prediction is not imported while RDKit cannot canonicalize its SMILES', function () {
    $prediction = createFinishedPrediction(smiles: 'CC(');

    expect(fn () => importPrediction($prediction))->toThrow(RuntimeException::class, 'will be retried');

    expect(Structure::query()->where('canonical_smiles', 'CC(')->exists())->toBeFalse()
        ->and($prediction->refresh()->step)->toBe(Prediction::STEP_RESULT_PARSE);
});

test('the import job processes finished predictions and logs failures on them', function () {
    $good = createFinishedPrediction();
    $broken = createFinishedPrediction(smiles: 'CC(');

    (new ImportFinishedPredictionResults)->handle(app(PredictionResultImporter::class));

    expect($good->refresh()->step)->toBe(Prediction::STEP_RESULT_DB_STORE)
        ->and($broken->refresh()->step)->toBe(Prediction::STEP_RESULT_PARSE)
        ->and(collect($broken->logs)->last()['message'] ?? '')->toContain('Import failed: RDKit could not canonicalize');
});

test('failing imports rotate to the back of the batch instead of starving newer predictions', function () {
    config()->set('prediction-workers.remote.worker.result_import_batch_size', 1);
    $broken = createFinishedPrediction(smiles: 'CC(');
    $this->travel(1)->minute();
    $good = createFinishedPrediction();
    $this->travel(1)->minute();

    (new ImportFinishedPredictionResults)->handle(app(PredictionResultImporter::class));
    $this->travel(1)->minute();
    (new ImportFinishedPredictionResults)->handle(app(PredictionResultImporter::class));

    expect($broken->refresh()->step)->toBe(Prediction::STEP_RESULT_PARSE)
        ->and($good->refresh()->step)->toBe(Prediction::STEP_RESULT_DB_STORE);
});
