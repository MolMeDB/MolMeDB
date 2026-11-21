<?php

namespace App\Policies;

use App\Enums\PermissionEnums;

/**
 * Prediction methods configure the remote calculations, managed by holders of predictions.manage only.
 */
class PredictionMethodPolicy extends PermissionGatedPolicy
{
    protected function viewPermission(): PermissionEnums
    {
        return PermissionEnums::PREDICTIONS_MANAGE;
    }

    protected function managePermission(): PermissionEnums
    {
        return PermissionEnums::PREDICTIONS_MANAGE;
    }
}
