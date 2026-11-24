<?php

require_once __DIR__.'/remote_prediction_test_helpers.php';

use App\Models\File;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\PredictionWorkers\DTO\PredictionResultJson;
use Modules\PredictionWorkers\Exceptions\RemotePredictionException;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionDataset;
use Modules\PredictionWorkers\Models\PredictionFile;
use Modules\PredictionWorkers\Models\PredictionMembrane;
use Modules\PredictionWorkers\Models\PredictionResult;
use Modules\PredictionWorkers\Models\PredictionStructure;

/*
 * The prediction models' own behaviour: state labels and progress, dataset
 * progress and priorities, stored files and results, structures and the
 * membrane definition the remote service needs.
 */

beforeEach(function () {
    enableRemotePredictionService();
});

/**
 * Adds a prediction of a new structure to the dataset.
 *
 * @param  array<string, mixed>  $attributes
 */
function addPredictionToDataset(PredictionDataset $dataset, array $attributes): Prediction
{
    $prediction = Prediction::query()->create([
        'structure_id' => PredictionStructure::query()->create(['canonical_smiles' => 'C'.Str::random(6)])->id,
        'state' => Prediction::STATE_RUNNING,
        'step' => Prediction::STEP_COSMO,
        'temperature' => 25.0,
        'membrane_id' => $dataset->membrane_id,
        'method_type' => 'cosmoperm',
        ...$attributes,
    ]);
    $dataset->predictions()->attach($prediction->id);

    return $prediction;
}

function storedPredictionFile(string $disk, string $path, string $contents, array $attributes = []): PredictionFile
{
    Storage::disk($disk)->put($path, $contents);

    return PredictionFile::query()->create(['storage' => $disk, 'path' => $path, ...$attributes]);
}

test('prediction states, steps and priorities have labels', function () {
    expect(Prediction::enumState(Prediction::STATE_RUNNING))->toBe('Running')
        ->and(Prediction::enumState(99))->toBe('N/A')
        ->and(Prediction::enumStep(Prediction::STEP_COSMO))->toBe('COSMO prediction')
        ->and(Prediction::enumStep(99))->toBe('N/A')
        ->and(Prediction::enumPriority(Prediction::PRIORITY_HIGH))->toBe('High')
        ->and(Prediction::enumPriority(99))->toBe('N/A')
        ->and(Prediction::enumRemoteStatus(null))->toBeNull()
        ->and(Prediction::enumRemoteStatus('waiting_for_script'))->toBe('Waiting for sources')
        ->and(Prediction::enumRemoteStatus('archived'))->toBe('archived')
        ->and(Prediction::enumMethod('cosmoperm'))->toBe('CosmoPerm')
        ->and(Prediction::enumMethod('unknown-method'))->toBe('unknown-method')
        ->and(Prediction::methodShortKey('cosmoperm'))->toBe('perm')
        ->and(Prediction::methodShortKey('Unknown Method'))->toBe('unknown-method')
        ->and(PredictionDataset::method('cosmoperm'))->toBe('CosmoPerm')
        ->and(PredictionDataset::enum_priority(Prediction::PRIORITY_LOW))->toBe('Low')
        ->and(PredictionDataset::enumState(PredictionDataset::STATE_FINISHED_WITH_ERRORS))->toBe('Finished with errors')
        ->and(PredictionDataset::enumState(99))->toBe('N/A');
});

test('the effective state shows a paused prediction as paused whatever its state', function (int $state, bool $paused, string $expected) {
    $prediction = (new Prediction)->forceFill(['state' => $state, 'remote_paused_at' => $paused ? now() : null, 'remote_status' => 'running']);

    expect($prediction->effectiveState())->toBe($expected)
        ->and($prediction->effectiveRemoteStatus())->toBe($paused ? 'paused' : 'running')
        ->and($prediction->effectiveRemoteStatusLabel())->toBe($paused ? 'Paused' : 'Running');
})->with([
    'stopped' => [Prediction::STATE_STOPPED, false, 'stopped'],
    'prepared' => [Prediction::STATE_PREPARED, false, 'prepared'],
    'error' => [Prediction::STATE_ERROR, false, 'error'],
    'remove' => [Prediction::STATE_REMOVE, false, 'remove'],
    'running' => [Prediction::STATE_RUNNING, false, 'running'],
    'finished' => [Prediction::STATE_FINISHED, false, 'finished'],
    'unknown' => [42, false, 'unknown'],
    'paused while running' => [Prediction::STATE_RUNNING, true, 'paused'],
]);

