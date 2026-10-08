<?php

namespace App\Http\Requests\Api\Public\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

class SearchStructureRequest extends FormRequest
{
    public const MAX_IDENTIFIERS = 100;

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
            'query' => ['nullable', 'string', 'max:4000'],
            'smiles' => ['nullable', 'string', 'max:4000'],
            'substructure' => ['nullable', 'string', 'max:4000'],
            'inchikey' => ['nullable', 'string', 'regex:/^[A-Z]{14}-[A-Z]{10}-[A-Z]$/i'],
            'pubchem' => ['nullable', 'string', 'regex:/^\d{1,12}$/'],
            'chembl' => ['nullable', 'string', 'regex:/^CHEMBL\d{1,12}$/i'],
            'chebi' => ['nullable', 'string', 'regex:/^(CHEBI:)?\d{1,12}$/i'],
            'drugbank' => ['nullable', 'string', 'regex:/^DB\d{5,6}$/i'],
            'pdb' => ['nullable', 'string', 'regex:/^[A-Z0-9]{1,5}$/i'],
            'identifiers' => ['nullable', 'string', 'max:2000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateSmilesField($validator, 'smiles');
                $this->validateSmilesField($validator, 'substructure');
                $this->validateIdentifierList($validator);
            },
        ];
    }

    /**
     * A comma-separated list of at most MAX_IDENTIFIERS MolMeDB identifiers.
     */
    private function validateIdentifierList(Validator $validator): void
    {
        if (! filled($this->input('identifiers')) || $validator->errors()->has('identifiers')) {
            return;
        }

        $error = self::identifierListError((string) $this->input('identifiers'));

        if ($error !== null) {
            $validator->errors()->add('identifiers', $error);
        }
    }

    /**
     * Why a list of identifiers is not valid, or null when it is.
     */
    public static function identifierListError(string $identifiers): ?string
    {
        $identifiers = self::identifierList($identifiers);
        $pattern = '/'.config('fair.identifier_pattern').'/i';

        if (count($identifiers) > self::MAX_IDENTIFIERS) {
            return 'At most '.self::MAX_IDENTIFIERS.' identifiers can be requested at once.';
        }

        $invalid = array_filter($identifiers, fn (string $identifier): bool => preg_match($pattern, $identifier) !== 1);

        return $invalid === [] ? null : 'Invalid identifiers: '.implode(', ', $invalid).'.';
    }

    /**
     * @return array<int, string>
     */
    public static function identifierList(string $identifiers): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $identifiers)), 'strlen')));
    }

    private function validateSmilesField(Validator $validator, string $field): void
    {
        $smiles = trim((string) $this->input($field));

        if (
            $smiles === ''
            || $validator->errors()->has($field)
            || DB::getDriverName() !== 'pgsql'
        ) {
            return;
        }

        $error = DB::scalar('SELECT bingo.checkMolecule(?)', [$smiles]);

        if (filled($error)) {
            $validator->errors()->add(
                $field,
                "The $field SMILES is invalid.",
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return Arr::except($this->validated(), ['per_page']);
    }

    public function perPage(): int
    {
        return min(max($this->integer('per_page', 20), 1), 100);
    }
}
