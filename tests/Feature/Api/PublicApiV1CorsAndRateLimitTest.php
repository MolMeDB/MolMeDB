<?php

require_once __DIR__.'/api_test_helpers.php';

use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    // The public-api rate limiters key by IP against the default cache
    // store — flush it so limiter state never leaks between test methods.
    Cache::flush();
});

test('an OPTIONS preflight request gets an open, non-credentialed CORS response', function () {
    $response = $this->call('OPTIONS', '/api/v1/membranes');

    $response->assertNoContent();
    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('*');
    expect($response->headers->get('Access-Control-Allow-Methods'))->toBe('GET, OPTIONS');
    expect($response->headers->get('Access-Control-Allow-Headers'))->toBe('Content-Type, Accept');
    expect($response->headers->has('Access-Control-Allow-Credentials'))->toBeFalse();
});

test('an OPTIONS preflight works for any path under the public API, not just known routes', function () {
    $this->call('OPTIONS', '/api/v1/whatever/nested/path')
        ->assertNoContent();
});

test('a normal GET response also carries the open CORS headers', function () {
    $response = $this->getJson('/api/v1/membranes');

    $response->assertOk();
    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('*');
    expect($response->headers->has('Access-Control-Allow-Credentials'))->toBeFalse();
});

test('the MCP server preflight allows its POST transport and session headers', function () {
    $response = $this->call('OPTIONS', '/api/v1/mcp');

    $response->assertNoContent();
    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('*');
    expect($response->headers->get('Access-Control-Allow-Methods'))->toBe('POST, OPTIONS');
    expect($response->headers->get('Access-Control-Allow-Headers'))->toBe('Content-Type, Accept, Mcp-Session-Id, MCP-Protocol-Version');
    expect($response->headers->has('Access-Control-Allow-Credentials'))->toBeFalse();
});

test('an MCP response exposes its session header to browser clients', function () {
    $response = $this->postJson('/api/v1/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
    ]);

    $response->assertOk()->assertHeader('MCP-Session-Id');
    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('*');
    expect($response->headers->get('Access-Control-Expose-Headers'))->toBe('Mcp-Session-Id');
    expect($response->headers->has('Access-Control-Allow-Credentials'))->toBeFalse();
});

test('the substructure rate limiter only kicks in once a substructure search is requested', function () {
    createApiStructure(['identifier' => 'MM0001', 'canonical_smiles' => 'CCO']);

    // Plain queries aren't subject to the stricter substructure limiter.
    for ($i = 0; $i < 6; $i++) {
        $this->getJson('/api/v1/structures?query=whatever')->assertOk();
    }
    $this->getJson('/api/v1/structures?query=whatever')->assertOk();

    // The 6/min substructure limiter does kick in on the 7th substructure request.
    for ($i = 0; $i < 6; $i++) {
        $this->getJson('/api/v1/structures?substructure=CO')->assertOk();
    }
    $this->getJson('/api/v1/structures?substructure=CO')
        ->assertStatus(429);
});

test('the blanket public-api rate limiter eventually kicks in', function () {
    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/v1/membranes')->assertOk();
    }

    $this->getJson('/api/v1/membranes')->assertStatus(429);
});
