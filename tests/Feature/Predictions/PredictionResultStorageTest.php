<?php

require_once __DIR__.'/remote_prediction_test_helpers.php';

use App\Models\Filesystem;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\PredictionWorkers\Enums\RemotePredictionArtifact;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionFile;
use Modules\PredictionWorkers\Models\PredictionResult;

/*
 * Downloading what a finished remote calculation produced: the COSMO
 * result (plain XML or zipped), the conformer COSMO files stored next to
 * it, and the other artifacts.
 */

beforeEach(function () {
    enableRemotePredictionService();
});

/**
 * @return array<string, mixed>
 */
function completedCalculationSnapshot(string $calculationId = 'calc-1'): array
{
    return [
        'id' => 'mol-1',
        'status' => 'completed',
        'calculations' => [['id' => $calculationId, 'molecule_id' => 'mol-1', 'status' => 'completed']],
    ];
}

/**
 * A completed prediction whose result download answers with the given body.
 *
 * @param  array<string, string>  $headers
 */
function completedPredictionDownloading(string $body, array $headers = ['Content-Type' => 'application/xml']): Prediction
{
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-1', 'remote_status' => 'completed']);
    fakePredictionHttpRoutes([
        'https://predictor.test/jobs/status' => Http::response(completedCalculationSnapshot()),
        'https://predictor.test/calculations/calc-1/download' => Http::response($body, 200, $headers),
    ]);

    return $prediction;
}

/**
 * A finished prediction with its result file stored on the predictions disk.
 *
 * @return array{prediction: Prediction, disk: string, folder: string}
 */
function finishedPredictionWithStoredResult(): array
{
    $disk = fakePredictionStorageDisk();
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), [
        'remote_calculation_id' => 'calc-1',
        'remote_status' => 'completed',
        'state' => Prediction::STATE_FINISHED,
    ]);
    // Real folders look like "{structure}/perm_DOPC_25,0"; hasStoredCosmoFiles()
    // escapes "_" with a backslash, which LIKE honours by default on
    // PostgreSQL (production) but not on the SQLite test connection.
    $folder = "{$prediction->structure_id}/perm-dopc-25,0";
    Storage::disk($disk)->put("{$folder}/cosmo.xml", cosmoResultXml());
    $file = PredictionFile::query()->create([
        'type' => PredictionFile::TYPE_RESULT_COSMO_XML,
        'name' => 'cosmo.xml',
        'mime' => 'application/xml',
        'storage' => $disk,
        'path' => "{$folder}/cosmo.xml",
    ]);
    $result = PredictionResult::query()->create(['file_id' => $file->id, 'data' => []]);
    $prediction->forceFill(['result_id' => $result->id])->save();

    return ['prediction' => $prediction->refresh(), 'disk' => $disk, 'folder' => $folder];
}

test('a zipped result is parsed from the COSMO XML inside the archive', function () {
    $disk = fakePredictionStorageDisk();
    $prediction = completedPredictionDownloading(
        predictionZip(['calc/readme.txt' => 'notes', 'calc/other.xml' => '<other/>', 'calc/cosmo.xml' => cosmoResultXml(-5.25)]),
        ['Content-Type' => 'application/zip', 'Content-Disposition' => "attachment; filename*=UTF-8''result%20archive.zip"],
    );

    $result = $prediction->storeRemotePredictionResult();
    $file = $result->file;

    expect($file->type)->toBe(PredictionFile::TYPE_RESULT_ARCHIVE)
        ->and($file->name)->toBe('result_archive.zip')
        ->and($file->mime)->toBe('application/zip')
        ->and($file->hash)->toBe(md5(Storage::disk($disk)->get($file->path)))
        ->and($result->data[0]['solutes'][0]['logPerm'])->toBe(-5.25)
        ->and($result->data[0]['temperature'])->toEqual(25.0)
        ->and($prediction->refresh()->step)->toBe(Prediction::STEP_RESULT_PARSE)
        ->and(collect($prediction->logs)->last()['type'])->toBe('RESULT DOWNLOAD');
});

test('a result without a file name is stored as cosmo.xml in the method, membrane and temperature folder', function () {
    $disk = fakePredictionStorageDisk();
    $prediction = completedPredictionDownloading(cosmoResultXml());

    $result = $prediction->storeRemotePredictionResult();
    $abbreviation = $prediction->predictionMembrane->abbreviation;

    expect($result->file->path)->toBe("{$prediction->structure_id}/perm_{$abbreviation}_25,0/cosmo.xml")
        ->and($result->file->type)->toBe(PredictionFile::TYPE_RESULT_COSMO_XML);
    Storage::disk($disk)->assertExists($result->file->path);
});

