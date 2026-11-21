<?php

use App\Rules\UploadFile\ActiveInteractions\ColumnTarget;
use App\Services\External\Chemical\Chebi\Chebi;
use App\Services\External\Chemical\Unichem\Unichem;
use App\Services\External\Protein\Uniprot;
use App\Services\UploadQueueExternalLookupCache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * The UniProt, ChEBI and UniChem clients against responses recorded from the
 * live services (tests/Fixtures/external), and how they behave when a
 * service answers with an error, with unexpected data or not at all.
 */

beforeEach(function () {
    config()->set('services.uniprot.base_api_url', 'https://uniprot.test/');
    config()->set('services.chebi.base_api_url', 'https://chebi.test/api/public');
    config()->set('services.unichem.base_api_url', 'https://unichem.test/api/v1/');
    Http::preventStrayRequests();
});

/**
 * @return array<string, mixed>
 */
function externalFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/external/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
}

function failingConnection(): Closure
{
    return fn () => throw new ConnectionException('Connection timed out');
}

// UniProt

test('UniProt returns the record of an existing accession', function () {
    Http::fake(['https://uniprot.test/uniprotkb/P08183*' => Http::response(externalFixture('uniprot-P08183'))]);

    $record = (new Uniprot)->getById('P08183');

    expect($record->id)->toBe('P08183')
        ->and($record->isActive())->toBeTrue()
        ->and($record->proteinDescription->getRecommendedName())->toBe('ATP-dependent translocase ABCB1')
        ->and($record->proteinDescription->getAlternativeNames())->toContain('P-glycoprotein 1', 'Multidrug resistance protein 1')
        ->and($record->proteinDescription->getRecommendedShortNames())->toBe([])
        ->and($record->proteinDescription->getRecommendedNameEvidences())->toHaveCount(1)
        ->and((new Uniprot)->existsById('P08183'))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://uniprot.test/uniprotkb/P08183?')
        && $request['fields'] === 'accession,protein_name');
});

test('a deleted UniProt entry does not count as an existing protein', function () {
    Http::fake(['https://uniprot.test/*' => Http::response(externalFixture('uniprot-inactive-Q00015'))]);

    $record = (new Uniprot)->getById('Q00015');

    expect($record->isActive())->toBeFalse()
        ->and($record->proteinDescription)->toBeNull()
        ->and((new Uniprot)->existsById('Q00015'))->toBeFalse();
});

test('UniProt has no record when the service answers with an error or not at all', function (Closure $response) {
    Http::fake(['https://uniprot.test/*' => $response()]);

    expect((new Uniprot)->getById('P08183'))->toBeNull()
        ->and((new Uniprot)->existsById('P08183'))->toBeFalse();
})->with([
    'unknown accession (404)' => [fn () => Http::response(externalFixture('uniprot-not-found'), 404)],
    'malformed accession (400)' => [fn () => Http::response(externalFixture('uniprot-invalid-accession'), 400)],
    'server error' => [fn () => Http::response('Bad gateway', 502)],
    'empty body' => [fn () => Http::response('', 200)],
    'not JSON' => [fn () => Http::response('<html>maintenance</html>', 200)],
    'no connection' => [fn () => failingConnection()],
]);

test('a UniProt record with missing parts is read without errors', function () {
    Http::fake(['https://uniprot.test/*' => Http::response([
        'primaryAccession' => 'P08183',
        'proteinDescription' => ['alternativeNames' => [['ecNumbers' => []]]],
        'comments' => [['texts' => [['value' => 'Function']]], 'unexpected'],
    ])]);

    $record = (new Uniprot)->getById('P08183');

    expect($record->entryType)->toBeNull()
        ->and($record->isActive())->toBeTrue()
        ->and($record->proteinDescription->getRecommendedName())->toBeNull()
        ->and($record->proteinDescription->getRecommendedNameEvidences())->toBe([])
        ->and($record->proteinDescription->getAlternativeNames())->toBe([])
        ->and($record->comments)->toHaveCount(1)
        ->and($record->comments[0]->commentType)->toBeNull();
});

test('a UniProt identifier cannot change the requested path', function () {
    Http::fake(['https://uniprot.test/*' => Http::response([], 404)]);

    (new Uniprot)->getById('../uniparc/search?query=x#');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://uniprot.test/uniprotkb/..%2Funiparc%2Fsearch%3Fquery%3Dx%23?fields='));
});

