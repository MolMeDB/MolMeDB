<?php

namespace App\Policies;

use App\Models\InteractionActive;
use App\Models\User;

class InteractionActivePolicy
{
    public function viewAny(User $user): bool
    {
        return app(DatasetPolicy::class)->viewAny($user);
    }

    public function view(User $user, InteractionActive $interactionActive): bool
    {
        return app(DatasetPolicy::class)->view($user, $interactionActive->dataset);
    }

    public function create(User $user): bool
    {
        return app(DatasetPolicy::class)->create($user);
    }

    public function update(User $user, InteractionActive $interactionActive): bool
    {
        return app(DatasetPolicy::class)->update($user, $interactionActive->dataset);
    }

    public function delete(User $user, InteractionActive $interactionActive): bool
    {
        return app(DatasetPolicy::class)->delete($user, $interactionActive->dataset);
    }

    /**
     * Every selected record is still checked against delete().
     */
    public function deleteAny(User $user): bool
    {
        return app(DatasetPolicy::class)->deleteAny($user);
    }

    public function restore(User $user, InteractionActive $interactionActive): bool
    {
        return app(DatasetPolicy::class)->restore($user, $interactionActive->dataset);
    }

    public function forceDelete(User $user, InteractionActive $interactionActive): bool
    {
        return app(DatasetPolicy::class)->forceDelete($user, $interactionActive->dataset);
    }
}
