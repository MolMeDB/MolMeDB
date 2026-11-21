<?php

namespace App\Filament\Clusters\Access\Resources\Users\RelationManagers;

use App\Enums\IconEnums;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Policies\RolePolicy;
use App\Services\NotificationService;
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
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

class RolesRelationManager extends RelationManager
{
    protected static string $relationship = 'roles';

    protected static string|\BackedEnum|null $icon = IconEnums::ROLES->value;

    protected static ?string $title = 'Access roles';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    // Only roles the signed-in user may assign (see User::canAssignRole()):
                    // the list is filtered and the submitted choice checked again.
                    ->recordSelectOptionsQuery(function (Builder $query): Builder {
                        if (Auth::user()->hasAdminRole()) {
                            return $query;
                        }

                        $assignable = Role::query()->with('permissions')->get()
                            ->filter(fn (Role $role): bool => Auth::user()->canAssignRole($role))
                            ->modelKeys();

                        return $query->whereKey($assignable);
                    })
                    ->recordSelect(fn (Select $select): Select => $select->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $roles = Role::query()->with('permissions')->whereKey((array) $value)->get();

                        if ($roles->contains(fn (Role $role): bool => ! Auth::user()->canAssignRole($role))) {
                            $fail('You cannot assign this role.');
                        }
                    }))
                    ->color('primary')
                    ->visible(fn ($record): bool => RolePolicy::attach(Auth::user()))
                    ->after(fn () => $this->notifyRoleChanged()),
            ])
            ->recordActions([
                // Not one's own roles, and only roles one could assign.
                DetachAction::make()
                    ->visible(fn (SpatieRole $record): bool => $this->ownerRecord->id !== Auth::user()->id
                        && RolePolicy::attach(Auth::user())
                        && Auth::user()->canAssignRole($record))
                    ->after(fn () => $this->notifyRoleChanged()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                ]),
            ]);
    }

    private function notifyRoleChanged(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = $this->ownerRecord;
        $roles = $user->roles()->pluck('name')->implode(', ');

        app(NotificationService::class)->send($user, NotificationTemplate::KEY_ACCOUNT_ROLE_CHANGED, [
            'roles' => $roles !== '' ? $roles : 'none',
        ]);
    }
}
