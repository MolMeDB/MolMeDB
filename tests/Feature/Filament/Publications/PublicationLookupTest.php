<?php

require_once __DIR__.'/../filament_resource_test_helpers.php';

use App\Enums\PermissionEnums;
use App\Filament\Resources\Publications\Pages\CreatePublication;
use App\Filament\Resources\Publications\Pages\EditPublication;
use App\Filament\Resources\Publications\Pages\ListPublications;
use App\Models\Author;
use App\Models\Publication;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\References\EuropePMC\Enums\Sources;

/*
 * The publication form looks a publication up in EuropePMC (by its PubMed or
 * other EuropePMC identifier) or in CrossRef (by DOI) and stores what it
 * finds, including the authors.
 */

beforeEach(function () {
    seedFilamentResourcePermissions();

    config()->set('services.europe_pmc.endpoint', 'https://europepmc.test');
    config()->set('services.crossref.endpoint', 'https://crossref.test');
});

/**
 * @return array<string, mixed>
 */
function publicationLookupEuropePmcRecord(string $id, array $overrides = []): array
{
    return [
        'id' => $id,
        'source' => 'MED',
        'pmid' => $id,
        'doi' => '10.1000/epmc.'.$id,
        'title' => 'Passive transport of small molecules',
        'journalInfo' => [
            'journal' => ['title' => 'Biophysical Journal'],
            'volume' => '118',
            'issue' => '4',
            'yearOfPublication' => 2020,
            'dateOfPublication' => '2020-02-25',
        ],
        'pageInfo' => '100-110',
        'authorList' => ['author' => [
            ['fullName' => 'Roe R', 'firstName' => 'Richard', 'lastName' => 'Roe', 'authorAffiliationDetailsList' => ['authorAffiliation' => [['affiliation' => 'Palacky University']]]],
            ['fullName' => 'Poe P', 'firstName' => 'Paula', 'lastName' => 'Poe'],
        ]],
        ...$overrides,
    ];
}

/**
 * @return array<string, mixed>
 */
function publicationLookupCrossRefWork(string $doi): array
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

function publicationLookupFakeEuropePmcDetail(string $id, ?array $record = null): void
{
    Http::fake([
        "https://europepmc.test/article/MED/{$id}*" => $record === null
            ? Http::response(['errMsg' => 'not found'], 404)
            : Http::response(['result' => $record]),
        'https://crossref.test/*' => Http::response(['status' => 'error'], 404),
    ]);
}

function publicationLookupEditor(): \App\Models\User
{
    return createFilamentResourceUser([PermissionEnums::PUBLICATION_VIEW, PermissionEnums::PUBLICATION_EDIT]);
}

function publicationLookupPublication(array $attributes = []): Publication
{
    return Publication::factory()->create([
        'citation' => 'Citation '.Str::random(12),
        'doi' => null,
        'identifier' => null,
        'identifier_source' => null,
        'title' => null,
        'journal' => null,
        'volume' => null,
        'issue' => null,
        'page' => null,
        'year' => null,
        'published_at' => null,
        'validated_at' => null,
        ...$attributes,
    ]);
}

