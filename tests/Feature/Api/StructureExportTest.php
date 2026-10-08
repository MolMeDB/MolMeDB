<?php

require_once __DIR__.'/api_test_helpers.php';

use App\Models\Category;

test('the CSV export of active interactions of a structure names the type of each interaction', function () {
    $structure = createApiStructure(['identifier' => 'MM00040']);
    createApiActiveInteraction([
        'structure' => $structure,
        'category' => Category::factory()->create([
            'title' => 'Substrate',
            'type' => Category::TYPE_ACTIVE_INTERACTION,
            'parent_id' => -1,
        ]),
    ]);

    $response = $this->get("/export/structure/{$structure->id}/activeInteractions");

    $response->assertOk();
    $rows = array_map(
        fn (string $line): array => str_getcsv($line, ';', '"', '\\'),
        array_values(array_filter(explode("\n", $response->streamedContent()))),
    );
    $type = array_search('Type', $rows[0], true);

    expect($type)->not->toBeFalse()
        ->and($rows[1][$type])->toBe('Substrate');
});

test('the active interactions of a structure on its page carry the type of the interaction', function () {
    $structure = createApiStructure(['identifier' => 'MM00040']);
    createApiActiveInteraction([
        'structure' => $structure,
        'category' => Category::factory()->create([
            'title' => 'Inhibitor',
            'type' => Category::TYPE_ACTIVE_INTERACTION,
            'parent_id' => -1,
        ]),
    ]);

    $this->getJson('/api/interactions/active/structure/MM00040')
        ->assertOk()
        ->assertJsonPath('data.0.interaction_type', 'Inhibitor');
});
