<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * Visibility of interaction records in the public API and the MCP server:
 * only interactions of an existing dataset and of a structure that has a
 * public identifier. The joined tables are also what the public filters
 * (membrane, method, structure identifier) filter on.
 */
trait VisibleInPublicApi
{
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->select($this->qualifyColumn('*'))
            ->join('datasets', fn (JoinClause $join) => $join
                ->on('datasets.id', '=', $this->qualifyColumn('dataset_id'))
                // A join bypasses the SoftDeletes scope of Dataset.
                ->whereNull('datasets.deleted_at'))
            ->join('structures', fn (JoinClause $join) => $join
                ->on('structures.id', '=', $this->qualifyColumn('structure_id'))
                ->whereNull('structures.deleted_at')
                ->whereNotNull('structures.identifier'));
    }
}
