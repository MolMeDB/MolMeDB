<?php

require_once __DIR__.'/api_test_helpers.php';

use App\Models\Identifier;
use App\Models\ProteinIdentifier;

function getJsonLd(string $uri)
{
    return test()->get($uri, ['Accept' => 'application/ld+json']);
}

test('structure detail is served as an unwrapped Bioschemas MolecularEntity document', function () {
    $structure = createApiStructure([
        'identifier' => 'MM00040',
        'canonical_smiles' => 'CN1C=NC2=C1C(=O)N(C(=O)N2C)C',
        'inchi' => 'InChI=1S/C8H10N4O2/c1-10-4-9-6-5(10)7(13)12(3)8(14)11(6)2/h4H,1-3H3',
        'inchikey' => 'RYYVLZVUVIJVGH-UHFFFAOYSA-N',
        'molecular_weight' => 194.19,
        'logp' => -0.07,
    ]);

    Identifier::create([
        'structure_id' => $structure->id,
        'value' => '2519',
        'type' => Identifier::TYPE_PUBCHEM,
        'state' => Identifier::STATE_VALIDATED,
    ]);

    $response = getJsonLd('/api/v1/structures/MM00040');

    $response->assertOk()->assertHeader('Content-Type', 'application/ld+json');

    expect($response->json())
        ->not->toHaveKey('data')
        ->and($response->json('@type'))->toBe('MolecularEntity')
        ->and($response->json('@id'))->toBe('https://identifiers.org/molmedb/MM00040')
        ->and($response->json('dct:conformsTo.@id'))->toBe('https://bioschemas.org/profiles/MolecularEntity/0.5-RELEASE')
        ->and($response->json('identifier'))->toBe('MM00040')
        ->and($response->json('url'))->toBe('https://molmedb.upol.cz/mol/MM00040')
        ->and($response->json('smiles'))->toBe(['CN1C=NC2=C1C(=O)N(C(=O)N2C)C'])
        ->and($response->json('inChIKey'))->toBe('RYYVLZVUVIJVGH-UHFFFAOYSA-N')
        ->and($response->json('molecularWeight.value'))->toBe(194.19)
        ->and($response->json('sameAs'))->toBe(['https://identifiers.org/pubchem.compound:2519'])
        ->and($response->json('isPartOf.@id'))->toBe('https://molmedb.upol.cz')
        ->and($response->json('license'))->toBe('https://creativecommons.org/publicdomain/zero/1.0/');
});

test('structure index is served as a JSON-LD graph with pagination in Link headers', function () {
    foreach (range(1, 3) as $index) {
        createApiStructure(['identifier' => "MM0000{$index}"]);
    }

    $response = getJsonLd('/api/v1/structures?per_page=2');

    $response->assertOk()->assertHeader('Content-Type', 'application/ld+json');

    expect($response->json('@graph'))->toHaveCount(2)
        ->and($response->json('@graph.0'))->not->toHaveKey('@context')
        ->and($response->json('@graph.0.@type'))->toBe('MolecularEntity')
        ->and($response->headers->get('Link'))->toContain('rel="next"');
});

test('plain JSON responses are unchanged when JSON-LD is not requested', function () {
    createApiStructure(['identifier' => 'MM00040']);

    $this->getJson('/api/v1/structures/MM00040')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('data.identifier', 'MM00040')
        ->assertJsonPath('data.url', 'https://molmedb.upol.cz/api/v1/structures/MM00040');
});

test('about endpoint describes the dataset as a Bioschemas Dataset', function () {
    $response = getJsonLd('/api/v1/about');

    $response->assertOk()->assertHeader('Content-Type', 'application/ld+json');

    $document = $response->json();

    expect($document['@type'])->toBe('Dataset')
        ->and($document['dct:conformsTo']['@id'])->toBe('https://bioschemas.org/profiles/Dataset/1.0-RELEASE')
        ->and($document)->toHaveKeys(['name', 'description', 'identifier', 'keywords', 'license', 'url', 'creator', 'funding', 'citation', 'distribution'])
        ->and($document['license'])->toBe('https://creativecommons.org/publicdomain/zero/1.0/')
        ->and(collect($document['creator'])->pluck('@id'))->toContain('https://orcid.org/0000-0001-9472-2589')
        ->and(collect($document['funding'])->pluck('identifier'))->toContain('17-21122S', 'LM2023055', '8K0208', 'DSGC-2021-0060')
        ->and(collect($document['citation'])->pluck('@id'))->toContain('https://doi.org/10.1093/database/baz078', 'https://doi.org/10.1186/s13321-026-01208-3')
        ->and(collect($document['distribution'])->pluck('contentUrl'))->toContain(
            'https://molmedb.upol.cz/api/v1',
            'https://doi.org/10.5281/zenodo.18632779',
            'https://idsm.elixir-czech.cz/sparql/endpoint/molmedb',
        );
});

