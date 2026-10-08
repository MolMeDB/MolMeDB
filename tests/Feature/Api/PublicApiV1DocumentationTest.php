<?php

use App\Http\Requests\Api\Public\V1\SearchStructureRequest;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/*
 * Keeps the documentation of the public API in step with its routes: the
 * hand-written HTML explorer (config/api_explorer.php) and the generated
 * OpenAPI specification, which is pinned as a whole in
 * tests/Fixtures/openapi.v1.json (re-record it with UPDATE_API_CONTRACTS=1).
 */

const OPENAPI_SNAPSHOT = __DIR__.'/../../Fixtures/openapi.v1.json';

/**
 * Public API GET routes, relative to api/v1 (e.g. "structures/{identifier}").
 *
 * @return array<int, string>
 */
function documentedPublicApiRoutes(): array
{
    return collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => in_array('GET', $route->methods(), true) && str_starts_with($route->uri(), 'api/v1/'))
        ->map(fn (Route $route): string => substr($route->uri(), strlen('api/v1/')))
        ->reject(fn (string $uri): bool => in_array($uri, ['docs', 'openapi.json'], true))
        ->sort()
        ->values()
        ->all();
}

test('the API explorer describes every public API route', function () {
    $documented = array_keys(config('api_explorer.routes'));

    expect(array_values(array_diff(documentedPublicApiRoutes(), $documented)))
        ->toBe([], 'Public API routes missing in config/api_explorer.php');
});

test('the API explorer does not describe routes that no longer exist', function () {
    $documented = array_keys(config('api_explorer.routes'));

    expect(array_values(array_diff($documented, documentedPublicApiRoutes())))
        ->toBe([], 'Routes in config/api_explorer.php that do not exist');
});

test('the API explorer knows every path parameter', function () {
    $parameters = collect(documentedPublicApiRoutes())
        ->flatMap(fn (string $uri): array => preg_match_all('/\{(\w+)\}/', $uri, $matches) ? $matches[1] : [])
        ->unique()
        ->values();

    expect($parameters->diff(array_keys(config('api_explorer.path_params')))->values()->all())
        ->toBe([], 'Path parameters without a label/example in config/api_explorer.php');
});

test('the API explorer documents every structure search parameter', function () {
    $documented = array_keys(config('api_explorer.routes.structures.query'));
    $accepted = array_keys((new SearchStructureRequest)->rules());

    expect(collect($accepted)->sort()->values()->all())->toBe(collect($documented)->sort()->values()->all());
});

test('the OpenAPI specification documents every public API route', function () {
    $specification = app(Generator::class)(Scramble::getGeneratorConfig('default'));
    $paths = array_map(fn (string $path): string => ltrim($path, '/'), array_keys($specification['paths']));

    expect(array_values(array_diff(documentedPublicApiRoutes(), $paths)))->toBe([]);
});

test('the OpenAPI specification matches the recorded one', function () {
    $specification = json_encode(
        app(Generator::class)(Scramble::getGeneratorConfig('default')),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    )."\n";

    if (getenv('UPDATE_API_CONTRACTS')) {
        file_put_contents(OPENAPI_SNAPSHOT, $specification);
    }

    expect(file_exists(OPENAPI_SNAPSHOT))->toBeTrue('Record the OpenAPI specification with UPDATE_API_CONTRACTS=1.')
        ->and($specification)->toBe(file_get_contents(OPENAPI_SNAPSHOT), 'The generated OpenAPI specification changed. If intended, re-record tests/Fixtures/openapi.v1.json with UPDATE_API_CONTRACTS=1 and review the diff.');
});
