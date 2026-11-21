<?php

namespace App\Policies;

use App\Enums\PermissionEnums;
use App\Models\User;
use Modules\PredictionWorkers\Models\PredictionDataset;

class PredictionDatasetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(PermissionEnums::PREDICTION_DATASET_MANAGE_ALL);
    }

    /**
     * Any authenticated user who is the dataset owner OR has manage-all permission.
     * Token-based access (unauthenticated) is handled separately in the controller
     * before the policy is consulted.
     */
    public function view(User $user, PredictionDataset $dataset): bool
    {
        return $this->canAccess($user, $dataset);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(PermissionEnums::PREDICTION_DATASET_MANAGE_ALL);
    }

    public function update(User $user, PredictionDataset $dataset): bool
    {
        return $this->canAccess($user, $dataset);
    }

    public function delete(User $user, PredictionDataset $dataset): bool
    {
        return $this->canAccess($user, $dataset);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermissionTo(PermissionEnums::PREDICTION_DATASET_MANAGE_ALL);
    }

    public function viewRecords(User $user, PredictionDataset $dataset): bool
    {
        return $this->canAccess($user, $dataset);
    }

    public function viewStructures(User $user, PredictionDataset $dataset): bool
    {
        return $this->canAccess($user, $dataset);
    }

    private function canAccess(User $user, PredictionDataset $dataset): bool
    {
        if ($user->hasPermissionTo(PermissionEnums::PREDICTION_DATASET_MANAGE_ALL)) {
            return true;
        }

        return $dataset->user_id === $user->id;
    }
}
