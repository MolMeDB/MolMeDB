<?php

namespace App\Services;

use App\Models\Dataset;
use App\Models\InteractionPassive;
use App\Models\Structure;
use Exception;
use Illuminate\Support\Str;
use Modules\PredictionWorkers\Exceptions\InvalidCosmoResultException;
use Modules\PredictionWorkers\Exceptions\UnreadableCosmoResultException;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionMethod;
use Modules\PredictionWorkers\Models\PredictionStructure;
use Modules\PredictionWorkers\Services\CosmoXmlParser;
use RuntimeException;

/**
 * Turns a finished prediction's parsed COSMO result into a real passive
 * interaction record, following the same Structure-matching / reference
 * conventions as the manual upload pipeline (UploadQueueInteractionPayloadBuilder).
 */
class PredictionResultImporter
{
    /**
     * Imports of a prediction whose own result cannot be used that are tried
     * before the prediction is marked as failed.
     */
    public const MAX_UNUSABLE_RESULT_ATTEMPTS = 5;

    public function __construct(private CosmoXmlParser $cosmoXmlParser) {}

    /**
     * @return 'imported'|'duplicate'|'error'
     */
    public function import(Prediction $prediction, ?PredictionMethod $method): string
    {
        if (! $method) {
            return $this->fail($prediction, "No prediction_methods definition found for method_type [{$prediction->method_type}].");
        }

        if (! $method->remote_id) {
            return $this->fail($prediction, "Prediction method [{$method->key}] has no real Method mapped (remote_id is empty).");
        }

        if (! $method->primary_publication_id) {
            return $this->fail($prediction, "Prediction method [{$method->key}] has no primary reference configured.");
        }

        $realMembraneId = $prediction->predictionMembrane?->remote_id;

        if (! $realMembraneId) {
            return $this->fail($prediction, "Prediction membrane [{$prediction->membrane_id}] has no real Membrane mapped (remote_id is empty).");
        }

        try {
            $resultData = $this->resultData($prediction);
        } catch (InvalidCosmoResultException $exception) {
            // The file was read and is not a result: it never will be.
            return $this->failUnusableResult($prediction, 'The result file is not a valid COSMO result: '.$this->reason($exception));
        } catch (UnreadableCosmoResultException $exception) {
            // The file is read from a storage that can be out of reach:
            // tried again for as long as it takes.
            return $this->fail($prediction, 'The result file could not be read: '.$this->reason($exception));
        } catch (Exception $exception) {
            // The same for an error of the storage itself, whose message
            // (hosts, connections) stays out of the log the owner of the
            // dataset reads.
            report($exception);

            return $this->fail($prediction, 'The result file could not be read: the storage reported an error.');
        }

        if ($resultData === null) {
            return $this->fail($prediction, 'The parsed result data could not be loaded.');
        }

        $logPerm = $this->extractLogPerm($resultData);

        if ($logPerm === null) {
            return $this->failUnusableResult($prediction, 'Could not extract LogPerm from the parsed result data.');
        }

        $predictionStructure = $prediction->predictionStructure;

        if (! $predictionStructure || trim((string) $predictionStructure->canonical_smiles) === '') {
            return $this->failUnusableResult($prediction, 'Prediction structure has no canonical SMILES.');
        }

        $structure = $this->resolveStructure($predictionStructure);

        $existing = $this->findDuplicate(
            $structure->id,
            (int) $method->primary_publication_id,
            (int) $method->remote_id,
            $realMembraneId,
            (float) $prediction->temperature,
        );

        if ($existing) {
            $sameValue = $existing->logperm !== null
                && round((float) $existing->logperm, 2) === $logPerm;

            $prediction->forceFill([
                'step' => Prediction::STEP_RESULT_DB_STORE,
                'logs' => $prediction->logsWithWorkerEvent(
                    $sameValue
                        ? "Matching interaction #{$existing->id} already exists with the same LogPerm value - skipped."
                        : "Interaction #{$existing->id} already exists with a different LogPerm value (existing={$existing->logperm}, new={$logPerm}) - skipped, not overwritten.",
                    [
                        'existing_interaction_id' => $existing->id,
                        'existing_logperm' => $existing->logperm,
                        'new_logperm' => $logPerm,
                    ],
                    'RESULT IMPORT',
                ),
            ])->save();

            return 'duplicate';
        }

        $dataset = $this->resolveDataset($method, $prediction, $realMembraneId);

        $interaction = InteractionPassive::query()->create([
            'dataset_id' => $dataset->id,
            'structure_id' => $structure->id,
            'publication_id' => $method->primary_publication_id,
            'temperature' => round((float) $prediction->temperature, 2),
            'ph' => null,
            'charge' => null,
            'logperm' => $logPerm,
            // Keep in sync with when the underlying prediction was actually
            // created, not when this import job happened to run.
            'created_at' => $prediction->created_at,
        ]);

        $prediction->forceFill([
            'step' => Prediction::STEP_RESULT_DB_STORE,
            'logs' => $prediction->logsWithWorkerEvent(
                "Imported into interaction #{$interaction->id} (dataset #{$dataset->id}).",
                [
                    'interaction_id' => $interaction->id,
                    'dataset_id' => $dataset->id,
                    'structure_id' => $structure->id,
                    'logperm' => $logPerm,
                ],
                'RESULT IMPORT',
            ),
        ])->save();

        return 'imported';
    }

