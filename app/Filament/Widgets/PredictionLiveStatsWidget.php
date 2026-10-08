<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\PredictionStatsResource;
use Carbon\CarbonInterface;
use Filament\Widgets\Widget;
use Modules\PredictionWorkers\Models\PredictionStat;

class PredictionLiveStatsWidget extends Widget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.prediction-live-stats';

    /**
     * @var array<int, array{title: string, caption: string, values: array<string, int>}>
     */
    public array $charts = [];

    public ?CarbonInterface $lastUpdatedAt = null;

    public ?string $detailUrl = null;

    public function mount(): void
    {
        $record = PredictionStat::query()->newest()->first();

        // No daily snapshot fetched yet (e.g. fresh install) - the charts
        // then render every step as 0.
        $this->charts = PredictionStatsResource::liveCharts($record);
        $this->lastUpdatedAt = $record?->fetched_at;
        $this->detailUrl = $record ? PredictionStatsResource::getUrl('view', ['record' => $record]) : null;
    }
}
