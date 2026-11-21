<?php

namespace App\ModelFilters;

use EloquentFilter\ModelFilter;
use Illuminate\Support\Facades\Schema;

class PublicationFilter extends ModelFilter
{
    public $sortBy = 'id';

    public function sortBy($column)
    {
        $this->sortBy = $this->sortableColumn($column);

        return $this;
    }

    public function sortByDirection($direction)
    {
        if (strtolower($direction) !== 'asc') {
            return $this->orderBy($this->sortBy, 'desc');
        }

        return $this->orderBy($this->sortBy, 'asc');
    }

    public function query($name)
    {
        if (! $name) {
            return $this;
        }

        $name = strtolower($name);

        if (str_starts_with($name, 'id:')) {
            $id = trim(str_replace('id:', '', $name));

            return ctype_digit($id) ? $this->where('id', $id) : $this->whereRaw('1 = 0');
        }

        return $this->whereRaw('LOWER(citation) LIKE ?', ['%'.$name.'%'])
            ->orWhereRaw('LOWER(title) LIKE ?', ['%'.$name.'%'])
            ->orWhereRaw('LOWER(doi) LIKE ?', ['%'.$name.'%'])
            ->distinct();
    }

    public function setup() {}

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
