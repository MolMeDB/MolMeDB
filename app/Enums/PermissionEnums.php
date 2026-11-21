<?php

namespace App\Enums;

enum PermissionEnums: string
{
    case ADMIN_PANEL = 'admin.panel';

    case CATEGORIES_VIEW = 'categories.view';
    case CATEGORIES_MANAGE = 'categories.manage';
    case CATEGORIES_MANAGE_OWN = 'categories.manage.own';

    case SETTINGS_VIEW = 'settings.view';
    case SETTINGS_EDIT = 'settings.edit';
    case CONFIG_VIEW = 'config.view';
    case CONFIG_EDIT = 'config.edit';
    case SSH_CREDENTIALS_MANAGE = 'ssh-credentials.manage';

    case MEMBRANE_METHOD_VIEW = 'membrane.method.view';
    case MEMBRANE_METHOD_EDIT = 'membrane.method.edit';
    case MEMBRANE_METHOD_EDIT_OWN = 'membrane.method.edit.own';
    case MEMBRANE_METHOD_DELETE = 'membrane.method.delete';
    case MEMBRANE_METHOD_DELETE_OWN = 'membrane.method.delete.own';

    case PROTEIN_VIEW = 'protein.view';
    case PROTEIN_VIEW_OWN = 'protein.view.own';
    case PROTEIN_EDIT = 'protein.edit';
    case PROTEIN_EDIT_OWN = 'protein.edit.own';
    case PROTEIN_DELETE = 'protein.delete';
    case PROTEIN_DELETE_OWN = 'protein.delete.own';

    case PUBLICATION_VIEW = 'publication.view';
    case PUBLICATION_VIEW_OWN = 'publication.view.own';
    case PUBLICATION_EDIT = 'publication.edit';
    case PUBLICATION_EDIT_OWN = 'publication.edit.own';
    case PUBLICATION_DELETE = 'publication.delete';
    case PUBLICATION_DELETE_OWN = 'publication.delete.own';

    case STRUCTURE_VIEW = 'structure.view';
    case STRUCTURE_VIEW_OWN = 'structure.view.own';
    case STRUCTURE_EDIT = 'structure.edit';
    case STRUCTURE_EDIT_OWN = 'structure.edit.own';
    case STRUCTURE_DELETE = 'structure.delete';
    case STRUCTURE_DELETE_OWN = 'structure.delete.own';

    case DATASET_VIEW = 'dataset.view';
    case DATASET_VIEW_OWN = 'dataset.view.own';
    case DATASET_EDIT = 'dataset.edit';
    case DATASET_EDIT_OWN = 'dataset.edit.own';
    case DATASET_DELETE = 'dataset.delete';
    case DATASET_DELETE_OWN = 'dataset.delete.own';
    case DATASET_DELETE_FORCE = 'dataset.delete.force';

    case UPLOAD_QUEUE_MANAGE_OWN = 'upload_queue.manage.own';
    case UPLOAD_QUEUE_MANAGE_ALL = 'upload_queue.manage.all';

    case PREDICTION_DATASET_MANAGE_ALL = 'prediction_dataset.manage.all';
    case PREDICTIONS_MANAGE = 'predictions.manage';

    case USERS_VIEW = 'users.view';
    case USERS_EDIT = 'users.edit';
    case USERS_DELETE = 'users.delete';

    case ROLES_VIEW = 'roles.view';
    case ROLES_ASSIGN = 'roles.assign';
    case ROLES_EDIT = 'roles.edit';
    case ROLES_DELETE = 'roles.delete';

    case SYSTEM_MONITOR = 'system.monitor';

    public function description(): string
    {
        return match ($this) {
            self::ADMIN_PANEL => 'Can access the admin dashboard',
            self::CATEGORIES_VIEW => 'Can view categories',
            self::CATEGORIES_MANAGE => 'Can manage categories',
            self::CATEGORIES_MANAGE_OWN => 'Can manage own categories',
            self::DATASET_VIEW => 'Can view datasets',
            self::DATASET_VIEW_OWN => 'Can view own datasets',
            self::DATASET_EDIT => 'Can manage datasets',
            self::DATASET_EDIT_OWN => 'Can manage own datasets',
            self::DATASET_DELETE => 'Can delete datasets',
            self::DATASET_DELETE_OWN => 'Can delete own datasets',
            self::DATASET_DELETE_FORCE => 'Can force delete datasets',
            self::UPLOAD_QUEUE_MANAGE_OWN => 'Can upload and manage own upload queue records',
            self::UPLOAD_QUEUE_MANAGE_ALL => 'Can manage any upload queue records',
            self::PREDICTION_DATASET_MANAGE_ALL => 'Can view and manage any prediction dataset',
            self::PREDICTIONS_MANAGE => 'Can manage predictions, prediction methods, structures and stats in the admin panel',
            self::MEMBRANE_METHOD_VIEW => 'Can view membranes and methods',
            self::MEMBRANE_METHOD_EDIT => 'Can manage membranes and methods',
            self::MEMBRANE_METHOD_EDIT_OWN => 'Can manage own membranes and methods',
            self::MEMBRANE_METHOD_DELETE => 'Can delete any membranes and methods',
            self::MEMBRANE_METHOD_DELETE_OWN => 'Can delete own membrane and method records',
            self::PROTEIN_VIEW => 'Can view protein targets',
            self::PROTEIN_VIEW_OWN => 'Can view own protein targets',
            self::PROTEIN_EDIT => 'Can manage protein targets',
            self::PROTEIN_EDIT_OWN => 'Can manage own protein targets',
            self::PROTEIN_DELETE => 'Can delete protein targets',
            self::PROTEIN_DELETE_OWN => 'Can delete own protein target records',
            self::PUBLICATION_VIEW => 'Can view publications',
            self::PUBLICATION_VIEW_OWN => 'Can view own publications',
            self::PUBLICATION_EDIT => 'Can manage publications',
            self::PUBLICATION_EDIT_OWN => 'Can manage own publications',
            self::PUBLICATION_DELETE => 'Can delete publications',
            self::PUBLICATION_DELETE_OWN => 'Can delete own publication records',
            self::STRUCTURE_VIEW => 'Can view structures',
            self::STRUCTURE_VIEW_OWN => 'Can view own structures',
            self::STRUCTURE_EDIT => 'Can manage structures',
            self::STRUCTURE_EDIT_OWN => 'Can manage own structures',
            self::STRUCTURE_DELETE => 'Can delete structures',
            self::STRUCTURE_DELETE_OWN => 'Can delete own structure records',
            self::SETTINGS_EDIT => 'Can manage settings',
            self::SETTINGS_VIEW => 'Can view settings',
            self::CONFIG_VIEW => 'Can view application configuration',
            self::CONFIG_EDIT => 'Can manage application configuration',
            self::SSH_CREDENTIALS_MANAGE => 'Can manage SSH credentials',
            self::ROLES_VIEW => 'Can see user roles',
            self::ROLES_ASSIGN => 'Can assign roles to users',
            self::ROLES_EDIT => 'Can manage roles',
            self::ROLES_DELETE => 'Can delete existing roles',
            self::USERS_VIEW => 'Can view users',
            self::USERS_EDIT => 'Can manage basic user details',
            self::USERS_DELETE => 'Can soft-delete user',
            self::SYSTEM_MONITOR => 'Can receive system health notifications (backups, failed jobs, notification failures)',
        };
    }

    // extra helper to allow for greater customization of displayed values, without disclosing the name/value data directly
    public function label(): string
    {
        return str_replace('.', ' ', $this->value);
    }
}