    /**
     * Why a result file was refused, short enough for the log of a prediction.
     */
    private function reason(Exception $exception): string
    {
        return Str::limit(Str::squish($exception->getMessage()), 200);
    }

    private function fail(Prediction $prediction, string $message): string
    {
        $prediction->forceFill([
            'logs' => $prediction->logsWithWorkerEvent($message, [], 'RESULT IMPORT', 'error'),
        ])->save();

        return 'error';
    }

    /**
     * For what is wrong with the prediction itself (its result or its
     * structure), not with the configuration around it: nothing an
     * administrator sets up later makes such an import succeed, so after a
     * few attempts the prediction is marked as failed instead of being tried
     * every minute forever. A missing method or membrane mapping stays with
     * fail(): those imports go through once the mapping is there.
     */
    private function failUnusableResult(Prediction $prediction, string $message): string
    {
        $attempts = 1 + collect(is_array($prediction->logs) ? $prediction->logs : [])
            ->filter(fn (mixed $entry): bool => is_array($entry)
                && ($entry['type'] ?? null) === 'RESULT IMPORT'
                && ($entry['context'] ?? null) === 'error'
                && ($entry['message'] ?? null) === $message)
            ->count();

        if ($attempts < self::MAX_UNUSABLE_RESULT_ATTEMPTS) {
            return $this->fail($prediction, $message);
        }

        $prediction->forceFill([
            'state' => Prediction::STATE_ERROR,
            'logs' => $prediction->logsWithWorkerEvent(
                "{$message} Given up after repeated attempts; the prediction is marked as failed.",
                ['attempts_in_log' => $attempts],
                'RESULT IMPORT',
                'error',
            ),
        ])->save();

        return 'error';
    }

