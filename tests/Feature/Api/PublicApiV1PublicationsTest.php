<?php

require_once __DIR__.'/api_test_helpers.php';

use App\Models\Author;
use App\Models\File;

test('publications index returns paginated publications', function () {
    createApiPublication(['title' => 'Example publication one']);
    createApiPublication(['title' => 'Example publication two']);

    $this->getJson('/api/v1/publications')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [['id', 'citation', 'title', 'doi', 'pmid', 'year']],
            'links',
            'meta',
        ]);
});

test('publications index does not include detail-only fields', function () {
    createApiPublication();

    $response = $this->getJson('/api/v1/publications');

    $response->assertOk();
    expect($response->json('data.0'))->not->toHaveKeys(['journal', 'volume', 'issue', 'page', 'authors']);
});

test('publications index filters by free-text query', function () {
    createApiPublication(['title' => 'Caffeine metabolism']);
    createApiPublication(['title' => 'Unrelated topic']);

    $this->getJson('/api/v1/publications?query=Caffeine')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Caffeine metabolism');
});

test('publications show returns full detail including authors', function () {
    $publication = createApiPublication([
        'journal' => 'Journal of Examples',
        'volume' => '12',
        'issue' => '3',
        'page' => '45-50',
    ]);
    $author = Author::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'full_name' => 'Jane Doe']);
    $publication->authors()->attach($author->id);

    $this->getJson("/api/v1/publications/{$publication->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $publication->id)
        ->assertJsonPath('data.journal', 'Journal of Examples')
        ->assertJsonPath('data.volume', '12')
        ->assertJsonPath('data.authors.0.full_name', 'Jane Doe');
});

test('publications show returns 404 for an unknown publication', function () {
    $this->getJson('/api/v1/publications/999999')
        ->assertNotFound();
});

test('publications stats returns aggregate counts', function () {
    $publication = createApiPublication();
    createApiPassiveInteraction(['publication' => $publication]);
    createApiActiveInteraction(['publication' => $publication]);

    $this->getJson("/api/v1/publications/{$publication->id}/stats")
        ->assertOk()
        ->assertJsonPath('data.publication.id', $publication->id)
        ->assertJsonPath('data.total.interactions_passive', 1)
        ->assertJsonPath('data.total.interactions_active', 1);
});

test('publication passive interactions downloads the latest export file', function () {
    $publication = createApiPublication();
    createApiExportFile($publication, File::TYPE_EXPORT_INTERACTIONS_PASSIVE_PUBLICATION, 'passive export contents');

    // A plain ->get() defaults to a browser-like Accept header (includes
    // text/html), which would route into the explorer page instead of
    // calling the controller — force JSON/binary negotiation explicitly.
    $response = $this->getJson("/api/v1/publications/{$publication->id}/interactions/passive/export");

    $response->assertOk();
    expect($response->streamedContent())->toBe('passive export contents');
});

test('publication active interactions downloads the latest export file', function () {
    $publication = createApiPublication();
    createApiExportFile($publication, File::TYPE_EXPORT_INTERACTIONS_ACTIVE_PUBLICATION, 'active export contents');

    $response = $this->getJson("/api/v1/publications/{$publication->id}/interactions/active/export");

    $response->assertOk();
    expect($response->streamedContent())->toBe('active export contents');
});

test('publication interactions returns 404 when no export exists yet', function () {
    $publication = createApiPublication();

    $this->getJson("/api/v1/publications/{$publication->id}/interactions/passive/export")
        ->assertNotFound();
});
