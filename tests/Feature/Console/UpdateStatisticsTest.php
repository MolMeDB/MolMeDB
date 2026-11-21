<?php

require_once __DIR__.'/../Api/api_test_helpers.php';

use App\Models\Category;
use App\Models\Config;
use App\Models\Dataset;
use App\Models\Identifier;
use App\Models\InteractionActive;
use App\Models\InteractionPassive;
use App\Models\Protein;
use App\Models\Publication;
use App\Models\Stats;
use App\Models\Structure;
use Illuminate\Support\Carbon;

/*
 * stats:update-all recomputes the six cached statistics shown on the
 * statistics page. It runs at most once a week unless forced.
 */

const STATS_LAST_RUN_KEY = 'command:stats:update-all:last-run';

/**
 * Shared catalogue records, so the interactions created by a test do not
 * bring their own membranes, methods and proteins with them.
 *
 * @return array{passiveDataset: Dataset, activeDataset: Dataset, protein: Protein, activeCategory: Category}
 */
function statisticsFixture(): array
{
    $membrane = createApiMembrane();
    $method = createApiMethod();

    return [
        'passiveDataset' => createApiDataset(['type' => Dataset::TYPE_PASSIVE, 'membrane' => $membrane, 'method' => $method]),
        'activeDataset' => createApiDataset(['type' => Dataset::TYPE_ACTIVE, 'membrane' => $membrane, 'method' => $method]),
        'protein' => createApiProtein(['uniprot_id' => 'P00001']),
        'activeCategory' => Category::factory()->create([
            'title' => 'Carrier-mediated',
            'type' => Category::TYPE_ACTIVE_INTERACTION,
            'parent_id' => -1,
        ]),
    ];
}

/**
 * @param  array{passiveDataset: Dataset, activeDataset: Dataset, protein: Protein, activeCategory: Category}  $fixture
 * @param  array<string, mixed>  $attributes
 */
function statisticsPassiveInteraction(array $fixture, Structure $structure, Publication $publication, array $attributes = []): InteractionPassive
{
    return createApiPassiveInteraction([
        'structure' => $structure,
        'dataset' => $fixture['passiveDataset'],
        'publication' => $publication,
        ...$attributes,
    ]);
}

/**
 * @param  array{passiveDataset: Dataset, activeDataset: Dataset, protein: Protein, activeCategory: Category}  $fixture
 * @param  array<string, mixed>  $attributes
 */
function statisticsActiveInteraction(array $fixture, Structure $structure, Publication $publication, array $attributes = []): InteractionActive
{
    return createApiActiveInteraction([
        'structure' => $structure,
        'dataset' => $fixture['activeDataset'],
        'publication' => $publication,
        'protein' => $fixture['protein'],
        'category' => $fixture['activeCategory'],
        // Unused because the protein is given, but it stops the helper from adding a protein group of its own.
        'protein_category' => $fixture['activeCategory'],
        ...$attributes,
    ]);
}

function runStatisticsUpdate(bool $force = true): void
{
    test()->artisan('stats:update-all', $force ? ['--force' => true] : [])->assertSuccessful();
}

/**
 * @return array<string, array{value1: int, value2: int|null}>
 */
function barChartByName(array $content): array
{
    return collect($content)
        ->mapWithKeys(fn (array $item): array => [$item['name'] => ['value1' => $item['value1'], 'value2' => $item['value2']]])
        ->all();
}

afterEach(function () {
    Carbon::setTestNow();
});

test('a run within the same week as the last one changes nothing', function () {
    Config::set(STATS_LAST_RUN_KEY, (string) now()->subMinute());
    createApiStructure();

    $this->artisan('stats:update-all')
        ->expectsOutputToContain('Skipping')
        ->assertSuccessful();

    expect(Stats::count())->toBe(0);
});

test('a regular run after an older one recomputes the statistics and remembers when it ran', function () {
    Carbon::setTestNow('2026-11-18 10:00:00');
    Config::set(STATS_LAST_RUN_KEY, '2026-11-09 10:00:00');
    createApiStructure(['created_at' => '2026-03-05']);

    runStatisticsUpdate(force: false);

    expect(Stats::getCountStats()->total_structures)->toBe(1)
        ->and(Carbon::parse(Config::get(STATS_LAST_RUN_KEY))->equalTo(now()))->toBeTrue();
});

test('a forced run recomputes the statistics even right after a run and keeps the last run time', function () {
    Config::set(STATS_LAST_RUN_KEY, $lastRun = (string) now()->subMinute());
    createApiStructure();

    runStatisticsUpdate();

    expect(Stats::getCountStats()->total_structures)->toBe(1)
        ->and(Config::get(STATS_LAST_RUN_KEY))->toBe($lastRun);
});

