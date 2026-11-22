<?php

namespace App\Filament\Resources\Predictions\Pages;

use App\Enums\IconEnums;
use App\Filament\Resources\Predictions\PredictionResource;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\PredictionWorkers\Enums\RemotePredictionStep;
use Modules\PredictionWorkers\Models\Prediction;
use Throwable;

class EditPrediction extends EditRecord
{
    protected static string $resource = PredictionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('requeue')
                ->label('Requeue')
                ->icon(IconEnums::RELOAD->value)
                ->color('warning')
                ->disabled(fn (): bool => blank($this->record->remote_calculation_id)
                    && ! $this->record->canBePreparedForSubmissionAgain())
                ->requiresConfirmation()
                ->modalHeading('Requeue prediction request')
                ->modalDescription(fn (): string => $this->record->canBePreparedForSubmissionAgain()
                    ? 'The remote service never accepted this prediction. It will be prepared again and submitted on the next run of the worker.'
                    : 'The remote service will automatically find the first failed step and run it again.')
                ->action(function (): void {
                    $this->requeuePrediction($this->record);
                }),
            Actions\Action::make('forceRequeue')
                ->label('Force requeue')
                ->icon(IconEnums::RESTORE->value)
                ->color('danger')
                ->disabled(fn (): bool => blank($this->record->remote_calculation_id))
                ->requiresConfirmation()
                ->modalHeading('Force requeue prediction request')
                ->modalDescription('Select the step from which the remote calculation should be run again. Only steps already reached by this job are available.')
                ->schema([
                    Select::make('step')
                        ->label('Start from step')
                        ->options(fn (): array => $this->record->forceRequeueStepOptions())
                        ->required()
                        ->native(false),
                ])
                ->action(function (array $data): void {
                    $this->requeuePrediction(
                        $this->record,
                        RemotePredictionStep::from($data['step']),
                        true,
                    );
                }),
            // Actions\DeleteAction::make(),
        ];
    }

    private function requeuePrediction(
        Prediction $record,
        ?RemotePredictionStep $step = null,
        bool $force = false,
    ): void {
        try {
            $snapshot = $record->requeueAndStoreRemotePrediction($step, $force);
            $this->record = $record->refresh();
            $this->fillForm();

            Notification::make()
                ->title($force ? 'Prediction force requeued' : 'Prediction requeued')
                ->body(match (true) {
                    $force => 'Remote step: '.$step?->label(),
                    $snapshot === null => 'The prediction was prepared again and will be submitted on the next run of the worker.',
                    default => 'The first failed remote step was queued again.',
                })
                ->success()
                ->send();
        } catch (Throwable $throwable) {
            Notification::make()
                ->title('Prediction requeue failed')
                ->body($throwable->getMessage())
                ->danger()
                ->send();
        }
    }

    public function getTitle(): string
    {
        return 'Prediction request detail';
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Details';
    }

    public function getContentTabIcon(): ?string
    {
        return IconEnums::VIEW->value;
    }

    protected function getFormActions(): array
    {
        return []; // Nothing to edit
    }
}
