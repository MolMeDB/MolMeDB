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

/**
 * @return array<int, array<int, string|null>>
 */
function structureExportRows(string $csv): array
{
    return array_map(
        fn (string $line): array => str_getcsv($line, ';', '"', '\\'),
        array_values(array_filter(explode("\n", $csv))),
    );
}

test('the CSV export of passive interactions of a structure lists its values and both references', function () {
    $structure = createApiStructure(['identifier' => 'MM00041']);
    $primary = createApiPublication(['doi' => '10.1000/primary']);
    $secondary = createApiPublication(['doi' => '10.1000/secondary']);
    $dataset = createApiDataset(['publication' => $secondary]);
    createApiPassiveInteraction([
        'structure' => $structure,
        'dataset' => $dataset,
        'publication' => $primary,
        'logperm' => -5.25,
        'note' => '=HYPERLINK("https://evil.example")',
    ]);
    createApiPassiveInteraction();

    $response = $this->get("/export/structure/{$structure->id}/passiveInteractions")->assertOk();
    $rows = structureExportRows($response->streamedContent());
    $column = fn (string $name): int|false => array_search($name, $rows[0], true);

    expect($rows)->toHaveCount(2)
        ->and($response->headers->get('Content-Disposition'))->toContain('MM00041_passiveInteractions.csv')
        ->and($rows[1][$column('Identifier')] ?? null)->toBe('MM00041')
        ->and($response->streamedContent())->toContain('-5.25')
        ->and($response->streamedContent())->toContain('10.1000/primary')
        ->and($response->streamedContent())->toContain('10.1000/secondary')
        ->and($response->streamedContent())->toContain("'=HYPERLINK");
});

test('a structure without interactions exports only the header', function () {
    $structure = createApiStructure();

    $rows = structureExportRows($this->get("/export/structure/{$structure->id}/passiveInteractions")->assertOk()->streamedContent());

    expect($rows)->toHaveCount(1);
});

test('exporting an unknown structure answers not found', function () {
    $this->get('/export/structure/999999/passiveInteractions')->assertNotFound();
});
