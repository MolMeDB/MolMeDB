<?php

require_once __DIR__.'/api_test_helpers.php';

test('a browser request (Accept: text/html) renders the explorer page instead of JSON', function () {
    $response = $this->withHeaders(['Accept' => 'text/html'])
        ->get('/api/v1/membranes');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/html');
    $response->assertSee('/membranes', false);
    $response->assertSee('MolMeDB API explorer', false);
});

test('the explorer page fills in path parameter values from the actual request', function () {
    $membrane = createApiMembrane();

    $response = $this->withHeaders(['Accept' => 'text/html'])
        ->get("/api/v1/membranes/{$membrane->id}/stats");

    $response->assertOk();
    $response->assertSee((string) $membrane->id, false);
});

test('an API client request (Accept: application/json) still gets JSON, not the explorer', function () {
    $this->withHeaders(['Accept' => 'application/json'])
        ->get('/api/v1/membranes')
        ->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta']);
});

test('a non-browser client with no format preference (Accept: */*) gets JSON', function () {
    // Plain ->get() can't simulate "no Accept header at all" — Symfony's
    // test Request::create() fills in a full browser-like default (which
    // includes text/html and would hit the explorer branch, as covered by
    // the "browser request" test above). Accept: */* is what a real
    // header-less client (e.g. curl, most HTTP libraries) sends instead.
    $this->withHeaders(['Accept' => '*/*'])
        ->get('/api/v1/membranes')
        ->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta']);
});

test('explorer returns a 404 JSON response for a route with no explorer config', function () {
    config()->set('api_explorer.routes.membranes', null);

    $this->withHeaders(['Accept' => 'text/html'])
        ->get('/api/v1/membranes')
        ->assertNotFound()
        ->assertJsonPath('message', 'No explorer page is available for this endpoint.');
});
