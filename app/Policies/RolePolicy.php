<?php

namespace App\Policies;

use App\Enums\PermissionEnums;
use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(PermissionEnums::ROLES_VIEW);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Role $role): bool
    {
        return $user->hasPermissionTo(PermissionEnums::ROLES_VIEW) || $user->hasRole($role);
    }

    /**
     * Determine whether the user can attach/detach roles
     */
    public static function attach(User $user): bool
    {
        return $user->hasPermissionTo(PermissionEnums::ROLES_ASSIGN);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo(PermissionEnums::ROLES_EDIT);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Role $role): bool
    {
        return $user->hasPermissionTo(PermissionEnums::ROLES_EDIT);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Role $role): bool
    {
        return $user->hasPermissionTo(PermissionEnums::ROLES_DELETE);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermissionTo(PermissionEnums::ROLES_DELETE);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Role $role): bool
    {
        return $user->hasPermissionTo(PermissionEnums::ROLES_DELETE);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Role $role): bool
    {
        return false;
    }
}
