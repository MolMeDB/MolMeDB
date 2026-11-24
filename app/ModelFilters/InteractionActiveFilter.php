<?php

namespace App\ModelFilters;

use EloquentFilter\ModelFilter;
use Illuminate\Support\Facades\Schema;

class InteractionActiveFilter extends ModelFilter
{
    public $sortBy = 'id';

    public function structureId($id)
    {
        return $this->where('structure_id', $id);
    }

    /**
     * EloquentFilter sees private methods too; these helpers must not be
     * callable from the request input.
     *
     * @var array<int, string>
     */
    protected $blacklist = ['translateSortBy', 'sortableColumn'];

    public function sortBy($column)
    {
        $this->sortBy = $this->translateSortBy($column);

        return $this;
    }

    public function sortByDirection($direction)
    {
        if (! is_string($direction) || strtolower($direction) !== 'asc') {
            return $this->orderBy($this->sortBy, 'desc');
        }

        return $this->orderBy($this->sortBy, 'asc');
    }

    public function setup()
    {
        $this->select('interactions_active.*');
    }

    private function translateSortBy($key)
    {
        $translated = match ($key) {
            default => $this->sortableColumn($key),
        };

        return $translated;
    }

    /**
     * Only a column of the listed table may be sorted by; anything else,
     * which would otherwise reach the SQL as it is, falls back to the id.
     */
    private function sortableColumn(mixed $key): string
    {
        $model = $this->query->getModel();

        $isOwnColumn = is_string($key)
            && in_array($key, Schema::getColumnListing($model->getTable()), true);

        return $model->qualifyColumn($isOwnColumn ? $key : 'id');
    }
}
