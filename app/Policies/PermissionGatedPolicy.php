<?php

namespace App\Policies;

use App\Enums\PermissionEnums;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A policy for records that are not owned by anyone: one permission lets a
 * user read them, another lets them change them (and implies reading).
 *
 * Filament allows every ability a model's policy does not define, so this
 * class defines all of them explicitly.
 */
abstract class PermissionGatedPolicy
{
    abstract protected function viewPermission(): PermissionEnums;

    abstract protected function managePermission(): PermissionEnums;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->canManage($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->canManage($user);
    }

    public function restoreAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, Model $record): bool
    {
        return $this->canManage($user);
    }

    public function reorder(User $user): bool
    {
        return $this->canManage($user);
    }

    protected function canView(User $user): bool
    {
        return $user->hasPermissionTo($this->viewPermission())
            || $this->canManage($user);
    }

    protected function canManage(User $user): bool
    {
        return $user->hasPermissionTo($this->managePermission());
    }
}