test('progress counts steps up to the stored result', function (?int $step, mixed $resultId, int $value, int $percent) {
    expect(Prediction::progressStepValue($step, null, $resultId))->toBe($value)
        ->and(Prediction::progressPercent($step, null, $resultId))->toBe($percent);
})->with([
    'no step' => [null, null, 0, 0],
    'negative step' => [-1, null, 0, 0],
    'optimization' => [Prediction::STEP_OPTIMIZATION, null, 3, 43],
    'beyond the final step' => [12, null, 7, 100],
    'stored result' => [Prediction::STEP_PENDING, 5, 7, 100],
]);

test('remote method mappings fail loudly only where a mapping is required', function () {
    $unmapped = (new Prediction)->forceFill(['method_type' => 'unknown-method']);

    expect(Prediction::hasRemotePredictionMethod(null))->toBeFalse()
        ->and(Prediction::hasRemotePredictionMethod('cosmoperm'))->toBeTrue()
        ->and(Prediction::remoteMethodKeyFor(null))->toBeNull()
        ->and(Prediction::remoteMethodKeyFor('unknown-method'))->toBeNull()
        ->and(Prediction::remoteMethodKeyFor('cosmoperm'))->toBe('cosmoperm')
        ->and(Prediction::enabledPredictionMethodOptions())->toHaveKey('cosmoperm')
        ->and(fn () => $unmapped->remotePredictionMethod())->toThrow(RuntimeException::class, 'has no remote prediction mapping');
});

test('dataset progress counts each prediction once in its phase', function () {
    ['dataset' => $dataset] = createPredictionDataset(User::factory()->create(), ['state' => Prediction::STATE_PREPARED, 'step' => Prediction::STEP_PENDING]);
    addPredictionToDataset($dataset, ['state' => Prediction::STATE_RUNNING, 'step' => Prediction::STEP_COSMO]);
    addPredictionToDataset($dataset, ['state' => Prediction::STATE_RUNNING, 'step' => Prediction::STEP_OPTIMIZATION, 'remote_paused_at' => now()]);
    addPredictionToDataset($dataset, ['state' => Prediction::STATE_ERROR, 'step' => Prediction::STEP_COSMO]);
    addPredictionToDataset($dataset, ['state' => Prediction::STATE_FINISHED, 'step' => Prediction::STEP_RESULT_DB_STORE]);

    expect($dataset->calculateProgressStats())->toBe([
        'state' => PredictionDataset::STATE_RUNNING,
        'enum_state' => 'In progress',
        'stats' => ['pending' => 1, 'running' => 1, 'paused' => 1, 'done' => 1, 'failed' => 1, 'total' => 5],
        'overall_stats' => [
            // 0 + 4 + 3 + 4 + 7 of 5 × 7 steps
            'progress_percent' => 51,
            'completed_percent' => 40,
            'steps_done' => 18,
            'steps_total' => 35,
        ],
    ]);
});

test('the dataset state follows the counts of its predictions', function (array $counts, int $expected) {
    expect(PredictionDataset::resolveState(...$counts))->toBe($expected);
})->with([
    'empty' => [[0, 0, 0, 0, 0], PredictionDataset::STATE_EMPTY],
    'all failed' => [[0, 0, 0, 2, 2], PredictionDataset::STATE_FAILED],
    'all done' => [[0, 0, 2, 0, 2], PredictionDataset::STATE_FINISHED],
    'done with failures' => [[0, 0, 1, 1, 2], PredictionDataset::STATE_FINISHED_WITH_ERRORS],
    'running' => [[1, 1, 0, 0, 2], PredictionDataset::STATE_RUNNING],
    'one done, one pending' => [[1, 0, 1, 0, 2], PredictionDataset::STATE_RUNNING],
    'only pending' => [[2, 0, 0, 0, 2], PredictionDataset::STATE_PENDING],
    'unclassified predictions' => [[0, 0, 0, 0, 2], PredictionDataset::STATE_EMPTY],
]);

test('cached dataset progress is served until it is forgotten', function () {
    ['dataset' => $dataset, 'prediction' => $prediction] = createPredictionDataset(User::factory()->create());

    expect($dataset->cachedProgressStats()['stats']['running'])->toBe(1);

    $prediction->forceFill(['state' => Prediction::STATE_ERROR])->save();

    expect($dataset->cachedProgressStats()['stats']['running'])->toBe(1)
        ->and($dataset->forgetProgressStatsCache())->toBeTrue()
        ->and($dataset->cachedProgressStats()['state'])->toBe(PredictionDataset::STATE_FAILED);
});

