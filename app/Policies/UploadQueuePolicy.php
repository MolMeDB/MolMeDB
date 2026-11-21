<?php

namespace App\Policies;

use App\Enums\PermissionEnums;
use App\Models\Dataset;
use App\Models\UploadQueue;
use App\Models\User;

class UploadQueuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAdminRole()
            || $user->hasPermissionTo(PermissionEnums::UPLOAD_QUEUE_MANAGE_OWN)
            || $user->hasPermissionTo(PermissionEnums::UPLOAD_QUEUE_MANAGE_ALL);
    }

    public function view(User $user, UploadQueue $uploadQueue): bool
    {
        return $this->canManage($user, $uploadQueue);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, UploadQueue $uploadQueue): bool
    {
        return $this->canManage($user, $uploadQueue);
    }

    public function configure(User $user, UploadQueue $uploadQueue): bool
    {
        return $this->canManage($user, $uploadQueue);
    }

    public function enqueue(User $user, UploadQueue $uploadQueue): bool
    {
        return $this->canManage($user, $uploadQueue);
    }

    public function reupload(User $user, UploadQueue $uploadQueue): bool
    {
        return $this->canManage($user, $uploadQueue);
    }

    public function cancel(User $user, UploadQueue $uploadQueue): bool
    {
        return $this->canManage($user, $uploadQueue) && $uploadQueue->canBeCanceled();
    }

    /**
     * Reverting deletes every interaction of the upload's dataset, so the
     * user must also be allowed to change that dataset.
     */
    public function revert(User $user, UploadQueue $uploadQueue): bool
    {
        $dataset = Dataset::withTrashed()->find($uploadQueue->dataset_id);

        return $this->canManage($user, $uploadQueue)
            && $dataset !== null
            && $this->targetDataset($user, $dataset);
    }

    /**
     * Whether an upload may write into (and be reverted in) the dataset:
     * administrators and dataset.edit holders may use any dataset, everyone
     * else only datasets they created, e.g. by their own lab upload.
     * UploadQueueResource::scopeDatasetOptions() is the query form of this.
     */
    public function targetDataset(User $user, Dataset $dataset): bool
    {
        return $user->hasAdminRole()
            || $user->hasPermissionTo(PermissionEnums::DATASET_EDIT)
            || $dataset->created_by === $user->id;
    }

    /**
     * Approving or rejecting the data of an upload waiting for review is the
     * administrators' decision, never the uploader's own.
     */
    public function review(User $user, UploadQueue $uploadQueue): bool
    {
        return $user->hasAdminRole()
            || $user->hasPermissionTo(PermissionEnums::UPLOAD_QUEUE_MANAGE_ALL);
    }

    public function delete(User $user, UploadQueue $uploadQueue): bool
    {
        return $this->canManage($user, $uploadQueue) && $uploadQueue->canBeCanceled();
    }

    /**
     * Upload queues are deleted one by one (delete()), never in bulk.
     */
    public function deleteAny(User $user): bool
    {
        return false;
    }

    /**
     * Admins and UPLOAD_QUEUE_MANAGE_ALL holders can manage every record.
     * UPLOAD_QUEUE_MANAGE_OWN only grants access to records the user owns.
     */
    private function canManage(User $user, UploadQueue $uploadQueue): bool
    {
        if ($user->hasAdminRole() || $user->hasPermissionTo(PermissionEnums::UPLOAD_QUEUE_MANAGE_ALL)) {
            return true;
        }

        return $uploadQueue->user_id === $user->id
            && $user->hasPermissionTo(PermissionEnums::UPLOAD_QUEUE_MANAGE_OWN);
    }
}