test('the target column accepts only complete UniProt accessions', function (string $value, bool $valid) {
    $failures = [];
    ColumnTarget::make()->validate_fast('target', $value, function (string $message) use (&$failures): void {
        $failures[] = $message;
    });

    expect($failures === [])->toBe($valid);
})->with([
    'six characters' => ['P08183', true],
    'O/P/Q with letters' => ['Q9Y6L6', true],
    'ten characters' => ['A0A024R161', true],
    'isoform' => ['P08183-2', true],
    'version' => ['P08183.3', true],
    'chain' => ['P08183#PRO_0000093332', true],
    'accession followed by a path' => ['P08183/../../uniparc', false],
    'accession followed by text' => ['A0A024R161 and more', false],
    'text before an accession' => ['x P08183', false],
    'comma instead of a letter' => ['A1,,,1', false],
    'lower case' => ['p08183', false],
    'too short' => ['P0818', false],
]);

// ChEBI

test('ChEBI returns the record of an existing compound', function () {
    Http::fake(['https://chebi.test/api/public/compound/15365*' => Http::response(externalFixture('chebi-compound-15365'))]);

    $record = (new Chebi)->getById('CHEBI:15365');

    expect($record->id)->toBe(15365)
        ->and($record->chebi_id)->toBe('CHEBI:15365')
        ->and($record->name)->toBe('Acetylsalicylic acid')
        ->and($record->chemical_data->formula)->toBe('C9H8O4')
        ->and($record->chemical_data->charge)->toBe('0')
        ->and($record->chemical_data->molecular_weight)->toBe(180.159)
        ->and($record->chemical_data->monoisotopic_mass)->toBe(180.04226)
        ->and($record->names->iupac_name)->toBe('2-(acetyloxy)benzoic acid')
        // Two of the three recorded synonyms differ only in letter case.
        ->and($record->names->synonyms)->toBe(['2-acetoxybenzenecarboxylic acid', '2-acetoxybenzoic acid', '2-(acetyloxy)benzoic acid']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://chebi.test/api/public/compound/15365?')
        && $request['only_ontology_parents'] === 'false'
        && $request['only_ontology_children'] === 'false');
});

test('ChEBI accepts the identifier with or without its prefix', function (string $identifier) {
    Http::fake(['https://chebi.test/api/public/compound/15365*' => Http::response(externalFixture('chebi-compound-15365'))]);

    expect((new Chebi)->existsById($identifier))->toBeTrue();
})->with(['CHEBI:15365', 'chebi:15365', '15365', ' CHEBI:15365 ']);

test('ChEBI has no record when the service answers with an error or not at all', function (Closure $response) {
    Http::fake(['https://chebi.test/*' => $response()]);

    expect((new Chebi)->getById('CHEBI:999999999'))->toBeNull()
        ->and((new Chebi)->existsById('CHEBI:999999999'))->toBeFalse();
})->with([
    'unknown compound (404)' => [fn () => Http::response(externalFixture('chebi-not-found'), 404)],
    'server error' => [fn () => Http::response('Service unavailable', 503)],
    'empty body' => [fn () => Http::response('', 200)],
    'a JSON string' => [fn () => Http::response('"maintenance"', 200)],
    'no connection' => [fn () => failingConnection()],
]);

test('ChEBI has no structure image when the service answers with an error or not at all', function (Closure $response) {
    Http::fake(['https://chebi.test/*' => $response()]);

    expect((new Chebi)->getStructureById('CHEBI:999999999'))->toBeNull();
})->with([
    'unknown compound (404)' => [fn () => Http::response(externalFixture('chebi-not-found'), 404)],
    'server error' => [fn () => Http::response('Service unavailable', 503)],
    'no connection' => [fn () => failingConnection()],
]);

test('a ChEBI record with missing parts is read without errors', function () {
    Http::fake(['https://chebi.test/*' => Http::response([
        'chebi_accession' => 'CHEBI:1',
        'names' => ['SYNONYM' => [['ascii_name' => null], ['name' => 'no ascii'], ['ascii_name' => 'Water']]],
        'chemical_data' => [],
    ])]);

    $record = (new Chebi)->getById('CHEBI:1');

    expect($record->id)->toBeNull()
        ->and($record->name)->toBeNull()
        ->and($record->chemical_data->formula)->toBeNull()
        ->and($record->chemical_data->molecular_weight)->toBeNull()
        ->and($record->names->iupac_name)->toBeNull()
        ->and($record->names->synonyms)->toBe(['Water']);
});

