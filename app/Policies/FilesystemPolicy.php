<?php

namespace App\Policies;

use App\Enums\PermissionEnums;

/**
 * Storage disks hold access credentials, so they belong to the settings permissions.
 */
class FilesystemPolicy extends PermissionGatedPolicy
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
