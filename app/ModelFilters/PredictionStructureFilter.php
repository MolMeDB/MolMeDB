<?php

namespace App\ModelFilters;

use EloquentFilter\ModelFilter;

class PredictionStructureFilter extends ModelFilter
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

    public function datasetId($id)
    {
        if (! FilterInput::isId($id)) {
            return $this->whereRaw('1 = 0');
        }

        return $this->whereHas('predictions.predictionDatasets', function ($q) use ($id) {
            $q->where('datasets.id', $id);
        });
    }

    public function setup() {}

    /**
     * Only a column of the listed table may be sorted by; anything else,
     * which would otherwise reach the SQL as it is, falls back to the id.
     * The column listing comes from the model's own (predictions) connection.
     */
    private function sortableColumn(mixed $key): string
    {
        $model = $this->query->getModel();
        $columns = $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable());

        return $model->qualifyColumn(is_string($key) && in_array($key, $columns, true) ? $key : 'id');
    }
}
