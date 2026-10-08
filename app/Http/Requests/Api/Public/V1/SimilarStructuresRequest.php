<?php

namespace App\Http\Requests\Api\Public\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SimilarStructuresRequest extends FormRequest
{
    public const DEFAULT_THRESHOLD = 0.8;

    /**
     * Below it the similarity is too weak to be meaningful and a search
     * scores thousands of structures.
     */
    public const MIN_THRESHOLD = 0.7;

    public const MAX_PER_PAGE = 50;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'threshold' => ['nullable', 'numeric', 'between:'.self::MIN_THRESHOLD.',1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function threshold(): float
    {
        return (float) ($this->validated('threshold') ?? self::DEFAULT_THRESHOLD);
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 20);
    }
}
