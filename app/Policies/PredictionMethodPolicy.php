<?php

namespace App\Policies;

use App\Enums\PermissionEnums;

/**
 * Prediction methods configure the remote calculations, managed by prediction managers only.
 */
class PredictionMethodPolicy extends PermissionGatedPolicy
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
