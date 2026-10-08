<?php

namespace App\Http\Requests\Api\Public\V1;

use App\ModelFilters\PublicInteractionFilter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * Filters shared by the passive and active interaction listings.
 */
abstract class SearchInteractionsRequest extends FormRequest
{
    public const MAX_PER_PAGE = 100;

    /**
     * @return class-string<PublicInteractionFilter>
     */
    abstract protected function filterClass(): string;

    /**
     * Filters specific to the interaction type.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    abstract protected function typeRules(): array;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $filter = $this->filterClass();
        $rangeRules = [];

        foreach ([...PublicInteractionFilter::RANGE_COLUMNS, ...$filter::valueColumns()] as $column) {
            $rangeRules["{$column}_min"] = ['nullable', 'numeric'];
            $rangeRules["{$column}_max"] = ['nullable', 'numeric'];
        }

        return [
            'structure' => ['nullable', 'string', 'max:30'],
            'publication' => ['nullable', 'integer', 'min:1'],
            ...$this->typeRules(),
            'charge' => ['nullable', 'string', 'max:40'],
            'with_value' => ['nullable', 'string', 'in:'.implode(',', $filter::valueColumns())],
            ...$rangeRules,
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return Arr::except($this->validated(), ['per_page', 'page']);
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 20);
    }
}
