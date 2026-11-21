<?php

namespace App\Policies;

use App\Enums\PermissionEnums;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The activity log records changes made by every user, so reading it belongs
 * to the settings permissions. Nobody may change or delete its entries.
 */
class ActivityPolicy extends PermissionGatedPolicy
{
    protected function viewPermission(): PermissionEnums
    {
        return PermissionEnums::SETTINGS_VIEW;
    }

    protected function managePermission(): PermissionEnums
    {
        return PermissionEnums::SETTINGS_EDIT;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Model $record): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, Model $record): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
