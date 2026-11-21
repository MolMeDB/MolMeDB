<?php

namespace App\Services\Interactions;

use App\Http\Resources\Api\Public\V1\InteractionActiveResource;
use App\Http\Resources\Api\Public\V1\InteractionPassiveResource;
use App\ModelFilters\PublicInteractionActiveFilter;
use App\ModelFilters\PublicInteractionPassiveFilter;
use App\Models\InteractionActive;
use App\Models\InteractionPassive;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;

/**
 * The one query behind every interaction listing of the public API and the
 * MCP server: publicly visible records, the public filters, the relations
 * the resource needs and a stable order for pagination.
 */
class PublicInteractionQuery
{
    /**
     * How long the total of a listing is reused. Counting a broad filter
     * (e.g. one membrane, ~490k rows) takes up to ~300 ms, while the data
     * only change with an import.
     */
    private const TOTAL_TTL_SECONDS = 3600;

    /**
     * @param  array<string, mixed>  $filters  validated input of SearchPassiveInteractionsRequest
     * @return Builder<InteractionPassive>
     */
    public static function passive(array $filters = []): Builder
    {
        return InteractionPassive::query()
            ->publiclyVisible()
            ->filter($filters, PublicInteractionPassiveFilter::class)
            ->with(InteractionPassiveResource::RELATIONS)
            ->orderBy('interactions_passive.id');
    }

    /**
     * @param  array<string, mixed>  $filters  validated input of SearchActiveInteractionsRequest
     * @return Builder<InteractionActive>
     */
    public static function active(array $filters = []): Builder
    {
        return InteractionActive::query()
            ->publiclyVisible()
            ->filter($filters, PublicInteractionActiveFilter::class)
            ->with(InteractionActiveResource::RELATIONS)
            ->orderBy('interactions_active.id');
    }

    /**
     * Paginates a listing with its cached total.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<int, TModel>
     */
    public static function paginate(Builder $query, int $perPage, ?int $page = null): LengthAwarePaginator
    {
        return $query->paginate($perPage, ['*'], 'page', $page, self::total($query));
    }

    /**
     * Number of interactions of a listing, cached per query (SQL and bindings).
     *
     * @param  Builder<*>  $query
     */
    public static function total(Builder $query): int
    {
        $count = $query->toBase()->cloneWithout(['orders', 'limit', 'offset']);

        return self::remember('total', $count, fn (): int => $count->getCountForPagination());
    }

    /**
     * Number of distinct structures of a listing, cached like total().
     *
     * @param  Builder<*>  $query
     */
    public static function structures(Builder $query): int
    {
        $count = $query->toBase()->cloneWithout(['orders', 'limit', 'offset', 'columns']);

        return self::remember('structures', $count, fn (): int => $count->distinct()->count('structures.id'));
    }

    /**
     * @param  Closure(): int  $callback
     */
    private static function remember(string $kind, QueryBuilder $query, Closure $callback): int
    {
        $key = "public-api:interactions-{$kind}:".md5($query->toSql().'|'.serialize($query->getBindings()));

        return Cache::remember($key, self::TOTAL_TTL_SECONDS, $callback);
    }
}
