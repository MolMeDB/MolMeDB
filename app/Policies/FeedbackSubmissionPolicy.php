<?php

namespace App\Policies;

use App\Enums\PermissionEnums;

/**
 * Feedback submissions carry visitors' e-mail addresses, so they belong to the settings permissions.
 */
class FeedbackSubmissionPolicy extends PermissionGatedPolicy
{
    protected function viewPermission(): PermissionEnums
    {
        return PermissionEnums::SETTINGS_VIEW;
    }

    protected function managePermission(): PermissionEnums
    {
        return PermissionEnums::SETTINGS_EDIT;
    }
}