test('a publication created with a PubMed ID is filled from EuropePMC with its authors', function () {
    publicationLookupFakeEuropePmcDetail('31000001', publicationLookupEuropePmcRecord('31000001'));
    $this->actingAs(publicationLookupEditor());

    Livewire::test(CreatePublication::class)
        ->fillForm([
            'citation' => 'Roe R: Passive transport',
            'identifier' => '31000001',
            'identifier_source' => Sources::MED->value,
            'doi' => '10.1000/epmc.31000001',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $publication = Publication::query()->where('identifier', '31000001')->firstOrFail();

    expect($publication)
        ->title->toBe('Passive transport of small molecules')
        ->journal->toBe('Biophysical Journal')
        ->volume->toBe('118')
        ->issue->toBe('4')
        ->page->toBe('100-110')
        ->year->toBe(2020)
        ->validated_at->not->toBeNull()
        ->and((string) $publication->published_at)->toStartWith('2020-02-25')
        ->and($publication->authors->sortBy('last_name')->pluck('full_name')->all())->toBe(['Poe P', 'Roe R'])
        ->and($publication->authors->firstWhere('last_name', 'Roe')->affiliation)->toBe('Palacky University');
});

test('a publication whose PubMed ID EuropePMC does not know is stored unvalidated with a warning', function () {
    publicationLookupFakeEuropePmcDetail('31000002');
    $this->actingAs(publicationLookupEditor());

    Livewire::test(CreatePublication::class)
        ->fillForm([
            'citation' => 'Unknown PubMed citation',
            'identifier' => '31000002',
            'identifier_source' => Sources::MED->value,
            'doi' => '10.1000/unknown.31000002',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Record not found on Europe PMC server.');

    $publication = Publication::query()->where('citation', 'Unknown PubMed citation')->firstOrFail();

    expect($publication->validated_at)->toBeNull()
        ->and($publication->title)->toBeNull()
        ->and($publication->authors)->toBeEmpty();
});

test('a publication created with a DOI only is filled from CrossRef with its authors', function () {
    $doi = '10.5555/'.Str::lower(Str::random(10));
    Http::fake(["https://crossref.test/works/{$doi}" => Http::response(publicationLookupCrossRefWork($doi))]);
    $this->actingAs(publicationLookupEditor());

    Livewire::test(CreatePublication::class)
        ->fillForm(['citation' => 'Doe J: Caffeine', 'doi' => $doi])
        ->call('create')
        ->assertHasNoFormErrors();

    $publication = Publication::query()->where('doi', $doi)->firstOrFail();

    expect($publication)
        ->title->toBe('Permeation of caffeine through lipid bilayers')
        ->journal->toBe('Journal of Membrane Science')
        ->year->toBe(2024)
        ->validated_at->not->toBeNull()
        ->and((string) $publication->published_at)->toStartWith('2024-04-11')
        ->and($publication->authors->pluck('full_name')->all())->toBe(['Jane Doe']);
});

test('a publication whose DOI CrossRef does not know is stored unvalidated with a warning', function () {
    Http::fake(['https://crossref.test/*' => Http::response(['status' => 'error'], 404)]);
    $this->actingAs(publicationLookupEditor());

    Livewire::test(CreatePublication::class)
        ->fillForm(['citation' => 'Unknown DOI citation', 'doi' => '10.5555/unknown'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Record not found on CrossRef server.');

    expect(Publication::query()->where('doi', '10.5555/unknown')->firstOrFail()->validated_at)->toBeNull();
});

test('the publication form requires a citation, a unique citation and a DOI next to an identifier', function () {
    Http::fake();
    publicationLookupPublication(['citation' => 'Taken citation']);
    $this->actingAs(publicationLookupEditor());

    Livewire::test(CreatePublication::class)
        ->fillForm(['citation' => null])
        ->call('create')
        ->assertHasFormErrors(['citation' => 'required']);

    Livewire::test(CreatePublication::class)
        ->fillForm(['citation' => 'Taken citation'])
        ->call('create')
        ->assertHasFormErrors(['citation' => 'unique']);

    Livewire::test(CreatePublication::class)
        ->fillForm(['citation' => 'Fresh citation', 'identifier' => '123', 'identifier_source' => Sources::MED->value, 'doi' => null])
        ->call('create')
        ->assertHasFormErrors(['doi']);

    Http::assertNothingSent();
});

test('saving a publication with a PubMed ID refreshes its details and replaces its authors', function () {
    publicationLookupFakeEuropePmcDetail('31000003', publicationLookupEuropePmcRecord('31000003'));
    $publication = publicationLookupPublication(['title' => 'Old title', 'published_at' => '1999-01-01']);
    $oldAuthor = Author::query()->create(['first_name' => 'Old', 'last_name' => 'Author', 'full_name' => 'Old Author']);
    $publication->authors()->attach($oldAuthor);
    $this->actingAs(publicationLookupEditor());

    Livewire::test(EditPublication::class, ['record' => $publication->getKey()])
        ->fillForm(['identifier' => '31000003', 'identifier_source' => Sources::MED->value, 'doi' => '10.1000/epmc.31000003'])
        ->call('save')
        ->assertHasNoFormErrors();

    $publication->refresh();

    expect($publication)
        ->title->toBe('Passive transport of small molecules')
        ->year->toBe(2020)
        ->validated_at->not->toBeNull()
        ->and((string) $publication->published_at)->toStartWith('2020-02-25')
        ->and($publication->authors->pluck('full_name')->sort()->values()->all())->toBe(['Poe P', 'Roe R']);
});

test('saving a publication with an identifier EuropePMC does not know drops the identifier', function () {
    publicationLookupFakeEuropePmcDetail('31000004');
    $publication = publicationLookupPublication(['title' => 'Old title']);
    $this->actingAs(publicationLookupEditor());

    Livewire::test(EditPublication::class, ['record' => $publication->getKey()])
        ->fillForm(['identifier' => '31000004', 'identifier_source' => Sources::MED->value, 'doi' => '10.1000/missing'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Record not found on Europe PMC server.');

    expect($publication->refresh())
        ->identifier->toBeNull()
        ->identifier_source->toBeNull()
        ->title->toBeNull();
});

test('saving a publication with a DOI only refreshes it from CrossRef', function () {
    $doi = '10.5555/'.Str::lower(Str::random(10));
    Http::fake(["https://crossref.test/works/{$doi}" => Http::response(publicationLookupCrossRefWork($doi))]);
    $publication = publicationLookupPublication();
    $this->actingAs(publicationLookupEditor());

    Livewire::test(EditPublication::class, ['record' => $publication->getKey()])
        ->fillForm(['doi' => $doi])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($publication->refresh())
        ->title->toBe('Permeation of caffeine through lipid bilayers')
        ->and((string) $publication->published_at)->toStartWith('2024-04-11')
        ->and($publication->authors->pluck('full_name')->all())->toBe(['Jane Doe']);
});

test('saving a publication with a DOI CrossRef does not know warns and keeps no details', function () {
    Http::fake(['https://crossref.test/*' => Http::response(['status' => 'error'], 404)]);
    $publication = publicationLookupPublication(['title' => 'Old title']);
    $this->actingAs(publicationLookupEditor());

    Livewire::test(EditPublication::class, ['record' => $publication->getKey()])
        ->fillForm(['doi' => '10.5555/not-there'])
        ->call('save')
        ->assertNotified('Record not found on CrossRef server.');

    expect($publication->refresh()->title)->toBeNull();
});

test('searching a citation offers it unlinked and the matching EuropePMC records', function () {
    Http::fake(['https://europepmc.test/search*' => Http::response([
        'hitCount' => 1,
        'resultList' => ['result' => [publicationLookupEuropePmcRecord('31000005')]],
    ])]);
    $this->actingAs(publicationLookupEditor());

    $page = Livewire::test(CreatePublication::class);
    $results = $page->instance()->form->getComponent('citation')->getSearchResults('caffeine');

    expect(array_keys($results))->toBe(['caffeine', '31000005&&&MED'])
        ->and($results['caffeine'])->toBe('[UNLINKED] caffeine')
        ->and($results['31000005&&&MED'])->toContain('Passive transport of small molecules');
});

test('choosing a EuropePMC record as the citation fills the publication details', function () {
    publicationLookupFakeEuropePmcDetail('31000006', publicationLookupEuropePmcRecord('31000006'));
    $this->actingAs(publicationLookupEditor());

    Livewire::test(CreatePublication::class)
        ->fillForm(['citation' => '31000006&&&MED'])
        ->assertNotified('Citation selected')
        ->assertSchemaStateSet([
            'identifier' => '31000006',
            'identifier_source' => 'MED',
            'doi' => '10.1000/epmc.31000006',
            'title' => 'Passive transport of small molecules',
            'journal' => 'Biophysical Journal',
            'page' => '100-110',
        ])
        ->fillForm(['citation' => 'Only my own words'])
        ->assertSchemaStateSet(['identifier' => '', 'doi' => '', 'title' => '']);
});

test('the identifier lookup fills the form from EuropePMC or warns when nothing is found', function () {
    publicationLookupFakeEuropePmcDetail('31000007', publicationLookupEuropePmcRecord('31000007'));
    $this->actingAs(publicationLookupEditor());

    Livewire::test(CreatePublication::class)
        ->fillForm(['identifier' => '31000007', 'identifier_source' => Sources::MED->value])
        ->callAction(TestAction::make('validatePublicationRecord')->schemaComponent('identifier'))
        ->assertNotified('Record found')
        ->assertSchemaStateSet([
            'doi' => '10.1000/epmc.31000007',
            'title' => 'Passive transport of small molecules',
            'year' => 2020,
        ]);

    Livewire::test(CreatePublication::class)
        ->fillForm(['identifier' => '31000008', 'identifier_source' => Sources::MED->value])
        ->callAction(TestAction::make('validatePublicationRecord')->schemaComponent('identifier'))
        ->assertNotified('Record not found')
        ->assertSchemaStateSet(['title' => null]);
});

test('searching a DOI offers EuropePMC records and falls back to CrossRef', function () {
    $doi = '10.5555/'.Str::lower(Str::random(10));
    Http::fake([
        'https://europepmc.test/search*query=10.1000*' => Http::response([
            'hitCount' => 1,
            'resultList' => ['result' => [publicationLookupEuropePmcRecord('31000009')]],
        ]),
        'https://europepmc.test/search*' => Http::response(['errMsg' => 'nothing'], 404),
        "https://crossref.test/works/{$doi}" => Http::response(publicationLookupCrossRefWork($doi)),
    ]);
    $this->actingAs(publicationLookupEditor());

    $doiField = Livewire::test(CreatePublication::class)->instance()->form->getComponent('doi');

    expect($doiField->getSearchResults('10.1000/epmc.31000009'))->toBe(['31000009&&&MED' => '10.1000/epmc.31000009 [EuropePMC]'])
        ->and($doiField->getSearchResults($doi))->toBe([$doi => "{$doi} [CrossRef]"]);
});

test('choosing a DOI fills the publication details from CrossRef', function () {
    $doi = '10.5555/'.Str::lower(Str::random(10));
    Http::fake(["https://crossref.test/works/{$doi}" => Http::response(publicationLookupCrossRefWork($doi))]);
    $this->actingAs(publicationLookupEditor());

    Livewire::test(CreatePublication::class)
        ->fillForm(['doi' => $doi])
        ->assertNotified('DOI selected')
        ->assertSchemaStateSet([
            'title' => 'Permeation of caffeine through lipid bilayers',
            'journal' => 'Journal of Membrane Science',
            'year' => 2024,
        ]);
});

test('only publication deleters get the delete action, and nobody may force delete', function () {
    Http::fake();
    $publication = publicationLookupPublication();

    $this->actingAs(publicationLookupEditor());
    Livewire::test(EditPublication::class, ['record' => $publication->getKey()])
        ->assertActionHidden('delete')
        ->assertActionHidden('forceDelete');

    $this->actingAs(createFilamentResourceUser([PermissionEnums::PUBLICATION_VIEW, PermissionEnums::PUBLICATION_EDIT, PermissionEnums::PUBLICATION_DELETE]));
    Livewire::test(EditPublication::class, ['record' => $publication->getKey()])
        ->assertActionHidden('forceDelete')
        ->callAction('delete');

    expect($publication->fresh()->trashed())->toBeTrue();
});

test('a deleted publication is marked in the title and can be restored by a deleter only', function () {
    Http::fake();
    $publication = publicationLookupPublication();
    $publication->delete();

    $this->actingAs(publicationLookupEditor());
    Livewire::test(EditPublication::class, ['record' => $publication->getKey()])
        ->assertSee("(DELETED) Edit publication [ID:{$publication->id}] - not validated")
        ->assertActionHidden('restore');

    $this->actingAs(createFilamentResourceUser([PermissionEnums::PUBLICATION_VIEW, PermissionEnums::PUBLICATION_EDIT, PermissionEnums::PUBLICATION_DELETE]));
    Livewire::test(EditPublication::class, ['record' => $publication->getKey()])
        ->callAction('restore');

    expect($publication->fresh()->trashed())->toBeFalse();
});

test('publications are deleted in bulk only by users who may delete any publication', function () {
    Http::fake();
    $publications = collect([publicationLookupPublication(), publicationLookupPublication()]);

    $this->actingAs(createFilamentResourceUser([PermissionEnums::PUBLICATION_VIEW, PermissionEnums::PUBLICATION_DELETE_OWN]));
    Livewire::test(ListPublications::class)
        ->assertTableBulkActionHidden('delete');

    $this->actingAs(createFilamentResourceUser([PermissionEnums::PUBLICATION_VIEW, PermissionEnums::PUBLICATION_DELETE]));
    Livewire::test(ListPublications::class)
        ->callTableBulkAction('delete', $publications);

    expect(Publication::onlyTrashed()->whereKey($publications->pluck('id'))->count())->toBe(2);
});

test('the publications list shows deleted publications only through the trashed filter', function () {
    Http::fake();
    $active = publicationLookupPublication();
    $deleted = publicationLookupPublication();
    $deleted->delete();

    $this->actingAs(createFilamentResourceUser([PermissionEnums::PUBLICATION_VIEW]));

    Livewire::test(ListPublications::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$deleted])
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$active, $deleted])
        ->filterTable('trashed', false)
        ->assertCanSeeTableRecords([$deleted])
        ->assertCanNotSeeTableRecords([$active]);
});

test('a user who may only edit own publications may create one but never edit an existing one', function () {
    $user = createFilamentResourceUser([PermissionEnums::PUBLICATION_VIEW, PermissionEnums::PUBLICATION_EDIT_OWN]);
    $publication = publicationLookupPublication();

    // Publications carry no owner, so "own" publications never exist.
    expect($user->can('create', Publication::class))->toBeTrue()
        ->and($user->can('update', $publication))->toBeFalse();
});
