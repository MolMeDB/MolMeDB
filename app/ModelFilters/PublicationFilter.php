<?php

namespace App\ModelFilters;

use EloquentFilter\ModelFilter;
use Illuminate\Support\Facades\Schema;

class PublicationFilter extends ModelFilter
{
    public $sortBy = 'id';

    /**
     * EloquentFilter sees private methods too; these helpers must not be
     * callable from the request input.
     *
     * @var array<int, string>
     */
    protected $blacklist = ['sortableColumn'];

    public function sortBy($column)
    {
        $this->sortBy = $this->sortableColumn($column);

        return $this;
    }

    public function sortByDirection($direction)
    {
        if (! is_string($direction) || strtolower($direction) !== 'asc') {
            return $this->orderBy($this->sortBy, 'desc');
        }

        return $this->orderBy($this->sortBy, 'asc');
    }

    public function query($name)
    {
        if (! is_string($name) || $name === '') {
            return $this;
        }

        $name = strtolower($name);

        if (str_starts_with($name, 'id:')) {
            $id = trim(str_replace('id:', '', $name));

            return FilterInput::isId($id) ? $this->where('id', $id) : $this->whereRaw('1 = 0');
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