test('a dataset priority change gives shared predictions the highest priority of their datasets', function () {
    ['dataset' => $first, 'prediction' => $prediction] = createPredictionDataset(User::factory()->create());
    $second = PredictionDataset::query()->create([
        'token' => Str::random(32),
        'temperature' => 25.0,
        'membrane_id' => $first->membrane_id,
        'method_type' => 'cosmoperm',
        'priority' => Prediction::PRIORITY_MEDIUM,
    ]);
    $second->predictions()->attach($prediction->id);
    $other = addPredictionToDataset($second, ['priority' => Prediction::PRIORITY_MEDIUM]);

    $first->update(['priority' => Prediction::PRIORITY_HIGH]);
    expect($prediction->refresh()->priority)->toBe(Prediction::PRIORITY_HIGH)
        ->and($other->refresh()->priority)->toBe(Prediction::PRIORITY_MEDIUM);

    $first->update(['priority' => Prediction::PRIORITY_LOW]);
    expect($prediction->refresh()->priority)->toBe(Prediction::PRIORITY_MEDIUM);

    $second->update(['priority' => Prediction::PRIORITY_LOW]);
    expect($prediction->refresh()->priority)->toBe(Prediction::PRIORITY_LOW)
        ->and($other->refresh()->priority)->toBe(Prediction::PRIORITY_LOW);
});

test('a dataset reaches the MolMeDB membrane through its prediction membrane', function () {
    ['dataset' => $dataset, 'membrane' => $membrane] = createPredictionDataset(User::factory()->create());

    expect($dataset->membrane()->first()?->id)->toBe($membrane->remote_id)
        ->and($membrane->predictionDatasets()->pluck('id')->all())->toBe([$dataset->id])
        ->and($membrane->predictions()->count())->toBe(1);
});

test('a prediction file detects its type and hash from the stored contents', function () {
    Storage::fake('predictions-test');
    Storage::fake('private');

    $xml = storedPredictionFile('predictions-test', 'r/cosmo.xml', '<?xml version="1.0"?><micoutput/>');
    $text = storedPredictionFile('private', 'm/dopc.mic', 'membrane definition');

    expect($xml->mime)->toBe('text/xml')
        ->and($xml->hash)->toBe(md5('<?xml version="1.0"?><micoutput/>'))
        ->and($text->mime)->toBe('text/plain')
        ->and($text->hash)->toBe(md5('membrane definition'));
});

test('a prediction file without a stored file cannot be recorded without its type', function () {
    Storage::fake('predictions-test');

    expect(fn () => PredictionFile::query()->create(['storage' => 'predictions-test', 'path' => 'missing.xml']))
        ->toThrow(Exception::class, 'File does not exist.');
    expect(PredictionFile::query()->count())->toBe(0);
});

test('a prediction file is named after its path when it has no name', function (?string $name, string $path, string $expected) {
    $file = (new PredictionFile)->forceFill(['name' => $name, 'path' => $path]);

    expect($file->name())->toBe($expected)
        ->and($file->downloadName())->toBe($expected);
})->with([
    'name with extension' => ['result.xml', 'a/b.xml', 'result.xml'],
    'name without extension' => ['result', 'a/b.xml', 'result.xml'],
    'no name' => [null, 'a/cosmo.xml', 'cosmo.xml'],
]);

test('a prediction file exists only when its stored file does', function () {
    Storage::fake('predictions-test');
    $file = storedPredictionFile('predictions-test', 'r/cosmo.xml', 'x', ['mime' => 'text/plain']);

    expect($file->exists())->toBeTrue();

    Storage::disk('predictions-test')->delete('r/cosmo.xml');

    expect($file->exists())->toBeFalse()
        ->and((new PredictionFile)->forceFill(['storage' => null, 'path' => 'r/cosmo.xml'])->exists())->toBeFalse();
});

