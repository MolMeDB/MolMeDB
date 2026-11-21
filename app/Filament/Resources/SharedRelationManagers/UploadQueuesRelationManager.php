<?php

namespace App\Filament\Resources\SharedRelationManagers;

use App\Enums\IconEnums;
use App\Filament\Resources\UploadQueues\UploadQueueResource;
use App\Models\UploadQueue;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UploadQueuesRelationManager extends RelationManager
{
    protected static string $relationship = 'uploadQueues';

    protected static ?string $title = 'Uploads';

    protected static string|\BackedEnum|null $icon = IconEnums::UPLOAD_QUEUE->value;

    public function table(Table $table): Table
    {
        return $table
            ->description('Upload queue records created for this dataset.')
            ->query(null)
            ->modifyQueryUsing(fn (Builder $query): Builder => UploadQueueResource::scopeToVisibleUploads($query))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->formatStateUsing(fn (?int $state): ?string => UploadQueueResource::stateLabel($state))
                    ->color(fn (?int $state): string => match ($state) {
                        UploadQueue::STATE_DONE => 'success',
                        UploadQueue::STATE_RUNNING,
                        UploadQueue::STATE_PENDING,
                        UploadQueue::STATE_REVIEW_REQUIRED => 'warning',
                        UploadQueue::STATE_ERROR,
                        UploadQueue::STATE_CANCELED => 'danger',
                        default => 'primary',
                    })
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (?int $state): ?string => UploadQueue::enumType($state))
                    ->sortable(),
                TextColumn::make('file.name')
                    ->label('File')
                    ->limit(30)
                    ->tooltip(fn (UploadQueue $record): ?string => $record->file?->name)
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label('User')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('last_log')
                    ->label('Latest log')
                    ->state(fn (UploadQueue $record): string => $record->logs?->last()?->message ?? '-')
                    ->limit(50)
                    ->tooltip(fn (UploadQueue $record): ?string => $record->logs?->last()?->message),
                TextColumn::make('created_at')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('state')
                    ->label('State')
                    ->multiple()
                    ->options(UploadQueueResource::stateOptions()),
                SelectFilter::make('type')
                    ->label('Type')
                    ->multiple()
                    ->options(UploadQueue::enumType()),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Detail')
                    ->icon(IconEnums::VIEW->value)
                    ->url(fn (UploadQueue $record): string => UploadQueueResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