test('the counts cover live records only', function () {
    $fixture = statisticsFixture();
    $publication = createApiPublication();
    [$first, $second] = [createApiStructure(), createApiStructure()];
    createApiStructure()->delete();

    statisticsPassiveInteraction($fixture, $first, $publication);
    statisticsPassiveInteraction($fixture, $second, $publication);
    statisticsPassiveInteraction($fixture, $second, $publication)->delete();
    statisticsActiveInteraction($fixture, $first, $publication);
    createApiProtein(['uniprot_id' => 'P00002'])->delete();

    runStatisticsUpdate();

    expect(Stats::getCountStats()->toArray())->toBe([
        'total_passive_interactions' => 2,
        'total_active_interactions' => 1,
        'total_structures' => 2,
        'total_membranes' => 1,
        'total_methods' => 1,
        'total_proteins' => 1,
    ]);
});

test('the history chart has a cumulative point per month up to the current one even for less than two years of data', function () {
    Carbon::setTestNow('2026-05-20 12:00:00');
    $fixture = statisticsFixture();
    $publication = createApiPublication();
    $first = createApiStructure(['created_at' => '2026-01-10 08:00:00']);
    $second = createApiStructure(['created_at' => '2026-03-15 08:00:00']);
    statisticsPassiveInteraction($fixture, $first, $publication, ['created_at' => '2026-02-03 08:00:00']);
    statisticsActiveInteraction($fixture, $second, $publication, ['created_at' => '2026-04-30 23:00:00']);

    runStatisticsUpdate();

    $point = fn (string $month, int $structures, int $interactions): array => [
        'date' => (int) Carbon::parse($month)->valueOf(),
        'value1' => $structures,
        'value2' => $interactions,
    ];

    expect(Stats::getByType(Stats::TYPE_INTERACTION_SUBSTANCE_HISTORY)->content)->toBe([
        $point('2026-02-01', 1, 1),
        $point('2026-03-01', 2, 1),
        $point('2026-04-01', 2, 2),
        $point('2026-05-01', 2, 2),
    ]);
});

test('a long history is sampled in even steps starting the month after the first structure', function () {
    Carbon::setTestNow('2026-11-20 12:00:00');
    createApiStructure(['created_at' => '2020-01-10']);
    createApiStructure(['created_at' => '2023-06-10']);

    runStatisticsUpdate();

    $points = collect(Stats::getByType(Stats::TYPE_INTERACTION_SUBSTANCE_HISTORY)->content);
    $months = $points->map(fn (array $item): string => Carbon::createFromTimestampMs($item['date'])->format('Y-m'));

    // 82 months from 02/2020 to 11/2026 inclusive, sampled every 82 / 22 = 3 months.
    expect($months->first())->toBe('2020-02')
        ->and($months->get(1))->toBe('2020-05')
        ->and($months->last())->toBe('2026-11')
        ->and($points->count())->toBe(28)
        ->and($points->firstWhere('date', Carbon::parse('2023-05-01')->valueOf())['value1'])->toBe(1)
        ->and($points->firstWhere('date', Carbon::parse('2023-08-01')->valueOf())['value1'])->toBe(2);
});

test('a sampled history always ends with the current month', function () {
    Carbon::setTestNow('2026-11-20 12:00:00');
    createApiStructure(['created_at' => '2018-08-10']);

    runStatisticsUpdate();

    $months = collect(Stats::getByType(Stats::TYPE_INTERACTION_SUBSTANCE_HISTORY)->content)
        ->map(fn (array $item): string => Carbon::createFromTimestampMs($item['date'])->format('Y-m'));

    // 99 months from 09/2018 to 11/2026, sampled every 4 months up to
    // 09/2026: 11/2026 is not on a step, so it is added at the end.
    expect($months->first())->toBe('2018-09')
        ->and($months->get(1))->toBe('2019-01')
        ->and($months->last())->toBe('2026-11')
        ->and($months->slice(-2, 1)->first())->toBe('2026-09');
});

test('the databases chart counts structures having a live identifier of each external database', function () {
    [$first, $second] = [createApiStructure(), createApiStructure()];
    createStatisticsIdentifier($first, Identifier::TYPE_PUBCHEM, '702');
    createStatisticsIdentifier($first, Identifier::TYPE_PUBCHEM, '703');
    createStatisticsIdentifier($second, Identifier::TYPE_PUBCHEM, '704');
    createStatisticsIdentifier($second, Identifier::TYPE_CHEMBL, 'CHEMBL545');
    createStatisticsIdentifier($second, Identifier::TYPE_DRUGBANK, 'DB00898')->delete();
    createStatisticsIdentifier($second, Identifier::TYPE_NAME, 'Ethanol');

    runStatisticsUpdate();

    expect(Stats::getByType(Stats::TYPE_DATABASES_BAR_COUNTS)->content)->toBe([
        ['name' => Identifier::enumType(Identifier::TYPE_PUBCHEM), 'value1' => 2, 'value2' => null],
        ['name' => Identifier::enumType(Identifier::TYPE_DRUGBANK), 'value1' => 0, 'value2' => null],
        ['name' => Identifier::enumType(Identifier::TYPE_CHEBI), 'value1' => 0, 'value2' => null],
        ['name' => Identifier::enumType(Identifier::TYPE_PDB), 'value1' => 0, 'value2' => null],
        ['name' => Identifier::enumType(Identifier::TYPE_CHEMBL), 'value1' => 1, 'value2' => null],
    ]);
});

