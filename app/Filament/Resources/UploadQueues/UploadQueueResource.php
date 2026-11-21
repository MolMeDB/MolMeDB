<?php

namespace App\Filament\Resources\UploadQueues;

use App\Enums\IconEnums;
use App\Enums\PermissionEnums;
use App\Filament\Resources\Datasets\DatasetResource;
use App\Filament\Resources\SharedRelationManagers\InteractionsActiveRelationManager;
use App\Filament\Resources\SharedRelationManagers\InteractionsPassiveRelationManager;
use App\Filament\Resources\UploadQueues\Pages\CreateUploadQueue;
use App\Filament\Resources\UploadQueues\Pages\EditUploadQueue;
use App\Filament\Resources\UploadQueues\Pages\ListUploadQueues;
use App\Models\Dataset;
use App\Models\File;
use App\Models\UploadQueue;
use App\Rules\FileUniqueByHash;
use App\Services\UploadQueueImporter;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class UploadQueueResource extends Resource
{
    protected static ?string $model = UploadQueue::class;

    protected static string|\BackedEnum|null $navigationIcon = IconEnums::UPLOAD_QUEUE->value;

    protected static string|\UnitEnum|null $navigationGroup = 'Data management';

    protected static ?string $navigationLabel = 'Uploads';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextEntry::make('file.name')
                    ->formatStateUsing(fn (?UploadQueue $record) => $record?->file->name)
                    ->hiddenOn('create')
                    ->state('File'),
                TextEntry::make('state')
                    ->formatStateUsing(fn (?UploadQueue $record) => $record ? static::stateLabel($record->state) : null)
                    ->state('State')
                    ->hiddenOn('create'),
                Select::make('type')
                    ->label('Type')
                    ->options(fn () => UploadQueue::enumType())
                    ->disabledOn('edit')
                    ->reactive()
                    ->afterStateUpdated(function (callable $set, ?int $state) {
                        $set('dataset_id', null);
                        $set('membrane_id', null);
                        $set('method_id', null);
                    })
                    ->required(),
                Select::make('dataset_id')
                    ->label('Dataset')
                    ->relationship('dataset', 'name', fn (Builder $query, Get $get) => $query->where('type', $get('type'))->orderby('id', 'DESC'))
                    ->getOptionLabelFromRecordUsing(fn (Dataset $record) => "[$record->id]: $record->name (".Str::limit($record->comment, 40).')')
                    ->disabled(fn (Get $get, ?UploadQueue $record) => ! $get('type') || $record?->isRevertible())
                    ->preload()
                    ->createOptionForm(fn (Schema $schema) => DatasetResource::form($schema))
                    ->createOptionModalHeading('Add new dataset')
                    ->suffixActions([
                        Action::make('edit_dataset')
                            ->icon(IconEnums::VIEW->value)
                            ->url(fn (Get $get) => $get('dataset_id') ? DatasetResource::getUrl('edit', ['record' => Dataset::withTrashed()->find($get('dataset_id'))]) : null)
                            ->tooltip('View dataset')
                            ->openUrlInNewTab(),
                    ])
                    ->searchable()
                    ->reactive()
                    ->afterStateUpdated(function (callable $set, ?int $state) {
                        if ($state) {
                            $dataset = Dataset::withTrashed()->find($state);
                            $set('membrane_id', $dataset->membrane_id);
                            $set('method_id', $dataset->method_id);
                        }
                    })
                    ->afterStateHydrated(function (callable $set, ?int $state) {
                        if ($state) {
                            $dataset = Dataset::withTrashed()->find($state);
                            $set('membrane_id', $dataset->membrane_id);
                            $set('method_id', $dataset->method_id);
                        }
                    })
                    ->required(),
                Grid::make(2)
                    ->hidden(fn (Get $get) => $get('type') != UploadQueue::TYPE_PASSIVE_DATASET)
                    ->reactive()
                    ->schema([
                        Select::make('membrane_id')
                            ->reactive()
                            ->disabled()
                            ->label('Membrane')
                            ->hint('Membrane used in the dataset')
                            ->hintColor('danger')
                            ->relationship('dataset.membrane', 'name'),
                        Select::make('method_id')
                            ->reactive()
                            ->disabled()
                            ->label('Method')
                            ->hint('Method used in the dataset')
                            ->hintColor('danger')
                            ->relationship('dataset.method', 'name'),
                    ]),
                FileUpload::make('path')
                    ->label('Interactions file')
                    ->required()
                    ->maxSize(1024 * 1024 * 20) // 20 MB
                    ->columnSpanFull()
                    ->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file, Get $get): string {
                        session()->put('upload_meta', [
                            'hash' => md5_file($file->getRealPath()),
                            'mime' => $file->getMimeType(),
                        ]);

                        return '[Dataset:'.$get('dataset_id').']-'.File::getUniqueNameForSave($file,
                            UploadQueue::typeFolder($get('type') ? intval($get('type')) : null),
                            UploadQueue::disk()
                        );
                    })
                    ->hidden(fn (Get $get) => ! $get('type') || $get('id'))
                    ->reactive()
                    ->rules([new FileUniqueByHash])
                    ->preserveFilenames()
                    ->disk(UploadQueue::disk())
                    ->directory(fn (Get $get) => UploadQueue::typeFolder($get('type') ? intval($get('type')) : null)),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description('List of files uploaded manually by the contributors')
            ->columns([
                TextColumn::make('state')
                    ->sortable()
                    ->alignCenter()
                    ->label('State')
                    ->colors([
                        'primary',
                        'warning' => static fn ($state): bool => in_array($state, [
                            UploadQueue::STATE_RUNNING,
                        ]),
                        'success' => static fn ($state): bool => in_array($state, [
                            UploadQueue::STATE_DONE,
                        ]),
                        'danger' => static fn ($state): bool => in_array($state, [
                            UploadQueue::STATE_ERROR,
                            UploadQueue::STATE_CANCELED,
                        ]),
                    ])
                    ->formatStateUsing(fn ($state) => static::stateLabel($state)),
                TextColumn::make('id')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('ID'),
                TextColumn::make('file.name')
                    ->sortable()
                    ->limit(20)
                    ->tooltip(fn (UploadQueue $record) => $record->file?->name)
                    ->searchable()
                    ->label('File'),
                TextColumn::make('dataset.name')
                    ->sortable()
                    ->searchable()
                    ->limit(20)
                    ->tooltip(fn (UploadQueue $record) => $record->dataset?->name)
                    ->label('Dataset'),
                TextColumn::make('user.name')
                    ->sortable()
                    ->searchable()
                    ->badge()
                    ->label('User'),
                TextColumn::make('type')
                    ->sortable()
                    ->badge()
                    ->color('warning')
                    ->label('Type')
                    ->formatStateUsing(fn ($state) => UploadQueue::enumType($state)),
                TextColumn::make('last_log')
                    ->label('Latest log')
                    ->state(fn (UploadQueue $record): string => $record->logs?->last()?->message ?? '-')
                    ->limit(45)
                    ->tooltip(fn (UploadQueue $record): ?string => $record->logs?->last()?->message)
                    ->toggleable(),
                TextColumn::make('logs_count')
                    ->label('Logs')
                    ->state(fn (UploadQueue $record): int => $record->logs?->count() ?? 0)
                    ->badge()
                    ->alignCenter()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('state')
                    ->label('State')
                    ->multiple()
                    ->query(fn (Builder $query, array $state) => $state['values'] ? $query->whereIn('state', $state['values']) : $query)
                    ->options(static::stateOptions()),

                SelectFilter::make('type')
                    ->label('Type')
                    ->multiple()
                    ->query(fn (Builder $query, array $state) => $state['values'] ? $query->whereIn('type', $state['values']) : $query)
                    ->options(UploadQueue::enumType()),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Detail')
                    ->icon(IconEnums::VIEW->value),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Administrators and UPLOAD_QUEUE_MANAGE_ALL holders see every upload,
     * UPLOAD_QUEUE_MANAGE_OWN holders only their own (see UploadQueuePolicy).
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasAdminRole() || $user->hasPermissionTo(PermissionEnums::UPLOAD_QUEUE_MANAGE_ALL)) {
            return $query;
        }

        return $query->where('user_id', $user->id);
    }

    public static function getRelations(): array
    {
        return [
            InteractionsPassiveRelationManager::class,
            InteractionsActiveRelationManager::class,
        ];
    }

    /**
     * @return array<int, Action>
     */
    public static function uploadActions(): array
    {
        return [
            Action::make('delete_upload')
                ->label('Delete')
                ->color('danger')
                ->icon(IconEnums::DELETE->value)
                ->authorize('delete')
                ->requiresConfirmation()
                ->modalHeading('Delete upload queue record?')
                ->modalDescription('This will delete the uploaded file and mark the upload as canceled. This action cannot be reverted.')
                ->action(function (UploadQueue $record) {
                    $record->deleteUploadedFileAndCancel(Auth::user());

                    Notification::make()
                        ->title('Upload deleted')
                        ->body('The uploaded file was removed and the upload was marked as canceled.')
                        ->success()
                        ->send();
                })
                ->hidden(fn (UploadQueue $record) => ! $record->canDeleteUploadedFile(Auth::user())),

            Action::make('revert')
                ->label('Revert')
                ->color('danger')
                ->icon(IconEnums::RESTORE->value)
                ->requiresConfirmation()
                ->action(function (UploadQueue $record) {
                    return $record->revert();
                })
                ->successRedirectUrl(fn (UploadQueue $record): string => static::editUrl($record))
                ->modalDescription('This will remove all already added information for the whole dataset. If the dataset has multiple assigned files, all of their uploads will be reverted.')
                ->modalHeading('Revert upload process?')
                ->modalIcon(IconEnums::RESTORE->value)
                ->hidden(fn (UploadQueue $record) => ! $record->isRevertible())
                ->tooltip('Revert to initial state'),

            static::reviewDataAction(),
            static::exportAction(),
        ];
    }

    public static function reviewDataAction(): Action
    {
        return Action::make('review_data')
            ->label('Review data')
            ->icon(IconEnums::VIEW->value)
            ->color('warning')
            ->modalHeading(fn (UploadQueue $record) => "Review uploaded data #{$record->id}")
            ->modalWidth('7xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalFooterActions(fn (UploadQueue $record) => $record->shouldBeDecidedByAdmin() ? [
                static::approveReviewAction(),
                static::rejectReviewAction(),
            ] : [])
            ->modalContent(fn (UploadQueue $record) => view('filament.upload-queues.review-data', [
                'record' => $record,
                'rows' => app(UploadQueueImporter::class)->previewRows($record, 500),
            ]))
            ->hidden(fn (UploadQueue $record) => ! $record->canBeReviewedByAdmin());
    }

    public static function approveReviewAction(): Action
    {
        return Action::make('approve_review')
            ->label('Approve')
            ->icon(IconEnums::CHECK->value)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve upload data?')
            ->modalDescription('The upload will be returned to the processing queue and the final import step will run.')
            ->action(function (UploadQueue $record) {
                $record->approveAdminReview(Auth::id());

                Notification::make()
                    ->title('Upload approved')
                    ->body('The upload was returned to the processing queue.')
                    ->success()
                    ->send();
            })
            ->successRedirectUrl(fn (UploadQueue $record): string => static::editUrl($record))
            ->hidden(fn (UploadQueue $record) => ! $record->canBeReviewedByAdmin());
    }

    public static function rejectReviewAction(): Action
    {
        return Action::make('reject_review')
            ->label('Reject')
            ->icon(IconEnums::STOP->value)
            ->color('danger')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->rows(4)
                    ->maxLength(1000),
            ])
            ->modalHeading('Reject upload data?')
            ->modalDescription('The reason will be stored in upload logs and visible to administrators.')
            ->action(function (UploadQueue $record, array $data) {
                $record->rejectAdminReview((string) $data['reason'], Auth::id());

                Notification::make()
                    ->title('Upload rejected')
                    ->body('The upload was marked as rejected.')
                    ->danger()
                    ->send();
            })
            ->successRedirectUrl(fn (UploadQueue $record): string => static::editUrl($record))
            ->hidden(fn (UploadQueue $record) => ! $record->canBeReviewedByAdmin());
    }

    public static function exportAction(): Action
    {
        return Action::make('export')
            ->label('Export')
            ->icon(IconEnums::DOWNLOAD->value)
            ->requiresConfirmation()
            ->action(function (UploadQueue $record) {
                return redirect()->route('export.upload-queue.raw', ['record' => $record->id]);
            })
            ->modalFooterActions([
                Action::make('export_parsed')
                    ->label('As stored [db]')
                    ->color('warning')
                    ->disabled(fn (UploadQueue $record) => ! $record->isFinished())
                    ->action(function (UploadQueue $record) {
                        return redirect()->route('export.upload-queue', ['record' => $record->id]);
                    }),
                Action::make('export_raw')
                    ->label('As uploaded [raw]')
                    ->action(function (UploadQueue $record) {
                        return redirect()->route('export.upload-queue.raw', ['record' => $record->id]);
                    }),
            ])
            ->modalDescription('How would you like to export this dataset?')
            ->modalHeading('Export dataset')
            ->modalIcon(IconEnums::DOWNLOAD->value)
            ->tooltip('Export data');
    }

    public static function stateLabel(?int $state): ?string
    {
        if ($state === null) {
            return null;
        }

        return UploadQueue::$ui_enum_states[$state] ?? UploadQueue::enumState($state);
    }

    private static function editUrl(UploadQueue $record): string
    {
        return static::getUrl('edit', ['record' => $record]);
    }

    /**
     * @return array<int, string|null>
     */
    public static function stateOptions(): array
    {
        return collect(UploadQueue::enumState())
            ->mapWithKeys(fn (?string $label, int $state): array => [
                $state => static::stateLabel($state),
            ])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUploadQueues::route('/'),
            'create' => CreateUploadQueue::route('/create'),
            'edit' => EditUploadQueue::route('/{record}/edit'),
        ];
    }
}