test('about endpoint returns license, citation and identifier scheme as plain JSON', function () {
    $this->getJson('/api/v1/about')
        ->assertOk()
        ->assertJsonPath('data.contact', 'molmedb@upol.cz')
        ->assertJsonPath('data.license.data.spdx', 'CC0-1.0')
        ->assertJsonPath('data.citation.doi', '10.1093/database/baz078')
        ->assertJsonPath('data.identifier_scheme.identifiers_org', 'https://identifiers.org/molmedb/{identifier}')
        ->assertJsonPath('data.links.openapi', 'https://molmedb.upol.cz/api/v1/openapi.json')
        ->assertJsonPath('data.rdf.sparql_endpoint', 'https://idsm.elixir-czech.cz/sparql/endpoint/molmedb')
        ->assertJsonPath('data.rdf.vocabulary', 'https://rdf.molmedb.upol.cz/vocabulary');
});

test('publication detail is served as a ScholarlyArticle identified by its DOI', function () {
    $publication = createApiPublication(['doi' => '10.1016/j.ejps.2003.10.009', 'title' => 'Example']);

    $response = getJsonLd("/api/v1/publications/{$publication->id}");

    $response->assertOk()->assertHeader('Content-Type', 'application/ld+json');

    expect($response->json('@type'))->toBe('ScholarlyArticle')
        ->and($response->json('@id'))->toBe('https://doi.org/10.1016/j.ejps.2003.10.009')
        ->and($response->json('sameAs'))->toBe("https://rdf.molmedb.upol.cz/reference/ref{$publication->id}")
        ->and($response->json('url'))->toBe("https://molmedb.upol.cz/publication/{$publication->id}");
});

test('membrane detail uses its MolMeDB RDF IRI and membrane model class', function () {
    $membrane = createApiMembrane(['name' => 'Egg PC mixture', 'abbreviation' => 'EggPC', 'description' => '<p>Egg <strong>PC</strong> &amp; more</p>']);

    $response = getJsonLd("/api/v1/membranes/{$membrane->id}");

    $response->assertOk()->assertHeader('Content-Type', 'application/ld+json');

    expect($response->json('@id'))->toBe("https://rdf.molmedb.upol.cz/interaction/membrane{$membrane->id}")
        ->and($response->json('additionalType'))->toBe('https://rdf.molmedb.upol.cz/vocabulary#MembraneModel')
        ->and($response->json('alternateName'))->toBe('EggPC')
        ->and($response->json('description'))->toBe('Egg PC & more')
        ->and($response->json('url'))->toBe("https://molmedb.upol.cz/membrane/{$membrane->id}");
});

test('method detail uses its MolMeDB RDF IRI and the BAO assay method class', function () {
    $method = createApiMethod();

    $response = getJsonLd("/api/v1/methods/{$method->id}");

    $response->assertOk();

    expect($response->json('@id'))->toBe("https://rdf.molmedb.upol.cz/interaction/method{$method->id}")
        ->and($response->json('additionalType'))->toBe('http://www.bioassayontology.org/bao#BAO_0002753')
        ->and($response->json('url'))->toBe("https://molmedb.upol.cz/method/{$method->id}");
});

test('protein detail is a Bioschemas Protein linked to UniProt', function () {
    $protein = createApiProtein(['uniprot_id' => 'O15244']);

    ProteinIdentifier::create([
        'protein_id' => $protein->id,
        'value' => 'SLC22A2',
        'type' => ProteinIdentifier::TYPE_NAME,
        'state' => ProteinIdentifier::STATE_VALIDATED,
    ]);

    $response = getJsonLd("/api/v1/proteins/{$protein->id}");

    $response->assertOk();

    expect($response->json('@type'))->toBe('Protein')
        ->and($response->json('@id'))->toBe("https://rdf.molmedb.upol.cz/transporter/target{$protein->id}")
        ->and($response->json('name'))->toBe('SLC22A2')
        ->and($response->json('identifier'))->toBe('O15244')
        ->and($response->json('sameAs'))->toBe('https://identifiers.org/uniprot:O15244')
        ->and($response->json('url'))->toBe("https://molmedb.upol.cz/protein/{$protein->id}");
});

test('plain JSON resources link their landing pages', function () {
    $membrane = createApiMembrane();
    $method = createApiMethod();
    $protein = createApiProtein();
    $publication = createApiPublication();

    $this->getJson("/api/v1/membranes/{$membrane->id}")->assertJsonPath('data.landing_page', "https://molmedb.upol.cz/membrane/{$membrane->id}");
    $this->getJson("/api/v1/methods/{$method->id}")->assertJsonPath('data.landing_page', "https://molmedb.upol.cz/method/{$method->id}");
    $this->getJson("/api/v1/proteins/{$protein->id}")->assertJsonPath('data.landing_page', "https://molmedb.upol.cz/protein/{$protein->id}");
    $this->getJson("/api/v1/publications/{$publication->id}")->assertJsonPath('data.landing_page', "https://molmedb.upol.cz/publication/{$publication->id}");
});
