<?php

namespace App\Policies;

use App\Enums\PermissionEnums;

/**
 * Notification templates are sent to every user, so they belong to the settings permissions.
 */
class NotificationTemplatePolicy extends PermissionGatedPolicy
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
