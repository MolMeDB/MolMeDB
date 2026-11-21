<?php

namespace App\ModelFilters;

use App\Models\Dataset;
use EloquentFilter\ModelFilter;
use Illuminate\Support\Facades\DB;

/**
 * Filters of the public API interaction listings (passive and active). The
 * input is validated by the Search*InteractionsRequest form requests and the
 * query is already joined with datasets and structures by
 * the publiclyVisible() scope, so every column is qualified by its table.
 */
abstract class PublicInteractionFilter extends ModelFilter
{
    /**
     * Measured values a client can require (with_value) and limit by a range
     * (<value>_min, <value>_max).
     *
     * @return array<int, string>
     */
    abstract public static function valueColumns(): array;

    /**
     * Other numeric columns limited by a range (<column>_min, <column>_max).
     *
     * @var array<int, string>
     */
    public const RANGE_COLUMNS = ['temperature', 'ph'];

    public function setup(): void
    {
        foreach ([...static::RANGE_COLUMNS, ...static::valueColumns()] as $column) {
            if (filled($this->input("{$column}_min"))) {
                $this->where($this->qualify($column), '>=', $this->input("{$column}_min"));
            }

            if (filled($this->input("{$column}_max"))) {
                $this->where($this->qualify($column), '<=', $this->input("{$column}_max"));
            }
        }
    }

    public function structure(string $identifier): void
    {
        $this->where('structures.identifier', $identifier);
    }

    /**
     * A publication is the primary reference of an interaction or the
     * secondary reference of its whole dataset, the same as in the exports.
     * The (few) datasets are resolved first: with a subquery the planner
     * cannot use the indexes and scans the whole table in id order.
     */
    public function publication(int|string $id): void
    {
        $datasetIds = DB::table('model_has_publications')
            ->where('model_type', Dataset::class)
            ->where('publication_id', $id)
            ->pluck('model_id');

        $this->where(fn ($query) => $query
            ->where($this->qualify('publication_id'), $id)
            ->orWhereIn($this->qualify('dataset_id'), $datasetIds));
    }

    /**
     * Charge is stored as text ("1", "+1", "-1", "+1,-1"); a plain integer
     * also matches its explicitly signed form.
     */
    public function charge(string $charge): void
    {
        $charge = trim($charge);

        if (preg_match('/^[+-]?\d+$/', $charge)) {
            $value = (int) $charge;
            $variants = $value > 0 ? [(string) $value, "+{$value}"] : [(string) $value];

            $this->whereIn($this->qualify('charge'), $variants);

            return;
        }

        $this->where($this->qualify('charge'), $charge);
    }

    public function withValue(string $column): void
    {
        $this->whereNotNull($this->qualify($column));
    }

    protected function qualify(string $column): string
    {
        return $this->query->getModel()->qualifyColumn($column);
    }
}
