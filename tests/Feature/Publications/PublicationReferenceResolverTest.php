<?php

require_once __DIR__.'/../Api/api_test_helpers.php';

use App\Models\Publication;
use App\Services\PublicationReferenceResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\References\EuropePMC\Enums\Sources;

/*
 * Upload rows and publication forms name a publication by DOI or PubMed ID.
 * The resolver finds it in the database, or looks it up in CrossRef (DOI) and
 * EuropePMC (PubMed ID, or DOI when CrossRef has nothing) and stores it.
 */

beforeEach(function () {
    config()->set('services.europe_pmc.endpoint', 'https://europepmc.test');
    config()->set('services.crossref.endpoint', 'https://crossref.test');
});

function uniqueDoi(): string
{
    return '10.5555/'.Str::lower(Str::random(10));
}

function crossRefWork(string $doi): array
{
    return [
        'status' => 'ok',
        'message' => [
            'DOI' => $doi,
            'title' => ['Permeation of caffeine through lipid bilayers'],
            'container-title' => ['Journal of Membrane Science'],
            'volume' => '12',
            'issue' => '3',
            'published' => ['date-parts' => [[2024, 4, 11]]],
            'author' => [['given' => 'Jane', 'family' => 'Doe', 'affiliation' => [['name' => 'Test Lab']]]],
        ],
    ];
}

function europePmcArticle(string $pmid, ?string $doi = null): array
{
    return [
        'result' => [
            'id' => $pmid,
            'source' => 'MED',
            'pmid' => $pmid,
            'doi' => $doi,
            'title' => 'Passive transport of small molecules',
            'authorString' => 'Roe R.',
            'journalInfo' => ['journal' => ['title' => 'Biophysical Journal'], 'yearOfPublication' => 2023],
        ],
    ];
}

test('references are normalised from DOI links and prefixes', function (string $input, string $expected) {
    expect(app(PublicationReferenceResolver::class)->normalizeReference($input))->toBe($expected);
})->with([
    'doi.org link' => ['https://doi.org/10.1000/ABC', '10.1000/ABC'],
    'dx.doi.org link' => ['http://dx.doi.org/10.1000/abc', '10.1000/abc'],
    'doi: prefix' => ['doi: 10.1000/abc', '10.1000/abc'],
    'PubMed ID' => [' 12345 ', '12345'],
]);

test('a publication already stored is found without asking any service', function () {
    $doi = uniqueDoi();
    createApiPublication(['doi' => strtoupper($doi)]);
    Http::fake();

    expect(app(PublicationReferenceResolver::class)->referenceExists($doi))->toBeTrue();
    Http::assertNothingSent();
});

test('an unknown DOI is looked up in CrossRef and stored with its authors', function () {
    $doi = uniqueDoi();
    Http::fake(['https://crossref.test/works/*' => Http::response(crossRefWork($doi))]);

    $resolver = app(PublicationReferenceResolver::class);
    $publication = $resolver->resolveOrCreatePublication("https://doi.org/{$doi}");

    expect($publication->doi)->toBe($doi)
        ->and($publication->title)->toBe('Permeation of caffeine through lipid bilayers')
        ->and($publication->journal)->toBe('Journal of Membrane Science')
        ->and($publication->year)->toBe(2024)
        ->and($publication->authors->pluck('full_name')->all())->toBe(['Jane Doe'])
        ->and($resolver->resolveOrCreatePublication($doi)->id)->toBe($publication->id)
        ->and(Publication::query()->where('doi', $doi)->count())->toBe(1);
});

test('a DOI unknown to CrossRef is searched for in EuropePMC', function () {
    $doi = uniqueDoi();
    Http::fake([
        'https://crossref.test/*' => Http::response(['status' => 'error'], 404),
        'https://europepmc.test/search*' => Http::response([
            'hitCount' => 1,
            'resultList' => ['result' => [['id' => '99887766', 'source' => 'MED', 'doi' => $doi, 'title' => 'Found in EuropePMC']]],
        ]),
    ]);

    $publication = app(PublicationReferenceResolver::class)->resolveOrCreatePublication($doi);

    expect($publication->title)->toBe('Found in EuropePMC')
        ->and($publication->identifier)->toBe('99887766')
        ->and($publication->identifier_source)->toBe(Sources::MED->value);
});

test('a PubMed ID is looked up in EuropePMC', function () {
    $pmid = (string) random_int(10_000_000, 99_999_999);
    Http::fake(["https://europepmc.test/article/MED/{$pmid}*" => Http::response(europePmcArticle($pmid))]);

    $publication = app(PublicationReferenceResolver::class)->resolveOrCreatePublication($pmid);

    expect($publication->identifier)->toBe($pmid)
        ->and($publication->identifier_source)->toBe(Sources::MED->value)
        ->and($publication->title)->toBe('Passive transport of small molecules');
});

test('a reference that no service knows does not exist and cannot be created', function () {
    $doi = uniqueDoi();
    Http::fake([
        'https://crossref.test/*' => Http::response(['status' => 'error'], 404),
        'https://europepmc.test/*' => Http::response(['hitCount' => 0, 'resultList' => ['result' => []]]),
    ]);

    $resolver = app(PublicationReferenceResolver::class);

    expect($resolver->referenceExists($doi))->toBeFalse()
        ->and(fn () => $resolver->resolveOrCreatePublication($doi))->toThrow(RuntimeException::class, "Unable to resolve publication reference '{$doi}'.");
});

test('a reference missed during an outage is found once the services answer again', function () {
    $doi = uniqueDoi();
    $servicesUp = false;
    Http::fake(function (Request $request) use (&$servicesUp, $doi) {
        if (! $servicesUp) {
            return Http::response('Service unavailable', 503);
        }

        return str_starts_with($request->url(), 'https://crossref.test/')
            ? Http::response(crossRefWork($doi))
            : Http::response(['hitCount' => 0, 'resultList' => ['result' => []]]);
    });

    expect(app(PublicationReferenceResolver::class)->referenceExists($doi))->toBeFalse();

    // A later queue job in the same worker process, after the short
    // negative cache of the lookup has expired.
    $servicesUp = true;
    $this->travel(11)->minutes();
    app()->forgetScopedInstances();

    expect(app(PublicationReferenceResolver::class)->referenceExists($doi))->toBeTrue();
});
