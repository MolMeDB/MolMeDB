<?php

require_once __DIR__.'/api_test_helpers.php';

use App\Models\Category;
use App\Models\ProteinIdentifier;

test('proteins index returns paginated proteins with identifiers', function () {
    $protein = createApiProtein(['uniprot_id' => 'P12345']);
    ProteinIdentifier::create([
        'protein_id' => $protein->id,
        'value' => 'OCT2',
        'type' => ProteinIdentifier::TYPE_NAME,
        'state' => ProteinIdentifier::STATE_VALIDATED,
    ]);
    createApiProtein(['uniprot_id' => 'Q67890']);

    $this->getJson('/api/v1/proteins')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.identifiers.0.value', 'OCT2');
});

test('proteins index filters by free-text query over uniprot id and identifiers', function () {
    createApiProtein(['uniprot_id' => 'P12345']);
    createApiProtein(['uniprot_id' => 'Q67890']);

    $this->getJson('/api/v1/proteins?query=Q678')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.uniprot_id', 'Q67890');
});

test('proteins index filters by category_id', function () {
    $category = createApiRootCategory(Category::TYPE_PROTEIN, 'Transporters');
    $otherCategory = createApiRootCategory(Category::TYPE_PROTEIN, 'Enzymes');

    $inCategory = createApiProtein(['uniprot_id' => 'P00001'], $category);
    createApiProtein(['uniprot_id' => 'P00002'], $otherCategory);

    $this->getJson("/api/v1/proteins?category_id={$category->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inCategory->id);
});

test('proteins show returns a single protein with its identifiers', function () {
    $protein = createApiProtein();
    ProteinIdentifier::create([
        'protein_id' => $protein->id,
        'value' => 'OCT2',
        'type' => ProteinIdentifier::TYPE_NAME,
        'state' => ProteinIdentifier::STATE_NEW,
    ]);

    $this->getJson("/api/v1/proteins/{$protein->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $protein->id)
        ->assertJsonPath('data.identifiers.0.value', 'OCT2')
        ->assertJsonPath('data.identifiers.0.verified', false);
});

test('proteins show returns 404 for an unknown protein', function () {
    $this->getJson('/api/v1/proteins/999999')
        ->assertNotFound();
});

test('proteins stats returns aggregate counts', function () {
    $protein = createApiProtein();
    createApiActiveInteraction(['protein' => $protein]);

    $this->getJson("/api/v1/proteins/{$protein->id}/stats")
        ->assertOk()
        ->assertJsonPath('data.protein.id', $protein->id)
        ->assertJsonPath('data.total.interactions_active', 1);
});

test('proteins categories returns the full category tree with items', function () {
    $root = createApiRootCategory(Category::TYPE_PROTEIN, 'Transporters');
    $protein = createApiProtein([], $root);

    $this->getJson('/api/v1/proteins/categories')
        ->assertOk()
        ->assertJsonPath('data.0.id', $root->id)
        ->assertJsonPath('data.0.items.0.id', $protein->id);
});

test('protein interactions returns a live paginated interaction listing, not a file download', function () {
    $protein = createApiProtein();
    createApiActiveInteraction(['protein' => $protein]);

    $this->getJson("/api/v1/proteins/{$protein->id}/interactions")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonStructure([
            'data' => [['protein', 'temperature', 'ph', 'km', 'ec50', 'ki', 'ic50']],
            'meta',
        ])
        ->assertJsonPath('data.0.protein.uniprot_id', $protein->uniprot_id);
});

test('protein interactions returns an empty list rather than 404 when there are none', function () {
    $protein = createApiProtein();

    $this->getJson("/api/v1/proteins/{$protein->id}/interactions")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
