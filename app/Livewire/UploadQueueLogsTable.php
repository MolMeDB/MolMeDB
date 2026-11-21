<?php

namespace App\Livewire;

use App\Enums\UploadQueueLogContextEnums;
use App\Models\UploadQueue;
use App\Models\User;
use App\ValueObjects\UploadQueueLog;
use BackedEnum;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

class UploadQueueLogsTable extends TableWidget implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    public ?UploadQueue $record = null;

    protected int|string|array $columnSpan = 2;

    public function getTableRecords(): EloquentCollection
    {
        $sortColumn = $this->getTableSortColumn() ?? 'timestamp';
        $sortDirection = $this->getTableSortDirection() ?? 'desc';

        // Enum columns (severity, type) are sorted by their values, enum cases cannot be compared.
        return $this->record->logs->sortBy(
            function (UploadQueueLog $log) use ($sortColumn): mixed {
                $value = $log->{$sortColumn};

                return $value instanceof BackedEnum ? $value->value : $value;
            },
            SORT_REGULAR,
            $sortDirection === 'desc',
        );
    }

    public function getTableRecordKey(Model|array $record): string
    {
        return $record->message;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(UploadQueue::query()->where('id', $this->record->id))
            ->description('Logs describing the history of the upload process.')
            ->paginationPageOptions([10, 25, 50])
            ->paginated(10)
            ->columns([
                TextColumn::make('context')
                    ->badge()
                    ->sortable()
                    ->color(fn (UploadQueueLog $record) => match ($record->context) {
                        UploadQueueLogContextEnums::ERROR => 'danger',
                        UploadQueueLogContextEnums::INFO => 'primary',
                        UploadQueueLogContextEnums::SUCCESS => 'success',
                        UploadQueueLogContextEnums::WARNING => 'warning',
                        default => 'default',
                    })
                    ->label('Severity'),
                TextColumn::make('type')
                    ->badge()
                    ->sortable()
                    ->label('Type'),
                TextColumn::make('state')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn ($state) => $this->record->enumState($state))
                    ->label('State'),
                TextColumn::make('message')
                    ->html()
                    ->formatStateUsing(fn (?string $state): string => nl2br(e($state ?? '')))
                    ->wrap()
                    ->limit(220)
                    ->tooltip(fn (UploadQueueLog $record): string => $record->message)
                    ->extraCellAttributes([
                        'class' => 'max-w-xl whitespace-normal break-words',
                    ])
                    ->color(fn (UploadQueueLog $record) => match ($record->context) {
                        UploadQueueLogContextEnums::ERROR => 'danger',
                        UploadQueueLogContextEnums::INFO => 'primary',
                        UploadQueueLogContextEnums::SUCCESS => 'success',
                        UploadQueueLogContextEnums::WARNING => 'warning',
                        default => 'default',
                    })
                    ->label('Log message'),
                TextColumn::make('user_id')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => User::find($state)?->name)
                    ->label('User'),
                TextColumn::make('timestamp')
                    ->dateTime()
                    ->sortable()
                    ->label('Date'),

            ])
            ->emptyStateHeading('No logs found.')
            ->defaultSort('timestamp', 'desc')
            ->filters([
                // ...
            ])
            ->recordActions([
                // ...
            ])
            ->toolbarActions([
                // ...
            ]);
    }

    public function render(): View
    {
        return view('livewire.oneline-logs-table');
    }
}
