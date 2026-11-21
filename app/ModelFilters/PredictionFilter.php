<?php

namespace App\ModelFilters;

use EloquentFilter\ModelFilter;
use Modules\PredictionWorkers\Models\Prediction;

class PredictionFilter extends ModelFilter
{
    public $sortBy = 'id';

    /**
     * EloquentFilter sees private methods too; these helpers must not be
     * callable from the request input.
     *
     * @var array<int, string>
     */
    protected $blacklist = ['sortableColumn', 'integerFilterValues', 'filterValues'];

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
        return $this->whereRelation('predictionDatasets', 'datasets.id', $id);
    }

    public function structureId($id)
    {
        return $this->whereRelation('predictionStructure', 'structures.id', $id);
    }

    public function hasResults($value)
    {
        if ($value) {
            return $this->whereHas('predictionResult');
        }

        return $this;
    }

    public function state($states)
    {
        $values = $this->filterValues($states);
        $includePaused = in_array('paused', $values, true);
        $states = $this->integerFilterValues($values);

        if ($states === [] && ! $includePaused) {
            return $this;
        }

        return $this->where(function ($query) use ($states, $includePaused): void {
            $otherStates = array_values(array_diff($states, [Prediction::STATE_RUNNING]));

            if ($otherStates !== []) {
                $query->orWhereIn('state', $otherStates);
            }

            if (in_array(Prediction::STATE_RUNNING, $states, true)) {
                $query->orWhere(function ($query): void {
                    $query
                        ->where('state', Prediction::STATE_RUNNING)
                        ->whereNull('remote_paused_at');
                });
            }

            if ($includePaused) {
                $query->orWhereNotNull('remote_paused_at');
            }
        });
    }

    public function step($steps)
    {
        $steps = $this->integerFilterValues($steps);

        if ($steps === []) {
            return $this;
        }

        return $this->whereIn('step', $steps);
    }

    public function query($value)
    {
        if (! $value) {
            return $this;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return $this;
        }

        if (str_starts_with(strtolower($value), 'id:')) {
            $id = trim(substr($value, 3));

            if (is_numeric($id)) {
                return $this->where('predictions.id', (int) $id);
            }
        }

        return $this->where(function ($query) use ($value) {
            if (is_numeric($value)) {
                $query
                    ->orWhere('predictions.id', (int) $value)
                    ->orWhereRelation('predictionStructure', 'id', (int) $value);
            }

            $query->orWhereRelation(
                'predictionStructure',
                'canonical_smiles',
                'ILIKE',
                '%'.$value.'%'
            );
        });
    }

    public function setup() {}

    /**
     * @return array<int>
     */
    private function integerFilterValues(mixed $values): array
    {
        return collect($this->filterValues($values))
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, mixed>
     */
    private function filterValues(mixed $values): array
    {
        if ($values === null || $values === '') {
            return [];
        }

        if (is_string($values)) {
            $values = explode(',', $values);
        }

        return is_array($values) ? array_values($values) : [$values];
    }

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
