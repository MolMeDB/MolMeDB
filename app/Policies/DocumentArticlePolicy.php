<?php

namespace App\Policies;

use App\Enums\PermissionEnums;

/**
 * Documentation articles are published on the public site, so they belong to the settings permissions.
 */
class DocumentArticlePolicy extends PermissionGatedPolicy
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
