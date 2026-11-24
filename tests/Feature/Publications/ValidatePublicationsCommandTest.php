<?php

require_once __DIR__.'/../Api/api_test_helpers.php';

use App\Models\Author;
use App\Models\Publication;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\References\EuropePMC\Enums\Sources;

/*
 * `europepmc:validate` fills the bibliographic data of publications that have a
 * EuropePMC identifier but were never validated, and replaces their authors.
 */

beforeEach(function () {
    config()->set('services.europe_pmc.endpoint', 'https://europepmc.test');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function unvalidatedPublication(array $attributes = []): Publication
{
    return createApiPublication(['identifier_source' => Sources::MED->value, 'validated_at' => null, ...$attributes]);
}

/**
 * One fake for EuropePMC: the connection check (search) and article details by identifier.
 *
 * @param  array<string, array<string, mixed>>  $articles  identifier => article result
 */
function fakeEuropePmcForValidation(array $articles, bool $isConnected = true): void
{
    Http::fake(function (Request $request) use ($articles, $isConnected) {
        if (str_starts_with($request->url(), 'https://europepmc.test/search')) {
            return $isConnected
                ? Http::response(['hitCount' => 1, 'resultList' => ['result' => [['id' => '1', 'source' => 'MED']]]])
                : Http::response('Service unavailable', 503);
        }

        foreach ($articles as $identifier => $article) {
            if (str_starts_with($request->url(), "https://europepmc.test/article/MED/{$identifier}")) {
                return Http::response(['result' => $article]);
            }
        }

        return Http::response(['errMsg' => 'Not found'], 404);
    });
}

/**
 * @param  array<int, array<string, mixed>>  $authors
 * @return array<string, mixed>
 */
function validationArticle(string $identifier, array $authors = []): array
{
    return [
        'id' => $identifier,
        'source' => 'MED',
        'title' => 'Permeation of drugs through lipid membranes',
        'pageInfo' => '101-110',
        'journalInfo' => [
            'issue' => '4',
            'volume' => '42',
            'yearOfPublication' => 2021,
            'journal' => ['title' => 'Journal of Membrane Biology'],
        ],
        'authorList' => ['author' => $authors],
    ];
}

test('unvalidated publications get the EuropePMC data and authors', function () {
    $identifier = (string) random_int(10_000_000, 99_999_999);
    $publication = unvalidatedPublication(['identifier' => $identifier, 'title' => 'Draft title']);
    $staleAuthor = Author::query()->create(['first_name' => 'Old', 'last_name' => 'Author', 'full_name' => 'Old Author']);
    $publication->authors()->attach($staleAuthor);
    $existingAuthor = Author::query()->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'full_name' => 'Jane Doe', 'affiliation' => 'Test Lab']);

    fakeEuropePmcForValidation([$identifier => validationArticle($identifier, [
        ['fullName' => 'Jane Doe', 'firstName' => 'Jane', 'lastName' => 'Doe', 'authorAffiliationDetailsList' => ['authorAffiliation' => [['affiliation' => 'Test Lab'], ['affiliation' => 'Second Lab']]]],
        ['fullName' => 'Richard Roe', 'firstName' => 'Richard', 'lastName' => 'Roe', 'authorAffiliationDetailsList' => ['authorAffiliation' => [['affiliation' => str_repeat('a', 600)]]]],
        ['fullName' => 'Consortium', 'firstName' => null, 'lastName' => 'Consortium'],
    ])]);

    $this->artisan('europepmc:validate')
        ->expectsOutputToContain("Validating publication {$publication->id}")
        ->expectsOutputToContain('## Publication validated!')
        ->expectsOutputToContain('Deleting 1 unassigned authors...')
        ->expectsOutputToContain('Done! Total 0 publications remaining not validated.')
        ->assertSuccessful();

    $publication->refresh();
    $authors = $publication->authors()->orderBy('last_name')->get();

    expect($publication->only(['title', 'journal', 'volume', 'issue', 'page']))->toBe([
        'title' => 'Permeation of drugs through lipid membranes',
        'journal' => 'Journal of Membrane Biology',
        'volume' => '42',
        'issue' => '4',
        'page' => '101-110',
    ])
        ->and((int) $publication->year)->toBe(2021)
        ->and($publication->validated_at)->not->toBeNull()
        ->and($authors->pluck('full_name')->all())->toBe(['Jane Doe', 'Richard Roe'])
        // An author with the same details is reused, the first affiliation is kept and limited to 500 characters.
        ->and($authors[0]->id)->toBe($existingAuthor->id)
        ->and(Str::length($authors[1]->affiliation))->toBe(503)
        ->and(Author::query()->whereKey($staleAuthor->id)->exists())->toBeFalse();
});

test('publications not found in EuropePMC stay unvalidated and others are not looked up', function () {
    $missing = unvalidatedPublication(['identifier' => '11111111', 'title' => 'Unknown']);
    $validated = unvalidatedPublication(['identifier' => '22222222', 'validated_at' => now()->subDay(), 'title' => 'Validated']);
    $withoutIdentifier = unvalidatedPublication(['identifier' => null, 'identifier_source' => null, 'title' => 'No identifier']);
    $sharedAuthor = Author::query()->create(['first_name' => 'Kept', 'last_name' => 'Author', 'full_name' => 'Kept Author']);
    $validated->authors()->attach($sharedAuthor);

    fakeEuropePmcForValidation([]);

    $this->artisan('europepmc:validate')
        ->expectsOutputToContain('## Publication not found on remote server.')
        ->doesntExpectOutputToContain("Validating publication {$validated->id}")
        ->doesntExpectOutputToContain('unassigned authors')
        ->expectsOutputToContain('Done! Total 2 publications remaining not validated.')
        ->assertSuccessful();

    expect($missing->refresh()->title)->toBe('Unknown')
        ->and($missing->validated_at)->toBeNull()
        ->and($validated->refresh()->title)->toBe('Validated')
        ->and($withoutIdentifier->refresh()->validated_at)->toBeNull()
        ->and(Author::query()->whereKey($sharedAuthor->id)->exists())->toBeTrue();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/article/MED/11111111'));
});

test('validation stops when EuropePMC is not reachable', function () {
    $publication = unvalidatedPublication(['identifier' => '33333333']);
    fakeEuropePmcForValidation([], isConnected: false);

    $this->artisan('europepmc:validate')
        ->expectsOutputToContain('EuropePMC is not connected. Stopping...')
        ->assertFailed();

    expect($publication->refresh()->validated_at)->toBeNull();
    Http::assertSentCount(1);
});
