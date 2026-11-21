<?php

namespace App\Policies;

use App\Enums\PermissionEnums;
use App\Models\Dataset;
use App\Models\User;

class DatasetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(PermissionEnums::DATASET_VIEW)
            || $user->hasPermissionTo(PermissionEnums::DATASET_VIEW_OWN);
    }

    public function view(User $user, Dataset $dataset): bool
    {
        return $user->hasPermissionTo(PermissionEnums::DATASET_VIEW)
            || (
                $user->hasPermissionTo(PermissionEnums::DATASET_VIEW_OWN)
                && $dataset->created_by === $user->id
            );
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(PermissionEnums::DATASET_EDIT)
            || $user->hasPermissionTo(PermissionEnums::DATASET_EDIT_OWN);
    }

    public function update(User $user, Dataset $dataset): bool
    {
        return $user->hasPermissionTo(PermissionEnums::DATASET_EDIT)
            || (
                $user->hasPermissionTo(PermissionEnums::DATASET_EDIT_OWN)
                && $dataset->created_by === $user->id
            );
    }

    public function delete(User $user, Dataset $dataset): bool
    {
        return $user->hasPermissionTo(PermissionEnums::DATASET_DELETE)
            || (
                $user->hasPermissionTo(PermissionEnums::DATASET_DELETE_OWN)
                && $dataset->created_by === $user->id
            );
    }

    /**
     * Bulk deletion is offered to anyone who may delete some datasets;
     * every selected record is still checked against delete().
     */
    public function deleteAny(User $user): bool
    {
        return $user->hasPermissionTo(PermissionEnums::DATASET_DELETE)
            || $user->hasPermissionTo(PermissionEnums::DATASET_DELETE_OWN);
    }

    public function restore(User $user, Dataset $dataset): bool
    {
        return $this->delete($user, $dataset);
    }

    public function forceDelete(User $user, Dataset $dataset): bool
    {
        return $user->hasPermissionTo(PermissionEnums::DATASET_DELETE_FORCE);
    }
}
