<?php

namespace App\Policies;

use App\Models\InteractionPassive;
use App\Models\User;

class InteractionPassivePolicy
{
    public function viewAny(User $user): bool
    {
        return app(DatasetPolicy::class)->viewAny($user);
    }

    public function view(User $user, InteractionPassive $interactionPassive): bool
    {
        return app(DatasetPolicy::class)->view($user, $interactionPassive->dataset);
    }

    public function create(User $user): bool
    {
        return app(DatasetPolicy::class)->create($user);
    }

    public function update(User $user, InteractionPassive $interactionPassive): bool
    {
        return app(DatasetPolicy::class)->update($user, $interactionPassive->dataset);
    }

    public function delete(User $user, InteractionPassive $interactionPassive): bool
    {
        return app(DatasetPolicy::class)->delete($user, $interactionPassive->dataset);
    }

    /**
     * Every selected record is still checked against delete().
     */
    public function deleteAny(User $user): bool
    {
        return app(DatasetPolicy::class)->deleteAny($user);
    }

    public function restore(User $user, InteractionPassive $interactionPassive): bool
    {
        return app(DatasetPolicy::class)->restore($user, $interactionPassive->dataset);
    }

    public function forceDelete(User $user, InteractionPassive $interactionPassive): bool
    {
        return app(DatasetPolicy::class)->forceDelete($user, $interactionPassive->dataset);
    }
}
