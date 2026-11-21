<?php

namespace App\Policies;

use App\Enums\PermissionEnums;

/**
 * Structures submitted for prediction by every user, visible to prediction managers only.
 */
class PredictionStructurePolicy extends PermissionGatedPolicy
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
