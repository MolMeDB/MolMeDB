<?php

namespace App\ModelFilters;

use Illuminate\Support\Facades\DB;

/**
 * Filters of GET /api/v1/interactions/passive and the passive interaction
 * listings of structures, membranes, methods and publications.
 */
class PublicInteractionPassiveFilter extends PublicInteractionFilter
{
    public static function valueColumns(): array
    {
        return ['logperm', 'logk', 'gpen', 'gwat', 'x_min'];
    }

    /**
     * Filtered by the (few) datasets of the membrane rather than by the joined
     * datasets.membrane_id: the planner has per-value statistics of
     * dataset_id and does not walk the whole table in id order for a small
     * membrane.
     */
    public function membrane(int|string $id): void
    {
        $this->whereIn($this->qualify('dataset_id'), DB::table('datasets')->where('membrane_id', $id)->pluck('id'));
    }

    public function method(int|string $id): void
    {
        $this->whereIn($this->qualify('dataset_id'), DB::table('datasets')->where('method_id', $id)->pluck('id'));
    }
}
