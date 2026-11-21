<?php

namespace App\Mcp\Support;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared pagination shaping for "search"/"list" MCP tools built on top of
 * the same EloquentFilter-backed queries the Public API v1 controllers use
 * (`Model::filter($input)->paginateFilter($perPage)`) — see
 * app/Http/Controllers/Api/Public/V1/*.
 */
trait PaginatesFilteredResults
{
    protected function clampPerPage(mixed $value, int $default = 20, int $max = 100): int
    {
        $perPage = is_numeric($value) ? (int) $value : $default;

        return max(1, min($max, $perPage ?: $default));
    }

    protected function clampPage(mixed $value): int
    {
        $page = is_numeric($value) ? (int) $value : 1;

        return max(1, $page);
    }

    /**
     * @param  Builder<*>  $query  a query already narrowed by ->filter($input)
     * @return array{data: array<int, mixed>, meta: array<string, mixed>}
     */
    protected function paginatedResult(Builder $query, string $resourceClass, int $perPage, int $page, bool $simple = false): array
    {
        $paginator = $simple
            ? $query->simplePaginateFilter($perPage, ['*'], 'page', $page)
            : $query->paginateFilter($perPage, ['*'], 'page', $page);

        return $this->paginatorToResult($paginator, $resourceClass);
    }

    /**
     * @return array{data: array<int, mixed>, meta: array<string, mixed>}
     */
    protected function paginatorToResult(Paginator $paginator, string $resourceClass): array
    {
        $meta = [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'has_more' => $paginator->hasMorePages(),
        ];

        if (method_exists($paginator, 'total')) {
            $meta['total'] = $paginator->total();
            $meta['last_page'] = $paginator->lastPage();
        }

        return [
            'data' => $resourceClass::collection($paginator->getCollection())->resolve(),
            'meta' => $meta,
        ];
    }
}