test('a result serves its stored data, or parses its file when it has none', function () {
    Storage::fake('predictions-test');
    $file = storedPredictionFile('predictions-test', 'r/cosmo.xml', cosmoResultXml(-3.5), ['mime' => 'application/xml']);
    $broken = storedPredictionFile('predictions-test', 'r/broken.xml', '<broken/>', ['mime' => 'application/xml']);

    $stored = PredictionResult::query()->create(['file_id' => $file->id, 'data' => [['solutes' => []]]]);
    $unparsed = PredictionResult::query()->create(['file_id' => $file->id]);
    $withoutFile = PredictionResult::query()->create([]);
    $unreadable = PredictionResult::query()->create(['file_id' => $broken->id]);

    $parsed = $unparsed->loadParsedResults();

    expect($stored->loadParsedResults())->toBe([['solutes' => []]])
        ->and($parsed)->toBeInstanceOf(PredictionResultJson::class)
        ->and($parsed->jsonSerialize()[0]['solutes'][0]['logPerm'])->toBe(-3.5)
        ->and($withoutFile->loadParsedResults())->toBeNull()
        ->and($unreadable->loadParsedResults())->toBeFalse();
});

test('a result belongs to the prediction that produced it', function () {
    ['prediction' => $prediction] = createPredictionDataset(User::factory()->create());
    $result = PredictionResult::query()->create([]);
    $prediction->forceFill(['result_id' => $result->id])->save();

    expect($result->prediction->id)->toBe($prediction->id);
});

test('a structure without SMILES cannot be sent to the remote service', function () {
    $structure = PredictionStructure::query()->create(['canonical_smiles' => '  ']);
    Http::fake();

    expect(fn () => $structure->remotePredictionStatus())->toThrow(RuntimeException::class, 'has no canonical SMILES');
    Http::assertNothingSent();
});

test('a structure reads its remote status and molecule archive by its SMILES', function () {
    ['structure' => $structure] = createPredictionDataset(User::factory()->create());
    $smiles = $structure->canonical_smiles;
    fakePredictionHttpRoutes([
        'https://predictor.test/jobs/status' => Http::response(['id' => 'mol-1', 'canonical_smiles' => $smiles, 'status' => 'running']),
        'https://predictor.test/molecules/download' => Http::response('zip', 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="molecule.zip"']),
    ]);

    $snapshot = $structure->remotePredictionStatus(5);
    $archive = $structure->downloadRemotePredictionMolecule();

    expect($snapshot->canonicalSmiles)->toBe($smiles)
        ->and($archive->filename)->toBe('molecule.zip')
        ->and($structure->predictions()->count())->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://predictor.test/jobs/status'
        && $request->data() === ['smiles' => $smiles, 'events_limit' => 5]);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://predictor.test/molecules/download'
        && $request['smiles'] === $smiles);
});

test('a membrane without a definition file uses the preferred or derived key without asking the service', function () {
    $membrane = PredictionMembrane::query()->create(['remote_id' => 990001, 'name' => 'Egg PC', 'abbreviation' => 'EggPC/Chol 30%']);
    $unnamed = PredictionMembrane::query()->create(['remote_id' => 990002, 'name' => '', 'abbreviation' => '']);
    $named = PredictionMembrane::query()->create(['remote_id' => 990003, 'name' => 'Skin Model', 'abbreviation' => '']);
    Http::fake();

    expect($membrane->ensureRemotePredictionKey())->toBe('eggpcchol-30')
        ->and($membrane->ensureRemotePredictionKey('preferred'))->toBe('preferred')
        ->and($unnamed->defaultRemotePredictionKey())->toBe("membrane-{$unnamed->id}")
        ->and($named->defaultRemotePredictionKey())->toBe('skin-model')
        ->and($membrane->hasRemotePredictionDefinitionFile())->toBeFalse()
        ->and(fn () => $membrane->remotePredictionContent())->toThrow(RuntimeException::class, 'has no definition file');
    Http::assertNothingSent();
});