test('a result that cannot be parsed is not recorded', function (string $body, string $message) {
    fakePredictionStorageDisk();
    $prediction = completedPredictionDownloading($body);

    expect(fn () => $prediction->storeRemotePredictionResult())->toThrow(RuntimeException::class, $message);

    expect($prediction->refresh()->result_id)->toBeNull()
        ->and($prediction->state)->toBe(Prediction::STATE_RUNNING)
        ->and(PredictionResult::query()->count())->toBe(0)
        ->and(PredictionFile::query()->count())->toBe(0);
})->with([
    'not XML' => fn () => ['Internal error', 'could not be parsed'],
    'archive without COSMO XML' => fn () => [predictionZip(['notes.xml' => '<notes/>']), 'could not be parsed'],
    'archive with broken COSMO XML' => fn () => [predictionZip(['cosmo.xml' => '<micoutput><broken></micoutput>']), 'archive XML could not be parsed'],
]);

test('a stored result is returned without downloading it again', function () {
    ['prediction' => $prediction] = finishedPredictionWithStoredResult();
    Http::fake();

    expect($prediction->storeRemotePredictionResult()->id)->toBe($prediction->result_id);
    Http::assertNothingSent();
});

test('a result is not downloaded without a configured predictions disk', function () {
    $prediction = completedPredictionDownloading(cosmoResultXml());
    $diskName = Filesystem::query()->where('type', Filesystem::TYPE_PREDICTIONS_STORAGE)->firstOrFail()->systemName;
    config()->set('filesystems.disks', collect(config('filesystems.disks'))->except($diskName)->all());

    expect(fn () => $prediction->storeRemotePredictionResult())->toThrow(RuntimeException::class, 'is not initialized');
    expect($prediction->refresh()->result_id)->toBeNull();
});

test('a result is not downloaded without a predictions filesystem', function () {
    $prediction = completedPredictionDownloading(cosmoResultXml());
    Filesystem::query()->where('type', Filesystem::TYPE_PREDICTIONS_STORAGE)->delete();

    expect(fn () => $prediction->storeRemotePredictionResult())->toThrow(RuntimeException::class, 'filesystem is not configured');
});

test('a result download needs the calculation to exist remotely', function () {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-gone', 'remote_status' => 'completed']);
    fakePredictionHttpRoutes(['https://predictor.test/jobs/status' => Http::response(['id' => 'mol-1', 'status' => 'completed', 'calculations' => []])]);

    expect(fn () => $prediction->downloadRemotePredictionResult())->toThrow(RuntimeException::class, 'calculation for prediction');
});

test('calculation artifacts are downloaded with their file name', function (string $method, RemotePredictionArtifact $artifact) {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-1', 'remote_status' => 'completed']);
    fakePredictionHttpRoutes([
        'https://predictor.test/jobs/status' => Http::response(completedCalculationSnapshot()),
        "https://predictor.test/calculations/calc-1/download/{$artifact->value}" => Http::response('artifact', 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => "attachment; filename=\"ethanol_{$artifact->value}.zip\"",
        ]),
    ]);

    $file = $prediction->{$method}();

    expect($file->contents)->toBe('artifact')
        ->and($file->filename)->toBe("ethanol_{$artifact->value}.zip")
        ->and($file->mimeType)->toBe('application/octet-stream')
        ->and($file->size())->toBe(8);
})->with([
    'conformers' => ['downloadRemotePredictionConformers', RemotePredictionArtifact::CONFORMERS],
    'SDF' => ['downloadRemotePredictionSdf', RemotePredictionArtifact::SDF],
    'COSMO archive' => ['downloadRemotePredictionCosmoArchive', RemotePredictionArtifact::COSMO],
]);

