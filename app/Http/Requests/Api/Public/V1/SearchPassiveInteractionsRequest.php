<?php

namespace App\Http\Requests\Api\Public\V1;

use App\ModelFilters\PublicInteractionPassiveFilter;

class SearchPassiveInteractionsRequest extends SearchInteractionsRequest
{
    protected function filterClass(): string
    {
        return PublicInteractionPassiveFilter::class;
    }

    protected function typeRules(): array
    {
        return [
            'membrane' => ['nullable', 'integer', 'min:1'],
            'method' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
