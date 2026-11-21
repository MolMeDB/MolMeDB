<?php

namespace App\ModelFilters;

use EloquentFilter\ModelFilter;
use Illuminate\Support\Facades\Schema;

class InteractionPassiveFilter extends ModelFilter
{
    public $sortBy = 'id';

    public function membraneIds($ids)
    {
        return $this->whereHas('dataset', function ($q) use ($ids) {
            $q->whereIn('membrane_id', self::numericIds($ids));
        });
    }

    public function methodIds($ids)
    {
        return $this->whereHas('dataset', function ($q) use ($ids) {
            $q->whereIn('method_id', self::numericIds($ids));
        });
    }

    public function structureId($id)
    {
        return $this->where('structure_id', $id);
    }

    public function sortBy($column)
    {
        $this->sortBy = $this->translateSortBy($column);

        return $this;
    }

    public function sortByDirection($direction)
    {
        if (strtolower($direction) !== 'asc') {
            return $this->orderBy($this->sortBy, 'desc');
        }

        return $this->orderBy($this->sortBy, 'asc');
    }

    public function setup()
    {
        $this->select('interactions_passive.*');
    }

    private function translateSortBy($key)
    {
        $translated = match ($key) {
            'membrane' => 'mem.abbreviation',
            'method' => 'met.abbreviation',
            default => $this->sortableColumn($key),
        };

        if (str_starts_with($translated, 'mem.')) {
            $this->join('datasets as d', 'd.id', '=', 'interactions_passive.dataset_id');
            $this->join('membranes as mem', 'mem.id', '=', 'd.membrane_id');

            return $translated;
        } elseif (str_starts_with($translated, 'met.')) {
            $this->join('datasets as d', 'd.id', '=', 'interactions_passive.dataset_id');
            $this->join('methods as met', 'met.id', '=', 'd.method_id');

            return $translated;
        }

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

    /**
     * @return array<int, string>
     */
    private static function numericIds(mixed $ids): array
    {
        return array_values(array_filter(
            (array) $ids,
            fn (mixed $id): bool => (is_string($id) || is_int($id)) && ctype_digit((string) $id),
        ));
    }
}
