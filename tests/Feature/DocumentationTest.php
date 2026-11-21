<?php

use App\Mcp\Servers\MolMeDBServer;
use App\Models\Category;
use App\Services\Documentation\DocumentationRenderer;
use App\Services\Documentation\DocumentationSource;

/*
 * The articles in resources/docs (published by docs:sync) render and keep
 * up with the code: a new public API endpoint, MCP tool or upload column
 * fails here until it is documented.
 */

/**
 * Rendered HTML of every article, by its full slug.
 *
 * @return array<string, string>
 */
function renderedDocumentation(): array
{
    $renderer = app(DocumentationRenderer::class);

    return collect(DocumentationSource::all())
        ->mapWithKeys(fn (DocumentationSource $source): array => [$source->fullSlug() => $renderer->render($source)])
        ->all();
}

test('every documentation article renders', function () {
    $articles = renderedDocumentation();

    expect($articles)->toHaveKeys([
        'rest', 'rest/structures', 'rest/interactions', 'rest/membranes-and-methods', 'rest/proteins', 'rest/publications', 'rest/mcp',
        'contributing-data/uploading-your-data', 'contributing-data/prediction-workflow', 'rdf/rdf-data-access',
    ]);

    foreach ($articles as $slug => $html) {
        expect($html)->not->toContain('{{', "{$slug} contains an unrendered Blade expression")
            ->not->toContain('&amp;amp;', "{$slug} escapes an ampersand twice");
    }
});

test('every public API endpoint is documented', function () {
    $documentation = implode("\n", renderedDocumentation());

    $missing = collect(array_keys(config('api_explorer.routes')))
        ->reject(fn (string $uri): bool => str_contains($documentation, "<code>GET /api/v1/{$uri}</code>"))
        ->values()
        ->all();

    expect($missing)->toBe([], 'Public API endpoints missing in resources/docs');
});

test('every MCP tool is documented', function () {
    $mcp = renderedDocumentation()['rest/mcp'];
    $tools = (new ReflectionClass(MolMeDBServer::class))->getProperty('tools')->getDefaultValue();

    foreach ($tools as $tool) {
        expect($mcp)->toContain('<code>'.app($tool)->name().'</code></h3>');
    }
});

test('every upload column is described', function () {
    Category::factory()->create(['title' => 'Substrate', 'type' => Category::TYPE_ACTIVE_INTERACTION, 'parent_id' => -1]);

    $upload = renderedDocumentation()['contributing-data/uploading-your-data'];

    expect($upload)->toContain('Substrate')
        ->and(preg_match_all('#<td>[^<]+</td>\s*<td></td>#', $upload))->toBe(0, 'An upload column has no description in resources/docs/contributing-data/uploading-your-data.md.blade.php');
});
