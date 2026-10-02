<?php

use App\Models\File;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const RDF_INTERACTION_IRI = 'https://rdf.molmedb.upol.cz/interaction/int18095';

/**
 * Fakes the SPARQL endpoint: ASK answers with $exists, CONSTRUCT echoes the
 * requested media type, SELECT returns one statement.
 */
function fakeRdfSparqlEndpoint(bool $exists = true): void
{
    Http::fake(function (HttpRequest $request) use ($exists) {
        $query = $request['query'];

        if (str_starts_with($query, 'ASK')) {
            return Http::response(['boolean' => $exists]);
        }

        if (str_starts_with($query, 'CONSTRUCT')) {
            return Http::response('serialized as '.$request->header('Accept')[0], 200, ['Content-Type' => $request->header('Accept')[0]]);
        }

        return Http::response(['results' => ['bindings' => [[
            'p' => ['type' => 'uri', 'value' => 'http://www.bioassayontology.org/bao#BAO_0090012'],
            'o' => ['type' => 'uri', 'value' => 'https://rdf.molmedb.upol.cz/interaction/membrane13'],
            'l' => ['type' => 'literal', 'value' => 'EggPC'],
        ]]]]);
    });
}

test('RDF resources are served as Turtle by default', function () {
    fakeRdfSparqlEndpoint();

    $this->get('/api/rdf/interaction/int18095', ['Accept' => '*/*'])
        ->assertOk()
        ->assertHeader('Content-Type', 'text/turtle; charset=UTF-8')
        ->assertHeader('Vary', 'Accept')
        ->assertSee('serialized as text/turtle');

    Http::assertSent(fn (HttpRequest $request) => str_contains($request['query'], '<'.RDF_INTERACTION_IRI.'>')
        && $request->url() === config('fair.rdf.sparql_endpoint'));
});

test('RDF resources are served in the negotiated serialization', function (string $accept, string $mimeType) {
    fakeRdfSparqlEndpoint();

    $response = $this->get('/api/rdf/interaction/int18095', ['Accept' => $accept]);

    $response->assertOk()
        ->assertHeader('Content-Type', "{$mimeType}; charset=UTF-8")
        ->assertSee("serialized as {$mimeType}");

    expect($response->headers->get('Link'))
        ->toContain(RDF_INTERACTION_IRI.'?format=ntriples>; rel="alternate"; type="application/n-triples"');
})->with([
    'turtle' => ['text/turtle', 'text/turtle'],
    'n-triples' => ['application/n-triples', 'application/n-triples'],
    'rdf/xml' => ['application/rdf+xml', 'application/rdf+xml'],
    'weighted' => ['application/rdf+xml;q=0.5, application/n-triples', 'application/n-triples'],
]);

test('the format query parameter overrides the Accept header', function () {
    fakeRdfSparqlEndpoint();

    $this->get('/api/rdf/interaction/int18095?format=rdfxml', ['Accept' => 'text/html'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/rdf+xml; charset=UTF-8');
});

test('browsers are redirected to the page about the resource', function (string $path, string $page) {
    fakeRdfSparqlEndpoint();

    $this->get("/api/rdf/{$path}", ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8'])
        ->assertStatus(303)
        ->assertRedirect("https://molmedb.upol.cz{$page}");
})->with([
    'membrane' => ['interaction/membrane13', '/membrane/13'],
    'method' => ['interaction/method87', '/method/87'],
    'transporter' => ['transporter/target1', '/protein/1'],
    'reference' => ['reference/ref71', '/publication/71'],
    'substance attribute' => ['substance/MM00040_Molecular_Weight', '/mol/MM00040'],
    'substance form attribute' => ['substance/MM475229.2_LogP', '/mol/MM475229.2'],
]);

test('browsers get an HTML listing for resources without their own page', function () {
    fakeRdfSparqlEndpoint();

    $this->get('/api/rdf/interaction/int18095', ['Accept' => 'text/html'])
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertSee(RDF_INTERACTION_IRI)
        ->assertSee('bao:BAO_0090012')
        ->assertSee('mmdbint:membrane13')
        ->assertSee('EggPC');
});

test('unknown RDF resources return 404', function () {
    fakeRdfSparqlEndpoint(exists: false);

    $this->get('/api/rdf/interaction/int999999999', ['Accept' => 'text/turtle'])
        ->assertNotFound()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
});

test('paths outside the RDF namespaces return 404 without querying the endpoint', function () {
    Http::fake();

    $this->get('/api/rdf/unknown/thing', ['Accept' => 'text/turtle'])->assertNotFound();

    Http::assertNothingSent();
});

test('an unavailable SPARQL endpoint returns 503', function () {
    Http::fake(fn () => Http::response('down', 500));

    $this->get('/api/rdf/interaction/int18095', ['Accept' => 'text/turtle'])
        ->assertStatus(503)
        ->assertHeader('Retry-After', '300')
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
});

test('the vocabulary is served from the RDF storage', function () {
    Storage::fake('disk-rdf');
    Storage::disk('disk-rdf')->put('vocabulary.owl', '<rdf:RDF/>');

    File::query()->insert([
        'type' => File::TYPE_RDF_VOCABULARY,
        'name' => 'vocabulary.owl',
        'path' => 'vocabulary.owl',
        'storage' => 'disk-rdf',
        'mime' => 'text/xml',
        'hash' => md5('<rdf:RDF/>'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->get('/api/rdf/vocabulary')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/rdf+xml; charset=UTF-8')
        ->assertContent('<rdf:RDF/>');
});

test('a missing vocabulary returns 404', function () {
    $this->get('/api/rdf/vocabulary')->assertNotFound();
});
