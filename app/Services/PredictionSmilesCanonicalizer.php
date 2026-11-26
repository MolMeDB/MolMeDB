<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Modules\Rdkit\Rdkit;
use RuntimeException;

class PredictionSmilesCanonicalizer
{
    /**
     * @param  array<int, mixed>  $values
     * @return array{smiles: array<int, string>, duplicates_removed: int}
     *
     * @throws ValidationException
     */
    public function canonicalize(array $values): array
    {
        $entries = collect($values)
            ->map(fn (mixed $value, int $index): array => [
                'line' => $index + 1,
                'smiles' => trim((string) $value),
            ])
            ->filter(fn (array $entry): bool => $entry['smiles'] !== '' && ! str_starts_with($entry['smiles'], '#'))
            ->values();

        if ($entries->isEmpty()) {
            throw ValidationException::withMessages([
                'smiles' => ['At least one non-empty SMILES is required.'],
            ]);
        }

        $uniqueEntries = $entries->unique('smiles')->values();
        $duplicatesRemoved = $entries->count() - $uniqueEntries->count();
        $validationByInput = [];
        $uncachedEntries = [];

        foreach ($uniqueEntries as $entry) {
            $cacheKey = $this->cacheKey($entry['smiles']);
            $cached = Cache::get($cacheKey);

            if (is_array($cached) && is_string($cached['canonical_smiles'] ?? null)) {
                $validationByInput[$entry['smiles']] = $cached;
            } else {
                $uncachedEntries[] = $entry;
            }
        }

        if ($uncachedEntries !== []) {
            $this->validateUncached($uncachedEntries, $validationByInput);
        }

        $canonical = $uniqueEntries
            ->map(fn (array $entry): ?string => $validationByInput[$entry['smiles']]['canonical_smiles'] ?? null)
            ->filter()
            ->values();
        $uniqueCanonical = $canonical->unique()->values();
        $duplicatesRemoved += $canonical->count() - $uniqueCanonical->count();

        return [
            'smiles' => $uniqueCanonical->all(),
            'duplicates_removed' => $duplicatesRemoved,
        ];
    }

    /**
     * @param  array<int, array{line: int, smiles: string}>  $entries
     * @param  array<string, array<string, mixed>>  $validationByInput
     *
     * @throws ValidationException
     */
    private function validateUncached(array $entries, array &$validationByInput): void
    {
        $baseUrl = rtrim((string) config('services.rdkit.url'), '/');

        if ($baseUrl === '') {
            throw ValidationException::withMessages([
                'smiles' => ['SMILES validation service is not configured.'],
            ]);
        }

        $responses = Http::pool(
            fn (Pool $pool): array => collect($entries)
                ->map(fn (array $entry, int $index) => $pool
                    ->as((string) $index)
                    ->acceptJson()
                    ->connectTimeout(3)
                    ->timeout(30)
                    ->get($baseUrl.'/structure/predictions/validate', ['smi' => $entry['smiles']]))
                ->all(),
            concurrency: 10,
        );
        $errors = [];

        foreach ($entries as $index => $entry) {
            $response = $responses[(string) $index] ?? null;
            $validation = $response instanceof Response
                ? $response->json('data')
                : null;
            $canonical = is_array($validation)
                ? $validation['canonical_smiles'] ?? null
                : null;

            if (! $response instanceof Response || $response->serverError()) {
                throw new RuntimeException('SMILES validation service is temporarily unavailable.');
            }

            if (! is_array($validation)) {
                throw new RuntimeException('SMILES validation service returned an invalid response.');
            }

            if (
                ! $response->successful()
                || ($validation['valid'] ?? false) !== true
                || ! is_string($canonical)
                || trim($canonical) === ''
            ) {
                $validationErrors = is_array($validation['errors'] ?? null)
                    ? $validation['errors']
                    : ['The structure could not be validated.'];

                foreach ($validationErrors as $validationError) {
                    $errors[] = "SMILES on line {$entry['line']}: ".(string) $validationError;
                }

                continue;
            }

            $validation['canonical_smiles'] = trim($canonical);
            $validationByInput[$entry['smiles']] = $validation;
            Cache::put($this->cacheKey($entry['smiles']), $validation, now()->addDay());
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['smiles' => $errors]);
        }
    }

    private function cacheKey(string $smiles): string
    {
        $constraints = (array) config('prediction-workers.structure_validation', []);

        // The form of the canonical SMILES drops validations cached with an
        // older one, whose canonical_smiles would no longer match the stored.
        return 'prediction:validated-smiles:v1:'.Rdkit::CANONICAL_SMILES_FORM.':'.hash('sha256', json_encode($constraints).'|'.$smiles);
    }
}
