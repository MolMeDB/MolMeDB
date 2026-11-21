<?php

require_once __DIR__.'/api_test_helpers.php';

use App\Models\Category;
use App\Models\Dataset;
use App\Models\InteractionPassive;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ids of the interactions a listing returns.
 *
 * @return array<int, int>
 */
function listedInteractionIds(string $uri): array
{
    return test()->getJson($uri)->assertOk()->json('data.*.id');
}

test('a passive interaction links its structure, membrane, method and RDF resource', function () {
    $structure = createApiStructure(['identifier' => 'MM00040']);
    $interaction = createApiPassiveInteraction(['structure' => $structure]);

    $this->getJson('/api/v1/interactions/passive')
        ->assertOk()
        ->assertJsonPath('data.0.id', $interaction->id)
        ->assertJsonPath('data.0.url', "https://molmedb.upol.cz/api/v1/interactions/passive/{$interaction->id}")
        ->assertJsonPath('data.0.rdf', "https://rdf.molmedb.upol.cz/interaction/int{$interaction->id}")
        ->assertJsonPath('data.0.structure.identifier', 'MM00040')
        ->assertJsonPath('data.0.structure.url', 'https://molmedb.upol.cz/api/v1/structures/MM00040')
        ->assertJsonPath('data.0.membrane.id', $interaction->dataset->membrane_id)
        ->assertJsonPath('data.0.method.id', $interaction->dataset->method_id)
        ->assertJsonPath('data.0.logperm', 1.5);
});

test('an active interaction names its type and links its protein', function () {
    $protein = createApiProtein(['uniprot_id' => 'O15245']);
    $category = Category::factory()->create(['title' => 'Substrate', 'type' => Category::TYPE_ACTIVE_INTERACTION, 'parent_id' => -1]);
    $interaction = createApiActiveInteraction(['protein' => $protein, 'category' => $category]);

    $this->getJson('/api/v1/interactions/active')
        ->assertOk()
        ->assertJsonPath('data.0.id', $interaction->id)
        ->assertJsonPath('data.0.rdf', "https://rdf.molmedb.upol.cz/transporter/tra{$interaction->id}")
        ->assertJsonPath('data.0.type', 'Substrate')
        ->assertJsonPath('data.0.protein.id', $protein->id)
        ->assertJsonPath('data.0.protein.uniprot_id', 'O15245')
        ->assertJsonPath('data.0.protein.url', "https://molmedb.upol.cz/api/v1/proteins/{$protein->id}");
});

test('a single interaction is found by its id', function () {
    $passive = createApiPassiveInteraction();
    $active = createApiActiveInteraction();

    $this->getJson("/api/v1/interactions/passive/{$passive->id}")->assertOk()->assertJsonPath('data.id', $passive->id);
    $this->getJson("/api/v1/interactions/active/{$active->id}")->assertOk()->assertJsonPath('data.id', $active->id);
    $this->getJson('/api/v1/interactions/passive/999999')->assertNotFound();
    $this->getJson("/api/v1/interactions/active/{$passive->id}0")->assertNotFound();
});

test('interactions are listed in the order of their ids', function () {
    $ids = collect(range(1, 3))->map(fn () => createApiPassiveInteraction()->id)->all();

    expect(listedInteractionIds('/api/v1/interactions/passive'))->toBe($ids);
});

test('deleted interactions, interactions of deleted datasets and of structures without identifier are not public', function () {
    $visible = createApiPassiveInteraction();

    createApiPassiveInteraction()->delete();

    // Bypasses the cascade of Dataset::delete() to cover the join itself.
    $orphaned = createApiPassiveInteraction();
    DB::table('datasets')->where('id', $orphaned->dataset_id)->update(['deleted_at' => now()]);

    $pending = createApiPassiveInteraction(['structure' => createApiStructure(['identifier' => null])]);

    expect(listedInteractionIds('/api/v1/interactions/passive'))->toBe([$visible->id]);

    $this->getJson("/api/v1/interactions/passive/{$orphaned->id}")->assertNotFound();
    $this->getJson("/api/v1/interactions/passive/{$pending->id}")->assertNotFound();
});

test('passive interactions are filtered by structure, membrane and method', function () {
    $structure = createApiStructure(['identifier' => 'MM00040']);
    $membrane = createApiMembrane();
    $method = createApiMethod();
    $match = createApiPassiveInteraction([
        'structure' => $structure,
        'dataset' => createApiDataset(['membrane' => $membrane, 'method' => $method]),
    ]);
    createApiPassiveInteraction(['structure' => $structure]);
    createApiPassiveInteraction(['dataset' => createApiDataset(['membrane' => $membrane, 'method' => $method])]);

    expect(listedInteractionIds("/api/v1/interactions/passive?structure=MM00040&membrane={$membrane->id}&method={$method->id}"))
        ->toBe([$match->id])
        ->and(listedInteractionIds("/api/v1/interactions/passive?membrane={$membrane->id}"))->toHaveCount(2)
        ->and(listedInteractionIds('/api/v1/interactions/passive?structure=MM00040'))->toHaveCount(2);
});

