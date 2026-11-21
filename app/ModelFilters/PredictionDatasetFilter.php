<?php

namespace App\ModelFilters;

use EloquentFilter\ModelFilter;

class PredictionDatasetFilter extends ModelFilter
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

    public function query($comment)
    {
        if (! is_string($comment) || $comment === '') {
            return $this;
        }

        $comment = strtolower($comment);

        if (str_starts_with($comment, 'id:')) {
            $id = trim(str_replace('id:', '', $comment));

            return ctype_digit($id) && strlen($id) <= 18 ? $this->where('id', $id) : $this->whereRaw('1 = 0');
        }

        return $this->whereRaw('LOWER(comment) LIKE ?', ['%'.$comment.'%'])
            ->distinct();
    }

    public function setup() {}

    /**
     * Only a column of the listed table may be sorted by; anything else,
     * which would otherwise reach the SQL as it is, falls back to the id.
     * The column listing comes from the model's own (predictions) connection.
     * The access token is never sortable: the order would hint at its value.
     */
    private function sortableColumn(mixed $key): string
    {
        $model = $this->query->getModel();
        $columns = array_diff($model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable()), ['token']);

        return $model->qualifyColumn(is_string($key) && in_array($key, $columns, true) ? $key : 'id');
    }
}
