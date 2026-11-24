<?php

namespace App\Filament\Clusters\Settings\Resources\Configs;

use App\Enums\IconEnums;
use App\Filament\Clusters\Settings\Resources\Configs\Pages\CreateConfig;
use App\Filament\Clusters\Settings\Resources\Configs\Pages\EditConfig;
use App\Filament\Clusters\Settings\Resources\Configs\Pages\ListConfigs;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\Config;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ConfigResource extends Resource
{
    protected static ?string $model = Config::class;

    protected static string|\BackedEnum|null $navigationIcon = IconEnums::SETTINGS->value;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Application';

    protected static ?string $navigationLabel = 'Configs';

    protected static ?string $modelLabel = 'Config';

    protected static ?string $pluralModelLabel = 'Configs';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->disabledOn('edit')
                    ->columnSpanFull(),
                Textarea::make('value')
                    // A sensitive value never reaches the browser: its field
                    // starts empty and an empty field keeps what is stored.
                    ->formatStateUsing(fn (?string $state, ?Config $record): ?string => static::holdsSensitiveValue($record) ? null : $state)
                    ->dehydrated(fn (?string $state, ?Config $record): bool => ! (static::holdsSensitiveValue($record) && blank($state)))
                    ->placeholder(fn (?Config $record): ?string => static::holdsSensitiveValue($record) ? 'The stored value is not shown. Leave empty to keep it.' : null)
                    // By the stored key of an edited setting: its key field is
                    // disabled, but its state can still be set by a crafted request.
                    ->rules(fn (Get $get, ?Config $record): array => ($record?->key ?? $get('key')) === Config::KEY_REMOTE_PREDICTION_URL
                        ? ['url', 'starts_with:https://']
                        : [])
                    ->maxLength(512)
                    ->rows(4)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('value')
                    ->formatStateUsing(fn (?string $state, Config $record): ?string => Config::isSensitiveKey($record->key)
                        ? '••••••••••••'
                        : $state)
                    ->searchable(query: fn (Builder $query, string $search): Builder => static::searchValues($query, $search))
                    ->limit(80)
                    ->wrap()
                    ->placeholder('No value'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function holdsSensitiveValue(?Config $record): bool
    {
        return $record !== null && Config::isSensitiveKey($record->key);
    }

    /**
     * Searches the values except the sensitive ones, so a search cannot
     * reveal a masked value piece by piece.
     */
    public static function searchValues(Builder $query, string $search): Builder
    {
        $sensitiveKeys = Config::query()
            ->pluck('key')
            ->filter(fn (string $key): bool => Config::isSensitiveKey($key))
            ->all();

        return $query
            ->whereLike('value', '%'.$search.'%')
            ->whereNotIn('key', $sensitiveKeys);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConfigs::route('/'),
            'create' => CreateConfig::route('/create'),
            'edit' => EditConfig::route('/{record}/edit'),
        ];
    }
}
