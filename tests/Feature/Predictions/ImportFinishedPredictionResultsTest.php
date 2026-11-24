<?php

require_once __DIR__.'/prediction_helpers.php';
require_once __DIR__.'/../UploadQueue/upload_pipeline_helpers.php';

use App\Jobs\ImportFinishedPredictionResults;
use App\Models\Dataset;
use App\Models\InteractionPassive;
use App\Models\Structure;
use App\Models\User;
use App\Services\PredictionResultImporter;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
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

test('a prediction whose result cannot be read is given up after a few attempts', function () {
    $prediction = createFinishedPrediction();
    $prediction->predictionResult->update(['data' => [['solutes' => [['logPerm' => null]]]]]);
    $job = fn () => (new ImportFinishedPredictionResults)->handle(app(PredictionResultImporter::class));

    foreach (range(1, PredictionResultImporter::MAX_UNUSABLE_RESULT_ATTEMPTS - 1) as $ignored) {
        $job();
    }

    expect($prediction->refresh()->state)->toBe(Prediction::STATE_FINISHED)
        ->and($prediction->logs)->toHaveCount(PredictionResultImporter::MAX_UNUSABLE_RESULT_ATTEMPTS - 1);

    $job();

    expect($prediction->refresh()->state)->toBe(Prediction::STATE_ERROR)
        ->and($prediction->step)->toBe(Prediction::STEP_RESULT_PARSE)
        ->and(collect($prediction->logs)->last()['message'])
        ->toBe('Could not extract LogPerm from the parsed result data. Given up after repeated attempts; the prediction is marked as failed.')
        ->and(InteractionPassive::query()->count())->toBe(0);

    // It is no longer picked up: nothing is added to its log.
    $job();

    expect($prediction->refresh()->logs)->toHaveCount(PredictionResultImporter::MAX_UNUSABLE_RESULT_ATTEMPTS);
});

test('a prediction that waits for a mapping is tried for as long as it takes', function () {
    PredictionMethod::query()->findOrFail('cosmoperm')->update(['remote_id' => null]);
    $prediction = createFinishedPrediction();

    foreach (range(1, PredictionResultImporter::MAX_UNUSABLE_RESULT_ATTEMPTS + 2) as $ignored) {
        importPrediction($prediction->refresh());
    }

    expect($prediction->refresh()->state)->toBe(Prediction::STATE_FINISHED);

    PredictionMethod::query()->findOrFail('cosmoperm')->update(['remote_id' => $this->method->id]);

    expect(importPrediction($prediction->refresh()))->toBe('imported');
});

test('a prediction that already failed many times is given up at once', function () {
    $prediction = createFinishedPrediction();
    $prediction->predictionResult->update(['data' => [['solutes' => [['logPerm' => null]]]]]);
    // As on production, where the same predictions were tried every minute for months.
    $logs = [['type' => 'WORKER', 'context' => 'success', 'message' => 'COSMO files stored.', 'payload' => [], 'timestamp' => now()->toIso8601String()]];
    foreach (range(1, 99) as $ignored) {
        $logs[] = ['type' => 'RESULT IMPORT', 'context' => 'error', 'message' => 'Could not extract LogPerm from the parsed result data.', 'payload' => [], 'timestamp' => now()->toIso8601String()];
    }
    $prediction->forceFill(['logs' => $logs])->save();

    expect(importPrediction($prediction->refresh()))->toBe('error')
        ->and($prediction->refresh()->state)->toBe(Prediction::STATE_ERROR);
});

test('a result whose file cannot be read is tried for as long as it takes, with the reason in the log', function () {
    Storage::fake('predictions');
    $prediction = createFinishedPrediction();
    // An older result: nothing parsed in the database, and its file is out of reach.
    $prediction->predictionResult->update(['data' => null]);

    foreach (range(1, PredictionResultImporter::MAX_UNUSABLE_RESULT_ATTEMPTS + 2) as $ignored) {
        expect(importPrediction($prediction->refresh()))->toBe('error');
    }

    expect($prediction->refresh()->state)->toBe(Prediction::STATE_FINISHED)
        ->and(collect($prediction->logs)->last()['message'])->toStartWith('The result file could not be read: COSMO XML file not found on disk [predictions]');
});

test('an error of the storage is tried for as long as it takes and stays out of the log of the prediction', function () {
    $prediction = createFinishedPrediction();
    $prediction->predictionResult->update(['data' => null]);
    Storage::shouldReceive('disk')->with('predictions')->andThrow(new RuntimeException('Unable to connect to host storage.internal'));
    Exceptions::fake();

    foreach (range(1, PredictionResultImporter::MAX_UNUSABLE_RESULT_ATTEMPTS + 2) as $ignored) {
        expect(importPrediction($prediction->refresh()))->toBe('error');
    }

    expect($prediction->refresh()->state)->toBe(Prediction::STATE_FINISHED)
        ->and(collect($prediction->logs)->last()['message'])->toBe('The result file could not be read: the storage reported an error.')
        ->and(json_encode($prediction->logs))->not->toContain('storage.internal');

    Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'storage.internal'));
});