    /**
     * PredictionResult.data is the COSMO XML parsed into [{solutes: [{logPerm, ...}]}]
     * (see CosmoXmlParser/SoluteResult). Each prediction is scoped to a single
     * structure, so the first job's first solute is taken as the value.
     *
     * Older results predate `data` always being populated at parse time - fall
     * back to parsing the stored XML file, whose PredictionResultJson DTO
     * serializes to the plain array shape the `data` column would hold.
     *
     * @return array<int|string, mixed>|null null when there is no result or no file of it
     *
     * @throws InvalidCosmoResultException when the file does not hold a COSMO result
     * @throws UnreadableCosmoResultException when the file could not be read as a whole
     * @throws Exception when the storage fails
     */
    private function resultData(Prediction $prediction): ?array
    {
        $result = $prediction->predictionResult;

        if (! $result) {
            return null;
        }

        $data = $result->data;

        if (! is_array($data)) {
            if (! $result->file) {
                return null;
            }

            $data = $this->cosmoXmlParser->parse($result->file)->jsonSerialize();
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Each prediction is scoped to a single structure, so the first job's
     * first solute is taken as the value.
     *
     * @param  array<int|string, mixed>  $data
     */
    private function extractLogPerm(array $data): ?float
    {
        $logPerm = $data[0]['solutes'][0]['logPerm'] ?? null;

        // Match the manual upload pipeline's normalizeInteractionValue(), which
        // rounds every stored numeric interaction value to 2 decimals.
        return is_numeric($logPerm) ? round((float) $logPerm, 2) : null;
    }

    /**
     * Re-canonicalizes via the same Rdkit service the manual upload pipeline uses
     * (UploadQueueExternalLookupCache::canonicalSmiles()), rather than trusting
     * PredictionStructure.canonical_smiles as-is - the prediction pipeline's own
     * canonicalizer is a different code path and isn't guaranteed to produce
     * byte-identical strings, which would otherwise risk duplicate Structure rows.
     *
     * Also backfills PredictionStructure.remote_id with the resolved real
     * Structure's id - this link was never populated by anything before (0%
     * of rows had it set), which is why the Prediction detail page couldn't
     * link to a real structure.
     */
    private function resolveStructure(PredictionStructure $predictionStructure): Structure
    {
        $smiles = trim((string) $predictionStructure->canonical_smiles);
        $canonical = app(UploadQueueExternalLookupCache::class)->canonicalSmiles($smiles);

        // Falling back to the prediction's own SMILES would create a second
        // Structure for a molecule stored under its RDKit form. The
        // prediction stays at STEP_RESULT_PARSE, so the next run retries it.
        if ($canonical === null) {
            throw new RuntimeException("RDKit could not canonicalize [{$smiles}]; the import will be retried.");
        }

        $structure = Structure::query()->storedAs($canonical)->first()
            ?? Structure::create(['canonical_smiles' => $canonical]);

        // remote_id is unique, but two prediction structures whose SMILES
        // differ can canonicalize to the same Structure; the link stays with
        // the first one rather than failing this import on every run.
        $linkedElsewhere = PredictionStructure::query()
            ->where('remote_id', $structure->id)
            ->whereKeyNot($predictionStructure->getKey())
            ->exists();

        if ($predictionStructure->remote_id !== $structure->id && ! $linkedElsewhere) {
            $predictionStructure->forceFill(['remote_id' => $structure->id])->save();
        }

        return $structure;
    }

    /**
     * Scoped to this importer's own output only (same structure, real method,
     * real membrane, primary publication, temperature, and charge/ph IS NULL -
     * this pipeline never has charge/ph data). Deliberately does NOT match
     * against unrelated pre-existing data such as the legacy "COSMO 2024" bulk
     * import (dataset #521), which records real per-ionization charge values
     * and isn't comparable to this job's output - this only guards against
     * re-importing a prediction this same job already processed.
     */
    private function findDuplicate(int $structureId, int $publicationId, int $methodId, int $membraneId, float $temperature): ?InteractionPassive
    {
        return InteractionPassive::query()
            ->where('structure_id', $structureId)
            ->where('publication_id', $publicationId)
            ->whereNull('charge')
            ->whereNull('ph')
            ->whereHas('dataset', function ($query) use ($methodId, $membraneId): void {
                $query->where('method_id', $methodId)->where('membrane_id', $membraneId);
            })
            ->get()
            ->first(fn (InteractionPassive $interaction): bool => $interaction->temperature !== null
                && round((float) $interaction->temperature, 2) === round($temperature, 2));
    }

    /**
     * One dataset per (method, membrane, calendar month it was imported in) -
     * e.g. "cosmoperm-DOPC-06/26" - rather than one growing forever per
     * (method, membrane). The name itself is the matching key (together with
     * type/membrane_id/method_id for clarity/safety), so a new month
     * naturally starts a new dataset without any extra date-range logic.
     */
    private function resolveDataset(PredictionMethod $method, Prediction $prediction, int $realMembraneId): Dataset
    {
        $period = now()->format('m/y');
        $membraneAbbreviation = $prediction->predictionMembrane?->abbreviation ?: "membrane-{$realMembraneId}";
        $name = "{$method->key}-{$membraneAbbreviation}-{$period}";

        $dataset = Dataset::query()->firstOrCreate(
            [
                'type' => Dataset::TYPE_PASSIVE_INTERNAL_COSMO,
                'membrane_id' => $realMembraneId,
                'method_id' => $method->remote_id,
                'name' => $name,
            ],
            [
                'comment' => 'Automatically generated by ImportFinishedPredictionResults.',
                'created_by' => null,
            ],
        );

        if ($method->secondary_publication_id) {
            $dataset->publications()->syncWithPivotValues(
                [$method->secondary_publication_id],
                ['model_type' => Dataset::class],
            );
        }

        return $dataset;
    }
}
