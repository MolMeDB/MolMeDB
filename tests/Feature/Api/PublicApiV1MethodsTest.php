<?php

require_once __DIR__.'/api_test_helpers.php';

use App\Models\Category;
use App\Models\File;

test('methods index returns paginated methods', function () {
    createApiMethod(['name' => 'PAMPA assay', 'abbreviation' => 'PAMPA']);
    createApiMethod(['name' => 'Caco-2 assay', 'abbreviation' => 'CACO2']);

    $this->getJson('/api/v1/methods')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [['id', 'name', 'abbreviation', 'description']],
            'links',
            'meta',
        ]);
});

test('methods index filters by free-text query', function () {
    createApiMethod(['name' => 'PAMPA assay', 'abbreviation' => 'PAMPA']);
    createApiMethod(['name' => 'Caco-2 assay', 'abbreviation' => 'CACO2']);

    $this->getJson('/api/v1/methods?query=Caco')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.abbreviation', 'CACO2');
});

test('methods index filters by category_id', function () {
    $category = createApiRootCategory(Category::TYPE_METHOD, 'In vitro');
    $otherCategory = createApiRootCategory(Category::TYPE_METHOD, 'In silico');

    $inCategory = createApiMethod(['name' => 'In category', 'abbreviation' => 'INC'], $category);
    createApiMethod(['name' => 'Not in category', 'abbreviation' => 'NIC'], $otherCategory);

    $this->getJson("/api/v1/methods?category_id={$category->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inCategory->id);
});

test('methods show returns a single method with its categories', function () {
    $category = createApiRootCategory(Category::TYPE_METHOD, 'In vitro');
    $method = createApiMethod([], $category);

    $this->getJson("/api/v1/methods/{$method->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $method->id)
        ->assertJsonPath('data.categories.0.id', $category->id);
});

test('methods show returns 404 for an unknown method', function () {
    $this->getJson('/api/v1/methods/999999')
        ->assertNotFound();
});

test('methods stats returns aggregate counts', function () {
    $method = createApiMethod();
    createApiPassiveInteraction(['dataset' => createApiDataset(['method' => $method])]);

    $this->getJson("/api/v1/methods/{$method->id}/stats")
        ->assertOk()
        ->assertJsonPath('data.method.id', $method->id)
        ->assertJsonPath('data.total.interactions_passive', 1);
});

test('methods categories returns the full category tree with items', function () {
    $root = createApiRootCategory(Category::TYPE_METHOD, 'In vitro');
    $method = createApiMethod([], $root);

    $this->getJson('/api/v1/methods/categories')
        ->assertOk()
        ->assertJsonPath('data.0.id', $root->id)
        ->assertJsonPath('data.0.items.0.id', $method->id);
});

test('method interactions downloads the latest export file', function () {
    $method = createApiMethod();
    createApiExportFile($method, File::TYPE_EXPORT_INTERACTIONS_METHOD, 'method export contents');

    // A plain ->get() defaults to a browser-like Accept header (includes
    // text/html), which would route into the explorer page instead of
    // calling the controller — force JSON/binary negotiation explicitly.
    $response = $this->getJson("/api/v1/methods/{$method->id}/interactions");

    $response->assertOk();
    expect($response->streamedContent())->toBe('method export contents');
});

test('method interactions returns 404 when no export exists yet', function () {
    $method = createApiMethod();

    $this->getJson("/api/v1/methods/{$method->id}/interactions")
        ->assertNotFound();
});
