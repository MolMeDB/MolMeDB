<?php

namespace App\Http\Requests\Api\Public\V1;

use App\ModelFilters\PublicInteractionActiveFilter;

class SearchActiveInteractionsRequest extends SearchInteractionsRequest
{
    protected function filterClass(): string
    {
        return PublicInteractionActiveFilter::class;
    }

    protected function typeRules(): array
    {
        return [
            'protein' => ['nullable', 'integer', 'min:1'],
            'uniprot' => ['nullable', 'string', 'max:20'],
            'type' => ['nullable', 'string', 'max:100'],
        ];
    }
}
