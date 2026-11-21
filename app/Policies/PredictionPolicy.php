<?php

namespace App\Policies;

use App\Enums\PermissionEnums;

/**
 * Predictions of every user's datasets, visible to holders of predictions.manage only.
 */
class PredictionPolicy extends PermissionGatedPolicy
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
