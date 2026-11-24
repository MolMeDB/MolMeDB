<?php

require_once __DIR__.'/filament_prediction_helpers.php';

use App\Enums\PermissionEnums;
use App\Filament\Resources\Predictions\PredictionResource;
use App\Filament\Resources\PredictionStatsResource;
use App\Filament\Resources\PredictionStatsResource\Pages\ListPredictionStats;
use App\Filament\Resources\PredictionStatsResource\Pages\ViewPredictionStats;
use App\Filament\Widgets\PredictionLiveStatsWidget;
use App\Models\User;
use Livewire\Livewire;
use Modules\PredictionWorkers\Models\PredictionStat;

/*
 * Daily snapshots of the prediction server statistics and the dashboard
 * widget with the newest one, for holders of predictions.manage only.
 */

beforeEach(function () {
    seedFilamentPredictionPermissions();
});

/**
 * A snapshot payload with two queued steps and jobs running in every stage.
 *
 * @return array<string, mixed>
 */
function filamentPredictionStatsPayload(string $cosmoCalculationId = 'calc-running', string $moleculeId = 'mol-running'): array
{
    return [
        'queue' => ['rdkit' => 4, 'cosmo' => 2],
        'running' => [
            'molecule_steps' => [
                ['step' => 'rdkit', 'molecule_id' => $moleculeId, 'canonical_smiles' => 'CCO'],
                ['step' => 'conformers', 'molecule_id' => 'mol-other', 'canonical_smiles' => 'CCN'],
            ],
            'conformer_steps' => [
                ['step' => 'optimization-orca', 'molecule_id' => 'mol-other', 'canonical_smiles' => 'CCN'],
            ],
            'cosmo' => [
                ['calculation_id' => $cosmoCalculationId, 'molecule_id' => 'mol-cosmo', 'canonical_smiles' => 'CCCl', 'membrane_key' => 'dopc', 'temperature_c' => 25.0, 'heartbeat_age_seconds' => 30],
            ],
        ],
        'periods' => [],
    ];
}

function createFilamentPredictionStat(string $date, array $payload = [], string $serverUrl = 'https://predictor.test'): PredictionStat
{
    return PredictionStat::query()->create([
        'server_url' => $serverUrl,
        'stats_date' => $date,
        'payload' => $payload,
        'fetched_at' => now(),
    ]);
}

test('prediction stats are forbidden without predictions.manage', function () {
    $stat = createFilamentPredictionStat('2026-11-20', filamentPredictionStatsPayload());
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTION_DATASET_MANAGE_ALL, PermissionEnums::SYSTEM_MONITOR]));

    Livewire::test(ListPredictionStats::class)->assertForbidden();
    Livewire::test(ViewPredictionStats::class, ['record' => $stat->getRouteKey()])->assertForbidden();
});

test('the stats list shows the newest snapshot first and searches by server', function () {
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTIONS_MANAGE]));
    $older = createFilamentPredictionStat('2026-11-19');
    $newer = createFilamentPredictionStat('2026-11-20');
    $otherServer = createFilamentPredictionStat('2026-11-18', serverUrl: 'https://backup-predictor.test');

    Livewire::test(ListPredictionStats::class)
        ->assertCanSeeTableRecords([$newer, $older, $otherServer], inOrder: true)
        ->assertTableActionHasUrl('view', PredictionStatsResource::getUrl('view', ['record' => $newer]), $newer)
        ->searchTable('backup')
        ->assertCanSeeTableRecords([$otherServer])
        ->assertCanNotSeeTableRecords([$newer, $older]);
});

test('a snapshot detail shows the running jobs with links to their predictions', function () {
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTIONS_MANAGE]));
    ['prediction' => $cosmoPrediction] = createPredictionDataset(User::factory()->create(), ['remote_calculation_id' => 'calc-running']);
    $stat = createFilamentPredictionStat('2026-11-20', filamentPredictionStatsPayload());

    Livewire::test(ViewPredictionStats::class, ['record' => $stat->getRouteKey()])
        ->assertOk()
        ->assertSee('https://predictor.test')
        ->assertSee('CCCl')
        ->assertSee(PredictionResource::getUrl('edit', ['record' => $cosmoPrediction]), escape: false)
        ->assertSee('Not in DB');
});

test('live charts count the queued and running jobs per step', function () {
    $stat = createFilamentPredictionStat('2026-11-20', filamentPredictionStatsPayload());

    expect(PredictionStatsResource::liveCharts($stat))->toBe([
        ['title' => 'Queue (waiting)', 'caption' => 'queued', 'values' => ['rdkit' => 4, 'cosmo' => 2]],
        ['title' => 'Running now', 'caption' => 'running', 'values' => ['rdkit' => 1, 'conformers' => 1, 'optimization-orca' => 1, 'cosmo' => 1]],
    ])
        ->and(PredictionStatsResource::liveCharts(null))->toBe([
            ['title' => 'Queue (waiting)', 'caption' => 'queued', 'values' => []],
            ['title' => 'Running now', 'caption' => 'running', 'values' => ['cosmo' => 0]],
        ]);
});

test('the dashboard widget shows the newest snapshot with a link to its detail', function () {
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTIONS_MANAGE]));
    createFilamentPredictionStat('2026-11-19', ['queue' => ['rdkit' => 99]]);
    $newest = createFilamentPredictionStat('2026-11-20', filamentPredictionStatsPayload());

    Livewire::test(PredictionLiveStatsWidget::class)
        ->assertOk()
        ->assertSet('detailUrl', PredictionStatsResource::getUrl('view', ['record' => $newest]))
        ->assertSet('charts', PredictionStatsResource::liveCharts($newest))
        ->assertSee('View details');
});

test('the dashboard widget renders before the first snapshot is fetched', function () {
    $this->actingAs(createFilamentPredictionUser([PermissionEnums::PREDICTIONS_MANAGE]));

    Livewire::test(PredictionLiveStatsWidget::class)
        ->assertOk()
        ->assertSet('detailUrl', null)
        ->assertSet('lastUpdatedAt', null)
        ->assertSee('Not fetched yet')
        ->assertDontSee('View details');
});
