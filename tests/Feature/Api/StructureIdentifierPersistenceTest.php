<?php

require_once __DIR__.'/api_test_helpers.php';

use App\Models\Identifier;

function mergeIdentifierInto(string $obsoleteIdentifier, $survivor): void
{
    Identifier::create([
        'structure_id' => $survivor->id,
        'type' => Identifier::TYPE_MOLMEDB,
        'value' => $obsoleteIdentifier,
        'state' => Identifier::STATE_OBSOLETE,
    ]);
}

test('a merged identifier redirects permanently to the structure it was merged into', function () {
    $survivor = createApiStructure(['identifier' => 'MM00001']);
    mergeIdentifierInto('MM00099', $survivor);

    $this->getJson('/api/v1/structures/MM00099')
        ->assertStatus(301)
        ->assertHeader('Location', 'https://molmedb.upol.cz/api/v1/structures/MM00001')
        ->assertJsonPath('data.status', 'merged')
        ->assertJsonPath('data.replaced_by', 'MM00001');
});

test('merged identifier redirects keep the sub-resource and query', function () {
    $survivor = createApiStructure(['identifier' => 'MM00001']);
    mergeIdentifierInto('MM00099', $survivor);

    $this->getJson('/api/v1/structures/MM00099/interactions/passive?per_page=5')
        ->assertStatus(301)
        ->assertHeader('Location', 'https://molmedb.upol.cz/api/v1/structures/MM00001/interactions/passive?per_page=5');
});

test('a removed structure answers 410 Gone with what is still known about it', function () {
    $structure = createApiStructure(['identifier' => 'MM00050']);
    $structure->delete();

    $this->getJson('/api/v1/structures/MM00050')
        ->assertStatus(410)
        ->assertJsonPath('data.identifier', 'MM00050')
        ->assertJsonPath('data.status', 'deleted')
        ->assertJsonPath('data.replaced_by', null);

    expect($this->getJson('/api/v1/structures/MM00050')->json('data.deleted_at'))->not->toBeNull();
});

test('an identifier that never existed answers 404', function () {
    $this->getJson('/api/v1/structures/MM99999')->assertNotFound();
});

test('the internal status endpoint tells the frontend what an identifier points to', function (string $identifier, string $status, ?string $replacedBy) {
    $survivor = createApiStructure(['identifier' => 'MM00001']);
    mergeIdentifierInto('MM00099', $survivor);
    createApiStructure(['identifier' => 'MM00050'])->delete();

    $this->getJson(apiRoutePath("api/structure/{$identifier}/status"))
        ->assertOk()
        ->assertJsonPath('data.identifier', $identifier)
        ->assertJsonPath('data.status', $status)
        ->assertJsonPath('data.replaced_by', $replacedBy);
})->with([
    'active' => ['MM00001', 'active', null],
    'merged' => ['MM00099', 'merged', 'MM00001'],
    'deleted' => ['MM00050', 'deleted', null],
    'unknown' => ['MM99999', 'unknown', null],
]);