test('the publication filter matches the primary reference and the reference of the dataset', function () {
    $publication = createApiPublication();
    $primary = createApiPassiveInteraction(['publication' => $publication]);
    $secondary = createApiPassiveInteraction(['dataset' => createApiDataset(['publication' => $publication])]);
    createApiPassiveInteraction();

    expect(listedInteractionIds("/api/v1/interactions/passive?publication={$publication->id}"))
        ->toBe([$primary->id, $secondary->id]);
});

test('passive interactions are filtered by conditions and value ranges', function (string $query, array $expected) {
    $interactions = [
        'cold' => createApiPassiveInteraction(['temperature' => 25, 'ph' => 7.4, 'charge' => '0', 'logperm' => -5.5, 'gwat' => null]),
        'warm' => createApiPassiveInteraction(['temperature' => 37, 'ph' => 6.5, 'charge' => '+1', 'logperm' => -4.2, 'gwat' => -3.1]),
        'charged' => createApiPassiveInteraction(['temperature' => 27, 'ph' => 7.0, 'charge' => '-1', 'logperm' => null, 'gwat' => -1.0]),
    ];

    expect(listedInteractionIds("/api/v1/interactions/passive?{$query}"))
        ->toBe(collect($expected)->map(fn (string $key): int => $interactions[$key]->id)->all());
})->with([
    'temperature range' => ['temperature_min=26&temperature_max=40', ['warm', 'charged']],
    'pH range' => ['ph_max=7.0', ['warm', 'charged']],
    'charge' => ['charge=-1', ['charged']],
    'unsigned charge matches the signed form' => ['charge=1', ['warm']],
    'with a value' => ['with_value=gwat', ['warm', 'charged']],
    'value range' => ['logperm_min=-5&logperm_max=-4', ['warm']],
    'conditions and a value range combined' => ['with_value=logperm&temperature_max=30&logperm_max=-5', ['cold']],
]);

test('membrane, method and LogPerm range combine', function () {
    $membrane = createApiMembrane();
    $method = createApiMethod();
    $dataset = createApiDataset(['membrane' => $membrane, 'method' => $method]);
    $match = createApiPassiveInteraction(['dataset' => $dataset, 'logperm' => -5.2]);
    createApiPassiveInteraction(['dataset' => $dataset, 'logperm' => -7.0]);
    createApiPassiveInteraction(['dataset' => createApiDataset(['membrane' => $membrane]), 'logperm' => -5.2]);

    expect(listedInteractionIds("/api/v1/interactions/passive?membrane={$membrane->id}&method={$method->id}&logperm_min=-6&logperm_max=-5"))
        ->toBe([$match->id]);
});

test('active interactions are filtered by protein, UniProt id, type and values', function () {
    $oct1 = createApiProtein(['uniprot_id' => 'O15245']);
    $substrate = Category::factory()->create(['title' => 'Substrate', 'type' => Category::TYPE_ACTIVE_INTERACTION, 'parent_id' => -1]);
    $inhibitor = Category::factory()->create(['title' => 'Substrate + inhibitor', 'type' => Category::TYPE_ACTIVE_INTERACTION, 'parent_id' => -1]);

    $a = createApiActiveInteraction(['protein' => $oct1, 'category' => $substrate, 'km' => 4.5, 'ki' => null, 'temperature' => 37]);
    $b = createApiActiveInteraction(['protein' => $oct1, 'category' => $inhibitor, 'km' => null, 'ki' => 6.1, 'temperature' => 25]);
    $c = createApiActiveInteraction(['category' => $substrate, 'km' => 5.0, 'ki' => null]);

    expect(listedInteractionIds("/api/v1/interactions/active?protein={$oct1->id}"))->toBe([$a->id, $b->id])
        ->and(listedInteractionIds('/api/v1/interactions/active?uniprot=o15245'))->toBe([$a->id, $b->id])
        ->and(listedInteractionIds('/api/v1/interactions/active?type=substrate'))->toBe([$a->id, $c->id])
        ->and(listedInteractionIds('/api/v1/interactions/active?type='.urlencode('Substrate + inhibitor')))->toBe([$b->id])
        ->and(listedInteractionIds('/api/v1/interactions/active?with_value=ki'))->toBe([$b->id])
        ->and(listedInteractionIds('/api/v1/interactions/active?km_min=4.8'))->toBe([$c->id])
        ->and(listedInteractionIds('/api/v1/interactions/active?temperature_min=30&uniprot=O15245'))->toBe([$a->id]);
});