test('the definition file of the MolMeDB membrane wins over the legacy prediction file', function () {
    Storage::fake('public');
    Storage::fake('predictions-test');
    ['membrane' => $membrane] = createPredictionDataset(User::factory()->create());
    Storage::disk('public')->put('membranes/dopc.mic', 'canonical definition');
    $canonical = File::query()->create(['type' => File::TYPE_COSMO_MEMBRANE, 'storage' => 'public', 'path' => 'membranes/dopc.mic', 'mime' => 'text/plain', 'hash' => md5('canonical definition'), 'name' => 'dopc.mic']);
    $membrane->membrane->files()->attach($canonical->id, ['model_type' => $membrane->membrane::class]);
    $legacy = storedPredictionFile('predictions-test', 'membranes/legacy.mic', 'legacy definition', ['mime' => 'text/plain']);
    $membrane->forceFill(['file_id' => $legacy->id])->save();
    $membrane->refresh();

    expect($membrane->remotePredictionContent())->toBe('canonical definition');

    Storage::disk('public')->delete('membranes/dopc.mic');

    expect($membrane->remotePredictionContent())->toBe('legacy definition');

    Storage::disk('predictions-test')->delete('membranes/legacy.mic');

    expect($membrane->hasRemotePredictionDefinitionFile())->toBeFalse()
        ->and(fn () => $membrane->remotePredictionContent())->toThrow(RuntimeException::class, 'public:membranes/dopc.mic, predictions-test:membranes/legacy.mic] do not exist');
});

/**
 * A prediction membrane with a stored legacy definition file.
 */
function membraneWithDefinition(string $contents = 'membrane definition'): PredictionMembrane
{
    Storage::fake('predictions-test');
    ['membrane' => $membrane] = createPredictionDataset(User::factory()->create());
    $file = storedPredictionFile('predictions-test', 'membranes/'.$membrane->id.'.mic', $contents, ['mime' => 'text/plain']);
    $membrane->forceFill(['file_id' => $file->id])->save();

    return $membrane->refresh();
}

test('a membrane already registered with the same contents reuses its remote key', function () {
    $membrane = membraneWithDefinition();
    fakePredictionHttpRoutes(['https://predictor.test/membranes' => Http::response(['membranes' => [
        ['key' => 'other', 'md5' => md5('something else'), 'file_valid' => true],
        ['key' => 'dopc-registered', 'md5' => md5('membrane definition'), 'file_valid' => true, 'created_at' => '2026-01-01T00:00:00+00:00'],
    ]])]);

    expect($membrane->ensureRemotePredictionKey('ignored'))->toBe('dopc-registered')
        ->and($membrane->findRemotePredictionKey())->toBe('dopc-registered')
        ->and($membrane->findRemotePredictionMembrane()->createdAt->toIso8601String())->toBe('2026-01-01T00:00:00+00:00');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

test('a registered membrane whose remote file failed validation is not used', function () {
    $membrane = membraneWithDefinition();
    fakePredictionHttpRoutes(['https://predictor.test/membranes' => Http::response(['membranes' => [
        ['key' => 'dopc-broken', 'md5' => md5('membrane definition'), 'file_valid' => false],
    ]])]);

    expect(fn () => $membrane->ensureRemotePredictionKey())->toThrow(RuntimeException::class, 'Remote prediction membrane [dopc-broken] exists, but its remote file failed validation.');
});

test('an unregistered membrane is uploaded under the preferred key', function () {
    $membrane = membraneWithDefinition();
    fakePredictionHttpRoutes([
        'https://predictor.test/membranes/' => Http::response(['key' => 'dopc-new', 'md5' => md5('membrane definition'), 'file_valid' => true]),
        'https://predictor.test/membranes' => Http::response(['membranes' => []]),
    ]);

    expect($membrane->ensureRemotePredictionKey('dopc new'))->toBe('dopc-new')
        ->and($membrane->findRemotePredictionKey())->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://predictor.test/membranes/dopc%20new'
        && $request->body() === 'membrane definition'
        && $request->hasHeader('Content-Type', 'text/plain'));
});

test('an upload refused as duplicate content uses the key the service already has', function () {
    $membrane = membraneWithDefinition();
    fakePredictionHttpRoutes([
        'https://predictor.test/membranes/' => Http::response(['detail' => [
            'code' => 'duplicate_membrane_content',
            'message' => 'Membrane content already registered.',
            'existing_key' => 'dopc-existing',
        ]], 409),
        'https://predictor.test/membranes' => Http::response(['membranes' => []]),
    ]);

    expect($membrane->ensureRemotePredictionKey())->toBe('dopc-existing');
});

test('any other upload failure is passed on', function () {
    $membrane = membraneWithDefinition();
    fakePredictionHttpRoutes([
        'https://predictor.test/membranes/' => Http::response(['detail' => ['code' => 'invalid_membrane', 'message' => 'Membrane file is not valid.']], 422),
        'https://predictor.test/membranes' => Http::response(['membranes' => []]),
    ]);

    expect(fn () => $membrane->ensureRemotePredictionKey())->toThrow(RemotePredictionException::class, 'Membrane file is not valid.');
});
