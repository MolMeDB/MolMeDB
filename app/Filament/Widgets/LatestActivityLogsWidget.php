<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Models\BaseModel;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

class LatestActivityLogsWidget extends TableWidget
{
    public string $scope = BaseModel::ACTIVITY_LOG_SYSTEM;

    protected int|string|array $columnSpan = 'full';

    /**
     * Shown only to users the Activity policy lets list these records.
     */
    public static function canView(): bool
    {
        return Gate::allows('viewAny', Activity::class);
    }

    protected static ?int $sort = 90;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Latest activity logs')
            ->query(fn (): Builder => $this->activityQuery())
            ->paginated(false)
            ->columns([
                TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->dateTimeTooltip(),
                TextColumn::make('log_name')
                    ->label('Log')
                    ->badge()
                    ->color(fn (?string $state): string => $state === BaseModel::ACTIVITY_LOG_SYSTEM ? 'danger' : 'gray')
                    ->placeholder('-'),
                TextColumn::make('event')
                    ->badge()
                    ->color('info')
                    ->placeholder('-'),
                TextColumn::make('description')
                    ->limit(90)
                    ->tooltip(fn (Activity $record): string => $record->description),
                TextColumn::make('subject')
                    ->state(fn (Activity $record): string => ActivityLogResource::formatMorph($record->subject_type, $record->subject_id))
                    ->toggleable(),
            ])
            ->headerActions([
                Action::make('systemLogs')
                    ->label('System')
                    ->icon(Heroicon::OutlinedComputerDesktop)
                    ->color($this->scope === BaseModel::ACTIVITY_LOG_SYSTEM ? 'primary' : 'gray')
                    ->action(function (): void {
                        $this->setScope(BaseModel::ACTIVITY_LOG_SYSTEM);
                    }),
                Action::make('allLogs')
                    ->label('All')
                    ->icon(Heroicon::OutlinedListBullet)
                    ->color($this->scope === 'all' ? 'primary' : 'gray')
                    ->action(function (): void {
                        $this->setScope('all');
                    }),
                Action::make('openLogs')
                    ->label('Open logs')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (): string => ActivityLogResource::getUrl('index')),
            ]);
    }

    public function setScope(string $scope): void
    {
        $this->scope = $scope;
        $this->resetTable();
    }

    protected function activityQuery(): Builder
    {
        return Activity::query()
            ->when(
                $this->scope === BaseModel::ACTIVITY_LOG_SYSTEM,
                fn (Builder $query): Builder => $query->where('log_name', BaseModel::ACTIVITY_LOG_SYSTEM),
            )
            ->latest('created_at')
            ->limit(8);
    }
}
