<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\PredictionWorkers\Models\Prediction;

class StorePredictionDatasetRequest extends FormRequest
{
    public const MAX_MOLECULES = 100;

    public const MIN_TEMPERATURE = 20;

    public const MAX_TEMPERATURE = 45;

    public const MAX_DESCRIPTION_LENGTH = 512;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        return [
            'membranes' => ['required', 'array', 'size:1'],
            'membranes.*' => ['integer', 'distinct'],
            'methods' => ['required', 'array', 'size:1'],
            'methods.*' => ['string', 'distinct', Rule::in(array_keys(Prediction::enabledPredictionMethodOptions()))],
            'smiles' => ['required', 'array', 'min:1', 'max:'.self::MAX_MOLECULES],
            'smiles.*' => ['string', 'max:4000'],
            'temperature' => ['required', 'numeric', 'between:'.self::MIN_TEMPERATURE.','.self::MAX_TEMPERATURE],
            'description' => ['required', 'string', 'max:'.self::MAX_DESCRIPTION_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'membranes' => 'membranes',
            'methods' => 'methods',
            'smiles' => 'SMILES',
            'description' => 'description',
        ];
    }
}
