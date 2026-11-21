<?php

namespace App\Policies;

use App\Enums\PermissionEnums;

/**
 * Prediction server statistics, visible to prediction managers only.
 */
class PredictionStatPolicy extends PermissionGatedPolicy
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