function createStatisticsIdentifier(Structure $structure, int $type, string $value): Identifier
{
    return Identifier::create([
        'structure_id' => $structure->id,
        'type' => $type,
        'value' => $value,
        'state' => Identifier::STATE_VALIDATED,
    ]);
}

test('the proteins chart sums proteins and their interactions over each top-level protein group with all subgroups', function () {
    $fixture = statisticsFixture();
    $publication = createApiPublication();
    $structure = createApiStructure();

    $transporters = createApiRootCategory(Category::TYPE_PROTEIN, 'Transporters');
    $abc = createApiChildCategory($transporters, 'ABC');
    $abcb = createApiChildCategory($abc, 'ABCB');
    createApiRootCategory(Category::TYPE_PROTEIN, 'Receptors');
    createApiRootCategory(Category::TYPE_MEMBRANE, 'Not a protein group');

    $deepProtein = createApiProtein(['uniprot_id' => 'P10001'], $abcb);
    createApiProtein(['uniprot_id' => 'P10002'], $transporters);
    statisticsActiveInteraction($fixture, $structure, $publication, ['protein' => $deepProtein]);
    statisticsActiveInteraction($fixture, $structure, $publication, ['protein' => $deepProtein]);
    statisticsActiveInteraction($fixture, $structure, $publication);

    runStatisticsUpdate();

    expect(barChartByName(Stats::getByType(Stats::TYPE_PROTEIN_BAR_COUNTS)->content))->toBe([
        'Transporters' => ['value1' => 2, 'value2' => 2],
        'Receptors' => ['value1' => 0, 'value2' => 0],
    ]);
});

test('the publications chart counts interactions per five-year period from the oldest publication until now', function () {
    $fixture = statisticsFixture();
    $structure = createApiStructure();
    $older = createApiPublication(['year' => 2003]);
    $newer = createApiPublication(['year' => 2011]);
    statisticsPassiveInteraction($fixture, $structure, $older);
    statisticsActiveInteraction($fixture, $structure, $older);
    statisticsPassiveInteraction($fixture, $structure, $newer);

    runStatisticsUpdate();

    $content = Stats::getByType(Stats::TYPE_PUBLICATIONS_BY_YEAR_STATS)->content;
    $lastPeriodStart = (int) date('Y') - ((int) date('Y') % 5);

    expect(array_slice($content, 0, 3))->toBe([
        ['date' => '2000 - 2004', 'value1' => 2, 'value2' => null],
        ['date' => '2005 - 2009', 'value1' => 0, 'value2' => null],
        ['date' => '2010 - 2014', 'value1' => 1, 'value2' => null],
    ])
        ->and(end($content)['date'])->toBe($lastPeriodStart.' - '.($lastPeriodStart + 4))
        ->and(collect($content)->sum('value1'))->toBe(3);
});

test('the journals chart lists journals with more than a hundred interactions, the busiest first', function () {
    $fixture = statisticsFixture();
    $structure = createApiStructure();
    $journalA = createApiPublication(['journal' => 'Journal A']);
    $journalB = createApiPublication(['journal' => 'Journal B']);
    $journalC = createApiPublication(['journal' => 'Journal C']);

    foreach (range(1, 101) as $i) {
        statisticsPassiveInteraction($fixture, $structure, $journalA);
    }
    foreach (range(1, 60) as $i) {
        statisticsPassiveInteraction($fixture, $structure, $journalB);
    }
    foreach (range(1, 50) as $i) {
        statisticsActiveInteraction($fixture, $structure, $journalB);
    }
    foreach (range(1, 100) as $i) {
        statisticsPassiveInteraction($fixture, $structure, $journalC);
    }

    runStatisticsUpdate();

    expect(Stats::getByType(Stats::TYPE_PUBLICATIONS_BY_JOURNAL_STATS)->content)->toBe([
        ['name' => 'Journal B', 'value1' => 110, 'value2' => null],
        ['name' => 'Journal A', 'value1' => 101, 'value2' => null],
    ]);
});

test('an empty database yields empty statistics', function () {
    Carbon::setTestNow('2026-05-20 12:00:00');

    runStatisticsUpdate();

    expect(Stats::getCountStats()->total_structures)->toBe(0)
        ->and(Stats::getByType(Stats::TYPE_INTERACTION_SUBSTANCE_HISTORY)->content)->toBe([])
        ->and(Stats::getByType(Stats::TYPE_PROTEIN_BAR_COUNTS)->content)->toBe([])
        ->and(Stats::getByType(Stats::TYPE_PUBLICATIONS_BY_YEAR_STATS)->content)->toBe([])
        ->and(Stats::getByType(Stats::TYPE_PUBLICATIONS_BY_JOURNAL_STATS)->content)->toBe([]);
});
