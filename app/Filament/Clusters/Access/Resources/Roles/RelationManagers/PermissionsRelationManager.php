<?php

namespace App\Filament\Clusters\Access\Resources\Roles\RelationManagers;

use App\Enums\RoleEnums;
use App\Models\Permission;
use Closure;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\PermissionRegistrar;

class PermissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'permissions';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('description')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->label('Key')
                    ->sortable(),
                TextColumn::make('description')
                    ->sortable()
                    ->wrap()
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                AttachAction::make()
                    ->multiple()
                    ->color('primary')
                    ->preloadRecordSelect()
                    // Only permissions the signed-in user holds (all for administrators).
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => Auth::user()->hasAdminRole()
                        ? $query
                        : $query->whereKey(Auth::user()->getAllPermissions()->modelKeys()))
                    ->recordSelect(fn (Select $select): Select => $select->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $permissions = Permission::query()->whereKey((array) $value)->get();

                        if (! Auth::user()->canGrantPermissions($permissions)) {
                            $fail('You can grant only permissions you hold yourself.');
                        }
                    }))
                    ->after(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions()),
            ])
            ->recordActions([
                // The Administrator role keeps every permission; others lose
                // only permissions the signed-in user holds themselves.
                DetachAction::make()
                    ->visible(fn (SpatiePermission $record): bool => $this->ownerRecord->name !== RoleEnums::ADMIN->value
                        && Auth::user()->canGrantPermissions([$record]))
                    ->after(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                ]),
            ]);
    }
}
