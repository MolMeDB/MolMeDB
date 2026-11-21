<?php

namespace App\Filament\Clusters\Access\Resources\Roles;

use App\Enums\IconEnums;
use App\Enums\RoleEnums;
use App\Filament\Clusters\Access\Resources\Roles\Pages\CreateRole;
use App\Filament\Clusters\Access\Resources\Roles\Pages\EditRole;
use App\Filament\Clusters\Access\Resources\Roles\Pages\ListRoles;
use App\Filament\Clusters\Access\Resources\Roles\RelationManagers\PermissionsRelationManager;
use App\Filament\Clusters\Access\Resources\Roles\RelationManagers\UsersRelationManager;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\Role;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|\BackedEnum|null $navigationIcon = IconEnums::ROLES->value;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Access rules';

    public static function form(Schema $schema): Schema
    {
        $default_enums = array_map(fn ($enum) => $enum->value, RoleEnums::cases());

        return $schema
            ->components([
                TextInput::make('name')
                    ->disabled(fn ($record) => $record && in_array($record->name, $default_enums))
                    ->hint(fn ($record) => $record && in_array($record->name, $default_enums) ? 'Default roles cannot be changed.' : '')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('users_count')
                    ->counts('users')
                    ->badge()
                    ->label('# Users')
                    ->alignCenter()
                    ->color('warning')
                    ->numeric(),
                TextColumn::make('permissions_count')
                    ->counts('permissions')
                    ->badge()
                    ->tooltip(fn ($record) => $record->permissions->pluck('description')->implode(' - '))
                    ->label('# Permissions')
                    ->alignCenter()
                    ->color('primary')
                    ->numeric(),
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
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Checks each role, so the default roles are skipped.
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PermissionsRelationManager::class,
            UsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
