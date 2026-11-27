<?php

namespace App\Filament\Widgets;

use App\Services\SystemHealth\PredictionServerAssessment;
use App\Services\SystemHealth\PredictionServerHealth;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Gate;
use Modules\PredictionWorkers\Models\PredictionStat;

/**
 * Whether the remote prediction server computes: its problems and warnings
 * and the replicas of its components, from the latest fetched statistics.
 */
class PredictionServerHealthWidget extends Widget
{
    /**
     * Shown to the users who see the prediction server statistics.
     */
    public static function canView(): bool
    {
        return Gate::allows('viewAny', PredictionStat::class);
    }

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.prediction-server-health';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $assessment = app(PredictionServerHealth::class)->assess();

        return [
            'assessment' => $assessment,
            'disabled' => $assessment->status === PredictionServerAssessment::STATUS_DISABLED,
        ];
    }
}
