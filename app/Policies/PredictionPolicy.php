<?php

namespace App\Policies;

use App\Enums\PermissionEnums;

/**
 * Predictions of every user's datasets, visible to prediction managers only.
 */
class PredictionPolicy extends PermissionGatedPolicy
{
    protected function viewPermission(): PermissionEnums
    {
        return PermissionEnums::PREDICTION_DATASET_MANAGE_ALL;
    }

    protected function managePermission(): PermissionEnums
    {
        return PermissionEnums::PREDICTION_DATASET_MANAGE_ALL;
    }
}
