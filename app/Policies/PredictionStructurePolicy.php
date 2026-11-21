<?php

namespace App\Policies;

use App\Enums\PermissionEnums;

/**
 * Structures submitted for prediction by every user, visible to holders of predictions.manage only.
 */
class PredictionStructurePolicy extends PermissionGatedPolicy
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