test('invalid filters are rejected', function (string $uri, string $field) {
    $this->getJson($uri)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'unknown value' => ['/api/v1/interactions/passive?with_value=km', 'with_value'],
    'non-numeric range' => ['/api/v1/interactions/passive?logperm_min=low', 'logperm_min'],
    'non-numeric membrane' => ['/api/v1/interactions/passive?membrane=DOPC', 'membrane'],
    'too many per page' => ['/api/v1/interactions/passive?per_page=101', 'per_page'],
    'active value on passive' => ['/api/v1/interactions/active?with_value=logperm', 'with_value'],
    'non-numeric protein' => ['/api/v1/interactions/active?protein=OCT1', 'protein'],
]);

test('pagination links keep the filters', function () {
    $membrane = createApiMembrane();
    $dataset = createApiDataset(['membrane' => $membrane]);
    createApiPassiveInteraction(['dataset' => $dataset]);
    createApiPassiveInteraction(['dataset' => $dataset]);

    $this->getJson("/api/v1/interactions/passive?membrane={$membrane->id}&per_page=1")
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('links.next', "http://localhost/api/v1/interactions/passive?membrane={$membrane->id}&per_page=1&page=2");
});

test('entity listings are interactions of the entity with the same filters', function () {
    $structure = createApiStructure(['identifier' => 'MM00040']);
    $membrane = createApiMembrane();
    $method = createApiMethod();
    $publication = createApiPublication();
    $protein = createApiProtein();
    $dataset = createApiDataset(['membrane' => $membrane, 'method' => $method, 'publication' => $publication]);
    $passive = createApiPassiveInteraction(['structure' => $structure, 'dataset' => $dataset, 'logperm' => -5.0]);
    createApiPassiveInteraction(['dataset' => $dataset, 'logperm' => -8.0]);
    createApiPassiveInteraction(['structure' => $structure]);
    $active = createApiActiveInteraction(['structure' => $structure, 'protein' => $protein, 'publication' => $publication]);
    createApiActiveInteraction(['structure' => $structure]);

    expect(listedInteractionIds("/api/v1/membranes/{$membrane->id}/interactions?logperm_min=-6"))->toBe([$passive->id])
        ->and(listedInteractionIds("/api/v1/methods/{$method->id}/interactions?structure=MM00040"))->toBe([$passive->id])
        ->and(listedInteractionIds("/api/v1/publications/{$publication->id}/interactions/passive?logperm_min=-6"))->toBe([$passive->id])
        ->and(listedInteractionIds("/api/v1/publications/{$publication->id}/interactions/active"))->toBe([$active->id])
        ->and(listedInteractionIds("/api/v1/proteins/{$protein->id}/interactions"))->toBe([$active->id])
        ->and(listedInteractionIds("/api/v1/structures/MM00040/interactions/passive?membrane={$membrane->id}"))->toBe([$passive->id])
        ->and(listedInteractionIds("/api/v1/structures/MM00040/interactions/active?protein={$protein->id}"))->toBe([$active->id]);
});

test('stats count the same interactions the listings return', function () {
    $publication = createApiPublication();
    $membrane = createApiMembrane();
    createApiPassiveInteraction(['publication' => $publication, 'dataset' => createApiDataset(['membrane' => $membrane])]);
    createApiPassiveInteraction(['dataset' => createApiDataset(['membrane' => $membrane, 'publication' => $publication])]);
    createApiPassiveInteraction(['dataset' => createApiDataset(['membrane' => $membrane]), 'structure' => createApiStructure(['identifier' => null])]);

    $this->getJson("/api/v1/publications/{$publication->id}/stats")->assertJsonPath('data.total.interactions_passive', 2);
    $this->getJson("/api/v1/membranes/{$membrane->id}/stats")
        ->assertJsonPath('data.total.interactions_passive', 2)
        ->assertJsonPath('data.total.structures', 2);
});

test('the number of queries of a page does not grow with its size', function (string $type) {
    $queries = function (int $count) use ($type): int {
        InteractionPassive::query()->forceDelete();
        DB::table('interactions_active')->delete();
        Cache::flush();

        foreach (range(1, $count) as $i) {
            $type === 'passive' ? createApiPassiveInteraction() : createApiActiveInteraction();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson("/api/v1/interactions/{$type}?per_page=100")->assertOk()->assertJsonCount($count, 'data');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($queries(12))->toBe($queries(2))->toBeLessThanOrEqual(12);
})->with(['passive', 'active']);