test('conformer COSMO files are extracted next to the stored result once', function () {
    ['prediction' => $prediction, 'disk' => $disk, 'folder' => $folder] = finishedPredictionWithStoredResult();
    fakePredictionHttpRoutes([
        'https://predictor.test/calculations/calc-1/download/cosmo' => Http::response(predictionZip([
            'calc/conformers/c001/ethanol_c001.ccf' => 'conformer 1',
            'calc/conformers/c000/ethanol_c000.ccf' => 'conformer 0',
            'calc/conformers/c000/mol.cosmo' => 'cosmo 0',
            'calc/conformers/c001/mol.cosmo' => 'cosmo 1',
            'calc/conformers/c000/weird name!.ccf' => 'weird',
            'calc/job.log' => 'log',
        ]), 200, ['Content-Type' => 'application/zip']),
    ]);

    expect($prediction->storeRemotePredictionCosmoFiles())->toBe(5);

    $stored = PredictionFile::query()->where('type', PredictionFile::TYPE_COSMO_CONFORMERS)->orderBy('id')->get();
    expect($stored->pluck('name')->all())->toBe([
        'calc_conformers_c001_mol.cosmo',
        'ethanol_c000.ccf',
        'ethanol_c001.ccf',
        'mol.cosmo',
        'weird_name_.ccf',
    ])
        ->and($stored->firstWhere('name', 'ethanol_c000.ccf')->hash)->toBe(md5('conformer 0'))
        ->and($stored->firstWhere('name', 'ethanol_c000.ccf')->path)->toBe("{$folder}/ethanol_c000.ccf")
        ->and(Storage::disk($disk)->get("{$folder}/calc_conformers_c001_mol.cosmo"))->toBe('cosmo 1')
        ->and(Storage::disk($disk)->get("{$folder}/mol.cosmo"))->toBe('cosmo 0')
        ->and(Storage::disk($disk)->exists("{$folder}/job.log"))->toBeFalse()
        ->and(collect($prediction->refresh()->logs)->last()['payload']['source'])->toBe('remote prediction service')
        ->and($prediction->hasStoredCosmoFiles())->toBeTrue();

    expect($prediction->storeRemotePredictionCosmoFiles())->toBe(0);
    Http::assertSentCount(1);
});

test('a COSMO artifact without conformer files is refused', function (callable $body, string $message) {
    ['prediction' => $prediction] = finishedPredictionWithStoredResult();
    fakePredictionHttpRoutes(['https://predictor.test/calculations/calc-1/download/cosmo' => Http::response($body(), 200)]);

    expect(fn () => $prediction->storeRemotePredictionCosmoFiles())->toThrow(RuntimeException::class, $message);
    expect($prediction->hasStoredCosmoFiles())->toBeFalse();
})->with([
    'not a zip' => [fn (): string => 'plain text', 'is not a valid zip archive'],
    'no COSMO files' => [fn (): string => predictionZip(['calc/job.log' => 'log', 'calc/result.xml' => '<x/>']), 'contains no .ccf/.cosmo files [calc/job.log, calc/result.xml]'],
]);

test('COSMO files are only stored for a finished prediction with a result', function () {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-1']);

    expect(fn () => $prediction->storeCosmoFiles(['a.ccf' => 'a'], 'test'))->toThrow(RuntimeException::class, 'has no finished result');
});

test('existing COSMO files are kept when identical and never overwritten', function () {
    ['prediction' => $prediction, 'disk' => $disk, 'folder' => $folder] = finishedPredictionWithStoredResult();
    Storage::disk($disk)->put("{$folder}/a.ccf", 'original');

    expect($prediction->storeCosmoFiles(['a.ccf' => 'original', 'b.ccf' => 'new'], 'manual upload'))->toBe(2)
        ->and(Storage::disk($disk)->get("{$folder}/b.ccf"))->toBe('new');

    expect(fn () => $prediction->storeCosmoFiles(['a.ccf' => 'changed', 'c.ccf' => 'other'], 'manual upload'))
        ->toThrow(RuntimeException::class, 'already exists with different contents');

    expect(Storage::disk($disk)->get("{$folder}/a.ccf"))->toBe('original')
        ->and(Storage::disk($disk)->exists("{$folder}/c.ccf"))->toBeFalse()
        ->and(PredictionFile::query()->where('type', PredictionFile::TYPE_COSMO_CONFORMERS)->count())->toBe(2);
});

test('only the last hundred worker events are kept in the logs', function () {
    $prediction = (new Prediction)->forceFill(['logs' => array_fill(0, 100, ['message' => 'old'])]);

    $logs = $prediction->logsWithWorkerEvent('Newest.', ['n' => 1], 'TEST', 'error');

    expect($logs)->toHaveCount(100)
        ->and(end($logs))->toMatchArray(['type' => 'TEST', 'context' => 'error', 'message' => 'Newest.', 'payload' => ['n' => 1]])
        ->and(reset($logs))->toBe(['message' => 'old']);
});

test('the result download request is the one the service expects', function () {
    fakePredictionStorageDisk();
    $prediction = completedPredictionDownloading(cosmoResultXml());

    $prediction->storeRemotePredictionResult();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://predictor.test/calculations/calc-1/download'
        && $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer valid-token'));
});
