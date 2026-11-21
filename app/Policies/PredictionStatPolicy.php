<?php

namespace App\Policies;

use App\Enums\PermissionEnums;

/**
 * Prediction server statistics, visible to holders of predictions.manage only.
 */
class PredictionStatPolicy extends PermissionGatedPolicy
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
