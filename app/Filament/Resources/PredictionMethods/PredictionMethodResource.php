<?php

namespace App\Filament\Resources\PredictionMethods;

use App\Enums\IconEnums;
use App\Filament\Resources\Methods\MethodResource;
use App\Filament\Resources\PredictionMethods\Pages\CreatePredictionMethod;
use App\Filament\Resources\PredictionMethods\Pages\EditPredictionMethod;
use App\Filament\Resources\PredictionMethods\Pages\ListPredictionMethods;
use App\Filament\Resources\Publications\PublicationResource;
use App\Models\Method;
use App\Models\Publication;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\PredictionWorkers\Models\PredictionMethod;

class PredictionMethodResource extends Resource
{
    protected static ?string $model = PredictionMethod::class;

    protected static string|\BackedEnum|null $navigationIcon = IconEnums::METHOD->value;

    protected static string|\UnitEnum|null $navigationGroup = 'Predictions';

    protected static ?int $navigationSort = 0;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->label('Key')
                    ->required()
                    ->maxLength(20)
                    ->regex('/^[a-z0-9_]+$/')
                    ->unique(ignoreRecord: true)
                    ->disabledOn('edit')
                    ->helperText('Immutable identifier stored on predictions/datasets as method_type. Cannot be changed after creation.'),
                Select::make('remote_id')
                    ->relationship('method', 'name', fn ($query) => $query->withTrashed())
                    ->label('Method')
                    ->getOptionLabelFromRecordUsing(fn (Method $record) => ($record->trashed() ? ' (DELETED) ' : '').$record->name)
                    ->searchable()
                    ->suffixAction(fn (Get $get) => Action::make('view_method')
                        ->url(fn () => MethodResource::getUrl('edit', ['record' => Method::withTrashed()->find($get('remote_id'))]))
                        ->icon(IconEnums::VIEW->value)
                        ->tooltip('Show detail')
                        ->visible(fn (Get $get) => filled($get('remote_id')))
                        ->openUrlInNewTab())
                    ->helperText('The real Method this maps to. Determines which Method new interaction/dataset rows get tagged with when finished predictions are imported.')
                    ->unique(ignoreRecord: true)
                    ->required(),
                TextInput::make('label')
                    ->required()
                    ->maxLength(255),
                TextInput::make('remote_key')
                    ->label('Remote key')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->helperText('Must match the method identifier configured on the remote prediction server.'),
                TextInput::make('short_key')
                    ->label('Short key')
                    ->maxLength(10)
                    ->regex('/^[a-z0-9_]+$/')
                    ->disabledOn('edit')
                    ->helperText('Short code embedded in COSMO result file paths on disk (e.g. "perm"). Leave blank to derive one from the key. Locked after creation - changing it would make existing results unfindable, since paths are computed from this value on every lookup, not stored.'),
                Toggle::make('enabled')
                    ->label('Enabled')
                    ->default(true)
                    ->helperText('Disables this method for new dataset/prediction uploads only. Existing predictions are unaffected.'),
                Select::make('primary_publication_id')
                    ->relationship('primaryPublication', 'citation', fn ($query) => $query->withTrashed())
                    ->label('Primary reference')
                    ->getOptionLabelFromRecordUsing(fn (Publication $record) => ($record->trashed() ? ' (DELETED) ' : '').$record->citation)
                    ->searchable()
                    ->suffixAction(fn (Get $get) => Action::make('view_primary_publication')
                        ->url(fn () => PublicationResource::getUrl('edit', ['record' => Publication::withTrashed()->find($get('primary_publication_id'))]))
                        ->icon(IconEnums::VIEW->value)
                        ->tooltip('Show detail')
                        ->visible(fn (Get $get) => filled($get('primary_publication_id')))
                        ->openUrlInNewTab())
                    ->required(),
                Select::make('secondary_publication_id')
                    ->relationship('secondaryPublication', 'citation', fn ($query) => $query->withTrashed())
                    ->label('Secondary reference')
                    ->getOptionLabelFromRecordUsing(fn (Publication $record) => ($record->trashed() ? ' (DELETED) ' : '').$record->citation)
                    ->searchable()
                    ->suffixAction(fn (Get $get) => Action::make('view_secondary_publication')
                        ->url(fn () => PublicationResource::getUrl('edit', ['record' => Publication::withTrashed()->find($get('secondary_publication_id'))]))
                        ->icon(IconEnums::VIEW->value)
                        ->tooltip('Show detail')
                        ->visible(fn (Get $get) => filled($get('secondary_publication_id')))
                        ->openUrlInNewTab()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->badge()
                    ->sortable()
                    ->searchable(),
                TextColumn::make('label')
                    ->sortable()
                    ->searchable(),
                // Methods live in the default database, so neither a join nor a
                // subquery can reach them from the predictions connection.
                TextColumn::make('method.name')
                    ->label('Method')
                    ->placeholder('Not mapped')
                    ->color(fn (PredictionMethod $record) => $record->remote_id ? null : 'danger')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereIn(
                        'remote_id',
                        Method::withTrashed()
                            ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                            ->pluck('id'),
                    )),
                TextColumn::make('remote_key')
                    ->label('Remote key')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('short_key')
                    ->label('Short key')
                    ->toggleable(),
                IconColumn::make('enabled')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('primaryPublication.citation')
                    ->label('Primary reference')
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('secondaryPublication.citation')
                    ->label('Secondary reference')
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('predictions_count')
                    ->label('Predictions')
                    ->counts('predictions')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('enabled'),
            ])
            ->recordActions([
                EditAction::make()
                    ->color('warning'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPredictionMethods::route('/'),
            'create' => CreatePredictionMethod::route('/create'),
            'edit' => EditPredictionMethod::route('/{record}/edit'),
        ];
    }
}