test('a result file the storage read only in part is tried for as long as it takes', function () {
    $prediction = createFinishedPrediction();
    $prediction->predictionResult->update(['data' => null]);
    $disk = Mockery::mock(Illuminate\Contracts\Filesystem\Filesystem::class);
    $disk->shouldReceive('exists')->andReturn(true);
    $disk->shouldReceive('get')->andReturn('');
    $disk->shouldReceive('size')->andReturn(4096);
    Storage::shouldReceive('disk')->with('predictions')->andReturn($disk);

    foreach (range(1, PredictionResultImporter::MAX_UNUSABLE_RESULT_ATTEMPTS + 2) as $ignored) {
        expect(importPrediction($prediction->refresh()))->toBe('error');
    }

    expect($prediction->refresh()->state)->toBe(Prediction::STATE_FINISHED)
        ->and(collect($prediction->logs)->last()['message'])->toContain('read only in part (0 of 4096 bytes)');
});

test('a result without a file is tried for as long as it takes', function () {
    $prediction = createFinishedPrediction();
    $prediction->predictionResult->update(['data' => null, 'file_id' => null]);

    foreach (range(1, PredictionResultImporter::MAX_UNUSABLE_RESULT_ATTEMPTS + 2) as $ignored) {
        expect(importPrediction($prediction->refresh()))->toBe('error');
    }

    expect($prediction->refresh()->state)->toBe(Prediction::STATE_FINISHED)
        ->and(collect($prediction->logs)->last()['message'])->toBe('The parsed result data could not be loaded.');
});

test('a result file that is not a COSMO result is given up after a few attempts, with the reason in the log', function () {
    Storage::fake('predictions');
    $prediction = createFinishedPrediction();
    $prediction->predictionResult->update(['data' => null]);
    // What the remote service leaves behind when its calculation stops half way.
    Storage::disk('predictions')->put(
        $prediction->predictionResult->file->path,
        "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n\n<micoutput>\n  <joblist>\n\n",
    );

    foreach (range(1, PredictionResultImporter::MAX_UNUSABLE_RESULT_ATTEMPTS - 1) as $ignored) {
        expect(importPrediction($prediction->refresh()))->toBe('error');
    }

    expect($prediction->refresh()->state)->toBe(Prediction::STATE_FINISHED)
        ->and(collect($prediction->logs)->last()['message'])->toStartWith('The result file is not a valid COSMO result: The file is not well-formed XML (line ');

    expect(importPrediction($prediction->refresh()))->toBe('error');

    expect($prediction->refresh()->state)->toBe(Prediction::STATE_ERROR)
        ->and(collect($prediction->logs)->last()['message'])->toContain('not well-formed XML')->toContain('Given up after repeated attempts')
        ->and(InteractionPassive::query()->count())->toBe(0);
});

test('a result file read from the storage is imported when nothing parsed is stored', function () {
    Storage::fake('predictions');
    $prediction = createFinishedPrediction();
    $prediction->predictionResult->update(['data' => null]);
    Storage::disk('predictions')->put($prediction->predictionResult->file->path, <<<'XML'
        <micoutput><joblist><job><temperature>310.15</temperature><layerposition>0.0</layerposition><solutelist><solute>
        <meanposition>1.5</meanposition><logP_micelle_water>2.75</logP_micelle_water><logPerm_membrane_cm_s>-3.21</logPerm_membrane_cm_s><distribution>0.5</distribution>
        </solute></solutelist></job></joblist></micoutput>
        XML);

    expect(importPrediction($prediction->refresh()))->toBe('imported')
        ->and((float) InteractionPassive::query()->sole()->logperm)->toBe(-3.21);
});

test('a result stored as the archive of the calculation is unpacked and imported', function () {
    Storage::fake('predictions');
    $prediction = createFinishedPrediction();
    $prediction->predictionResult->update(['data' => null]);

    $path = tempnam(sys_get_temp_dir(), 'import-archive-test-');
    $archive = new ZipArchive;
    $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $archive->addFromString('calculation/cosmo.xml', <<<'XML'
        <micoutput><joblist><job><temperature>310.15</temperature><layerposition>0.0</layerposition><solutelist><solute>
        <meanposition>1.5</meanposition><logP_micelle_water>2.75</logP_micelle_water><logPerm_membrane_cm_s>-2.34</logPerm_membrane_cm_s><distribution>0.5</distribution>
        </solute></solutelist></job></joblist></micoutput>
        XML);
    $archive->close();
    Storage::disk('predictions')->put($prediction->predictionResult->file->path, file_get_contents($path));
    unlink($path);

    expect(importPrediction($prediction->refresh()))->toBe('imported')
        ->and((float) InteractionPassive::query()->sole()->logperm)->toBe(-2.34);
});

test('a prediction without a structure to import is given up after a few attempts', function () {
    $prediction = createFinishedPrediction(smiles: ' ');

    foreach (range(1, PredictionResultImporter::MAX_UNUSABLE_RESULT_ATTEMPTS) as $ignored) {
        importPrediction($prediction->refresh());
    }

    expect($prediction->refresh()->state)->toBe(Prediction::STATE_ERROR)
        ->and(collect($prediction->logs)->last()['message'])->toStartWith('Prediction structure has no canonical SMILES. Given up');
});