test('a ChEBI identifier cannot change the requested path', function () {
    Http::fake(['https://chebi.test/*' => Http::response([], 404)]);

    (new Chebi)->getById('1/../../admin?x=1');
    (new Chebi)->getStructureById('1/../../admin?x=1');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://chebi.test/api/public/compound/1%2F..%2F..%2Fadmin%3Fx%3D1?'));
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://chebi.test/api/public/compound/1%2F..%2F..%2Fadmin%3Fx%3D1/structure/?'));
});

test('the ChEBI structure image is requested without the prefix and with its size', function () {
    Http::fake(['https://chebi.test/*' => Http::response('<svg/>')]);

    expect((new Chebi)->getStructureById('CHEBI:15365', 200, 100))->toBe('<svg/>');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://chebi.test/api/public/compound/15365/structure/?width=200&height=100');
});

// UniChem

test('UniChem returns the compound of a source identifier with its known cross-references', function () {
    Http::fake(['https://unichem.test/api/v1/compounds' => Http::response(externalFixture('unichem-compound-chembl25'))]);

    $chemical = (new Unichem)->getChemicalBySourceId(Unichem::SOURCE_CHEMBL, 'CHEMBL25');

    expect($chemical->inchi)->toBe('InChI=1S/C9H8O4/c1-6(10)13-8-5-3-2-4-7(8)9(11)12/h2-5H,1H3,(H,11,12)')
        ->and($chemical->formula)->toBe('C9H8O4');

    // Only the sources MolMeDB works with are kept, the fixture holds two more.
    $identifiers = collect($chemical->identifiers)->mapWithKeys(fn ($identifier) => [$identifier->unichemSourceId => $identifier->compoundId]);

    expect($identifiers->all())->toBe([
        Unichem::SOURCE_CHEMBL => 'CHEMBL25',
        Unichem::SOURCE_DRUGBANK => 'DB00945',
        Unichem::SOURCE_RCSB_PDB => 'AIN',
        Unichem::SOURCE_CHEBI => 'CHEBI:15365',
        Unichem::SOURCE_PUBCHEM => '2244',
    ])->and($chemical->identifiers[0]->url)->toBe('https://www.ebi.ac.uk/chembldb/compound/inspect/CHEMBL25');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://unichem.test/api/v1/compounds'
        && $request->data() === ['type' => 'sourceID', 'compound' => 'CHEMBL25', 'sourceID' => Unichem::SOURCE_CHEMBL]);
});

test('UniChem has no compound when the service answers with an error or not at all', function (Closure $response) {
    Http::fake(['https://unichem.test/*' => $response()]);

    expect((new Unichem)->getChemicalBySourceId(Unichem::SOURCE_PUBCHEM, '999999999999'))->toBeNull()
        ->and(app(UploadQueueExternalLookupCache::class)->unichemIdentifierExists(Unichem::SOURCE_PUBCHEM, '999999999999'))->toBeFalse();
})->with([
    'unknown identifier' => [fn () => Http::response(externalFixture('unichem-not-found'))],
    'bad request' => [fn () => Http::response(['error' => 'bad request'], 400)],
    'server error' => [fn () => Http::response('Internal error', 500)],
    'empty body' => [fn () => Http::response('', 200)],
    'compounds is not a list' => [fn () => Http::response(['compounds' => 'none'])],
    'no connection' => [fn () => failingConnection()],
]);

test('a UniChem compound without an InChI or sources is read without errors', function () {
    Http::fake(['https://unichem.test/*' => Http::response(['compounds' => [['uci' => 1, 'sources' => [['id' => 1], 'unexpected']]]])]);

    $chemical = (new Unichem)->getChemicalBySourceId(Unichem::SOURCE_CHEMBL, 'CHEMBL1');

    expect($chemical->inchi)->toBeNull()
        ->and($chemical->identifiers)->toHaveCount(1)
        ->and($chemical->identifiers[0]->compoundId)->toBeNull()
        ->and($chemical->identifiers[0]->url)->toBeNull();
});

test('UniChem is reachable only when its sources endpoint answers successfully', function (Closure $response, bool $reachable) {
    Http::fake(['https://unichem.test/api/v1/sources' => $response()]);

    expect((new Unichem)->is_reachable())->toBe($reachable);
})->with([
    'success' => [fn () => Http::response(['response' => 'Success', 'sources' => []]), true],
    'server error' => [fn () => Http::response('', 503), false],
    'no connection' => [fn () => failingConnection(), false],
]);
