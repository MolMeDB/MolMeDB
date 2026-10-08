<?php

require_once __DIR__.'/api_contract_seed.php';

/*
 * Pins the response of every public REST API endpoint (see Tests\Support\ApiContract).
 * The public API is a published contract: a change here needs a new API version
 * or at least an update of the OpenAPI documentation and the API explorer.
 */

test('public API endpoint keeps its contract', function (string $route, Closure $path) {
    $world = seedApiContractWorld();

    $this->getJson($path($world))->assertApiContract($route);
})->with([
    'about' => ['GET api/v1/about', fn () => '/api/v1/about'],
    'membranes' => ['GET api/v1/membranes', fn () => '/api/v1/membranes'],
    'membrane categories' => ['GET api/v1/membranes/categories', fn () => '/api/v1/membranes/categories'],
    'membrane' => ['GET api/v1/membranes/{membrane}', fn ($w) => "/api/v1/membranes/{$w['membrane']->id}"],
    'membrane stats' => ['GET api/v1/membranes/{membrane}/stats', fn ($w) => "/api/v1/membranes/{$w['membrane']->id}/stats"],
    'membrane interactions' => ['GET api/v1/membranes/{membrane}/interactions', fn ($w) => "/api/v1/membranes/{$w['membrane']->id}/interactions"],
    'membrane interactions export' => ['GET api/v1/membranes/{membrane}/interactions/export', fn ($w) => "/api/v1/membranes/{$w['membrane']->id}/interactions/export"],
    'methods' => ['GET api/v1/methods', fn () => '/api/v1/methods'],
    'method categories' => ['GET api/v1/methods/categories', fn () => '/api/v1/methods/categories'],
    'method' => ['GET api/v1/methods/{method}', fn ($w) => "/api/v1/methods/{$w['method']->id}"],
    'method stats' => ['GET api/v1/methods/{method}/stats', fn ($w) => "/api/v1/methods/{$w['method']->id}/stats"],
    'method interactions' => ['GET api/v1/methods/{method}/interactions', fn ($w) => "/api/v1/methods/{$w['method']->id}/interactions"],
    'method interactions export' => ['GET api/v1/methods/{method}/interactions/export', fn ($w) => "/api/v1/methods/{$w['method']->id}/interactions/export"],
    'passive interactions' => ['GET api/v1/interactions/passive', fn () => '/api/v1/interactions/passive'],
    'passive interaction' => ['GET api/v1/interactions/passive/{interaction}', fn ($w) => "/api/v1/interactions/passive/{$w['passive']->id}"],
    'active interactions' => ['GET api/v1/interactions/active', fn () => '/api/v1/interactions/active'],
    'active interaction' => ['GET api/v1/interactions/active/{interaction}', fn ($w) => "/api/v1/interactions/active/{$w['active']->id}"],
    'structures' => ['GET api/v1/structures', fn () => '/api/v1/structures'],
    'structure' => ['GET api/v1/structures/{identifier}', fn () => '/api/v1/structures/MM00040'],
    'structure stats' => ['GET api/v1/structures/{identifier}/stats', fn () => '/api/v1/structures/MM00040/stats'],
    'similar structures' => ['GET api/v1/structures/{identifier}/similar', fn () => '/api/v1/structures/MM00040/similar'],
    'structure passive interactions' => ['GET api/v1/structures/{identifier}/interactions/passive', fn () => '/api/v1/structures/MM00040/interactions/passive'],
    'structure active interactions' => ['GET api/v1/structures/{identifier}/interactions/active', fn () => '/api/v1/structures/MM00040/interactions/active'],
    'publications' => ['GET api/v1/publications', fn () => '/api/v1/publications'],
    'publication' => ['GET api/v1/publications/{publication}', fn ($w) => "/api/v1/publications/{$w['publication']->id}"],
    'publication stats' => ['GET api/v1/publications/{publication}/stats', fn ($w) => "/api/v1/publications/{$w['publication']->id}/stats"],
    'publication passive interactions' => ['GET api/v1/publications/{publication}/interactions/passive', fn ($w) => "/api/v1/publications/{$w['publication']->id}/interactions/passive"],
    'publication active interactions' => ['GET api/v1/publications/{publication}/interactions/active', fn ($w) => "/api/v1/publications/{$w['publication']->id}/interactions/active"],
    'publication passive interactions export' => ['GET api/v1/publications/{publication}/interactions/passive/export', fn ($w) => "/api/v1/publications/{$w['publication']->id}/interactions/passive/export"],
    'publication active interactions export' => ['GET api/v1/publications/{publication}/interactions/active/export', fn ($w) => "/api/v1/publications/{$w['publication']->id}/interactions/active/export"],
    'proteins' => ['GET api/v1/proteins', fn () => '/api/v1/proteins'],
    'protein categories' => ['GET api/v1/proteins/categories', fn () => '/api/v1/proteins/categories'],
    'protein' => ['GET api/v1/proteins/{protein}', fn ($w) => "/api/v1/proteins/{$w['protein']->id}"],
    'protein stats' => ['GET api/v1/proteins/{protein}/stats', fn ($w) => "/api/v1/proteins/{$w['protein']->id}/stats"],
    'protein interactions' => ['GET api/v1/proteins/{protein}/interactions', fn ($w) => "/api/v1/proteins/{$w['protein']->id}/interactions"],
]);

test('public API preflight requests keep their contract', function () {
    $this->call('OPTIONS', '/api/v1/structures', [], [], [], [
        'HTTP_ORIGIN' => 'https://example.org',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ])->assertApiContract('OPTIONS api/v1/{any}');
});

test('public API structure molfile keeps its contract', function () {
    $world = seedApiContractWorld();
    $world['structure']->update(['molfile_3d' => <<<'MOL'
Caffeine
  RDKit          3D

  2  1  0  0  0  0  0  0  0  0999 V2000
    0.0000    0.0000    0.0000 C   0  0  0  0  0  0  0  0  0  0  0  0
    1.2000    0.0000    0.0000 O   0  0  0  0  0  0  0  0  0  0  0  0
  1  2  1  0
M  END
MOL]);

    $this->get('/api/v1/structures/MM00040/molfile', ['Accept' => '*/*'])
        ->assertApiContract('GET api/v1/structures/{identifier}/molfile');
});
