<?php

require_once __DIR__.'/api_test_helpers.php';

use App\Models\Category;
use App\Models\File;

test('membranes index returns paginated membranes', function () {
    createApiMembrane(['name' => 'DOPC bilayer', 'abbreviation' => 'DOPC']);
    createApiMembrane(['name' => 'DOPS bilayer', 'abbreviation' => 'DOPS']);

    $this->getJson('/api/v1/membranes')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [['id', 'name', 'abbreviation', 'description']],
            'links',
            'meta',
        ]);
});

test('membranes index filters by free-text query', function () {
    createApiMembrane(['name' => 'DOPC bilayer', 'abbreviation' => 'DOPC']);
    createApiMembrane(['name' => 'DOPS bilayer', 'abbreviation' => 'DOPS']);

    $this->getJson('/api/v1/membranes?query=DOPS')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.abbreviation', 'DOPS');
});

test('membranes index filters by category_id', function () {
    $category = createApiRootCategory(Category::TYPE_MEMBRANE, 'Lipid bilayers');
    $otherCategory = createApiRootCategory(Category::TYPE_MEMBRANE, 'Other membranes');

    $inCategory = createApiMembrane(['name' => 'In category', 'abbreviation' => 'INC'], $category);
    createApiMembrane(['name' => 'Not in category', 'abbreviation' => 'NIC'], $otherCategory);

    $this->getJson("/api/v1/membranes?category_id={$category->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inCategory->id);
});

test('membranes index clamps per_page to 100', function () {
    $this->getJson('/api/v1/membranes?per_page=500')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

test('membranes show returns a single membrane with its categories', function () {
    $category = createApiRootCategory(Category::TYPE_MEMBRANE, 'Lipid bilayers');
    $membrane = createApiMembrane([], $category);

    $this->getJson("/api/v1/membranes/{$membrane->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $membrane->id)
        ->assertJsonPath('data.categories.0.id', $category->id)
        ->assertJsonPath('data.categories.0.breadcrumb.0.id', $category->id);
});

test('membranes show returns 404 for an unknown membrane', function () {
    $this->getJson('/api/v1/membranes/999999')
        ->assertNotFound();
});

test('membranes stats returns aggregate counts', function () {
    $membrane = createApiMembrane();
    createApiPassiveInteraction(['dataset' => createApiDataset(['membrane' => $membrane])]);
    createApiPassiveInteraction(['dataset' => createApiDataset(['membrane' => $membrane])]);

    $this->getJson("/api/v1/membranes/{$membrane->id}/stats")
        ->assertOk()
        ->assertJsonPath('data.membrane.id', $membrane->id)
        ->assertJsonPath('data.total.interactions_passive', 2)
        ->assertJsonPath('data.total.structures', 2);
});

test('membranes categories returns the full category tree with items', function () {
    $root = createApiRootCategory(Category::TYPE_MEMBRANE, 'Lipid bilayers');
    $child = createApiChildCategory($root, 'Phosphatidylcholines');
    $membrane = createApiMembrane([], $child);

    $this->getJson('/api/v1/membranes/categories')
        ->assertOk()
        ->assertJsonPath('data.0.id', $root->id)
        ->assertJsonPath('data.0.children.0.id', $child->id)
        ->assertJsonPath('data.0.children.0.items.0.id', $membrane->id);
});

test('membrane interactions downloads the latest export file', function () {
    $membrane = createApiMembrane();
    createApiExportFile($membrane, File::TYPE_EXPORT_INTERACTIONS_MEMBRANE, 'membrane export contents');

    // A plain ->get() defaults to a browser-like Accept header (includes
    // text/html), which would route into the explorer page instead of
    // calling the controller — force JSON/binary negotiation explicitly.
    $response = $this->getJson("/api/v1/membranes/{$membrane->id}/interactions");

    $response->assertOk();
    expect($response->streamedContent())->toBe('membrane export contents');
});

test('membrane interactions returns 404 when no export exists yet', function () {
    $membrane = createApiMembrane();

    $this->getJson("/api/v1/membranes/{$membrane->id}/interactions")
        ->assertNotFound();
});
