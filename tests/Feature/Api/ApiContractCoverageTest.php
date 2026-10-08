<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\Support\ApiContract;

/**
 * Every API endpoint must have a recorded contract (tests/Fixtures/api-contracts),
 * so adding or changing one without a test that pins its response fails here.
 */
const ROUTES_WITHOUT_CONTRACT = [
    'GET api/v1/docs' => 'HTML documentation page rendered by Scramble; the spec is pinned by OpenApiSpecificationTest.',
    'GET api/v1/openapi.json' => 'Pinned as a whole by OpenApiSpecificationTest.',
    'GET api/rdf/vocabulary' => 'Static RDF/XML document, see RdfDereferencingTest.',
    'GET api/rdf/vocabulary.owl' => 'The same document under the ontology IRI, see UpdateRdfVocabularyTest.',
    'GET api/rdf/{path}' => 'RDF serializations from the SPARQL endpoint, see RdfDereferencingTest.',
    'GET api/test' => 'Health check returning a constant message.',
    'GET api/epmc/test' => 'Manual Europe PMC connectivity check calling the live service.',
    'GET api/dump/idsm/download' => 'Streams the database dump for IDSM from the backup storage.',
];

/**
 * @return array<string, Route>
 */
function apiRoutesByContractName(): array
{
    return collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/'))
        // Authentication endpoints of laravel/fortify are the package's contract, not ours.
        ->reject(fn (Route $route): bool => str_starts_with($route->getActionName(), 'Laravel\\Fortify\\'))
        ->mapWithKeys(fn (Route $route): array => [
            collect($route->methods())->reject(fn (string $method): bool => $method === 'HEAD')->first().' '.$route->uri() => $route,
        ])
        ->all();
}

test('every API endpoint has a recorded contract', function () {
    $missing = collect(apiRoutesByContractName())
        ->keys()
        ->reject(fn (string $route): bool => array_key_exists($route, ROUTES_WITHOUT_CONTRACT))
        ->reject(fn (string $route): bool => file_exists(ApiContract::path($route)))
        ->values()
        ->all();

    expect($missing)->toBe([], 'Endpoints without a recorded contract (write a test calling ->assertApiContract() and record it with UPDATE_API_CONTRACTS=1): '.implode(', ', $missing));
});

test('every recorded contract belongs to an existing endpoint', function () {
    $routes = array_map(ApiContract::fileName(...), array_keys(apiRoutesByContractName()));

    $orphans = collect(glob(ApiContract::DIRECTORY.'/*.json') ?: [])
        ->map(fn (string $path): string => basename($path))
        ->reject(fn (string $file): bool => in_array($file, $routes, true))
        ->values()
        ->all();

    expect($orphans)->toBe([], 'Contracts of endpoints that no longer exist (delete them, and check the API consumers): '.implode(', ', $orphans));
});

test('endpoints excluded from contracts still exist', function () {
    expect(array_diff(array_keys(ROUTES_WITHOUT_CONTRACT), array_keys(apiRoutesByContractName())))->toBe([]);
});
