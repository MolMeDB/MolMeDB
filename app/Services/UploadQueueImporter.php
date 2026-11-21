<?php

namespace App\Services;

use App\Models\InteractionActive;
use App\Models\InteractionPassive;
use App\Models\Structure;
use App\Models\UploadQueue;
use DateTimeInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class UploadQueueImporter
{
    private const IGNORE_COLUMN = 'ignore';

    public function __construct(
        private readonly UploadQueueInteractionPayloadBuilder $payloadBuilder,
        private readonly UploadQueueDuplicateInteractionChecker $duplicateChecker,
        private readonly UploadQueueColumnRegistry $columns,
        private readonly UploadQueueCsvParser $csvParser,
    ) {}

    /**
     * @return array<int, array<string, string>>
     */
    public function previewRows(UploadQueue $record, int $limit = 25): array
    {
        return $this->readMappedRows($record, $limit)['rows'];
    }

    /**
     * @return array<string, mixed>
     */
    public function summarize(UploadQueue $record): array
    {
        $result = $this->readMappedRows($record, 0);

        return [
            'mode' => 'waiting_for_admin_review',
            'record_id' => $record->id,
            'dataset_id' => $record->dataset_id,
            'type' => $record->type,
            'prepared_rows' => $result['prepared_rows'],
            'skipped_rows' => $result['skipped_rows'],
        ];
    }

    public function import(UploadQueue $record, ?DateTimeInterface $deadline = null): array
    {
        if (! $record->config->detailedValidationPassed()) {
            throw new RuntimeException('Detailed validation must pass before importing upload data.');
        }

        return match ((int) $record->type) {
            UploadQueue::TYPE_PASSIVE_DATASET => $this->importPassiveInteractions($record, $deadline),
            UploadQueue::TYPE_ACTIVE_DATASET => $this->importActiveInteractions($record, $deadline),
            default => throw new RuntimeException('Unsupported upload queue type: '.$record->type),
        };
    }

    /**
     * This is the final passive interaction persistence path.
     *
     * @return array<string, mixed>
     */
    private function importPassiveInteractions(UploadQueue $record, ?DateTimeInterface $deadline = null): array
    {
        $progress = $this->resumeImportProgress($record, 'passive_interactions_imported');
        $summary = [
            'mode' => 'passive_interactions_imported',
            'record_id' => $record->id,
            'dataset_id' => $record->dataset_id,
            'type' => $record->type,
            'prepared_rows' => $progress['processed_rows'],
            'created_rows' => $progress['created_rows'],
            'skipped_rows' => $progress['skipped_rows'],
            'duplicate_rows' => $progress['duplicate_rows'],
            'duplicate_conflict_rows' => $progress['duplicate_conflict_rows'],
            'duplicate_warnings' => [],
            'duplicate_errors' => [],
            'sample_rows' => [],
        ];

        $importedStructureIds = [];
        $rowsSinceProgressSave = 0;
        $deferred = false;
        $nextLine = $progress['next_line'];

        $result = $this->forEachMappedRow(
            $record,
            function (array $row, int $lineNumber) use ($record, &$summary, &$importedStructureIds, &$rowsSinceProgressSave, &$deferred, &$nextLine, $deadline): bool {
                $payload = $this->payloadBuilder->passivePayload($record, $row);

                DB::transaction(function () use ($record, $payload, &$summary, &$importedStructureIds): void {
                    $summary['prepared_rows']++;

                    if ($this->skipDuplicate($record, $payload, $summary)) {
                        return;
                    }

                    $interaction = InteractionPassive::query()->create($payload);
                    $this->duplicateChecker->remember($record, $interaction, $payload);

                    $importedStructureIds[] = (int) $payload['structure_id'];
                    $summary['created_rows']++;
                    if (count($summary['sample_rows']) < 5) {
                        $summary['sample_rows'][] = $payload;
                    }
                });

                $rowsSinceProgressSave++;
                $nextLine = $lineNumber + 1;

                if ($rowsSinceProgressSave >= 50 || $this->deadlineReached($deadline)) {
                    $this->saveImportProgress($record, $summary, $nextLine);
                    $rowsSinceProgressSave = 0;
                }

                if ($this->deadlineReached($deadline)) {
                    $deferred = true;

                    return false;
                }

                return true;
            },
            $progress['next_line'],
        );

        $summary['skipped_rows'] += $result['skipped_rows'];
        $nextLine = max($nextLine, $result['last_line_number'] + 1);
        if ($rowsSinceProgressSave > 0 || $result['skipped_rows'] > 0) {
            $this->saveImportProgress($record, $summary, $nextLine);
        }

        if ($deferred) {
            $this->checkInternalIdentifiers($importedStructureIds, $summary);

            return $this->deferredImportResult($summary, $nextLine);
        }

        $this->failIfNothingWasImported($summary);
        $this->checkInternalIdentifiers($importedStructureIds, $summary);
        $record->config = $record->config->withoutProcessingProgress();
        $record->save();

        return $summary;
    }

    /**
     * This is the final active interaction persistence path.
     *
     * Rows take their category from the interaction type column; rows without a type fall back to the default category.
     * TODO: decide whether missing proteins should be created or rejected.
     *
     * @return array<string, mixed>
     */
    private function importActiveInteractions(UploadQueue $record, ?DateTimeInterface $deadline = null): array
    {
        $progress = $this->resumeImportProgress($record, 'active_interactions_imported');
        $summary = [
            'mode' => 'active_interactions_imported',
            'record_id' => $record->id,
            'dataset_id' => $record->dataset_id,
            'type' => $record->type,
            'prepared_rows' => $progress['processed_rows'],
            'created_rows' => $progress['created_rows'],
            'skipped_rows' => $progress['skipped_rows'],
            'duplicate_rows' => $progress['duplicate_rows'],
            'duplicate_conflict_rows' => $progress['duplicate_conflict_rows'],
            'duplicate_warnings' => [],
            'duplicate_errors' => [],
            'sample_rows' => [],
        ];

        $importedStructureIds = [];
        $rowsSinceProgressSave = 0;
        $deferred = false;
        $nextLine = $progress['next_line'];

        $result = $this->forEachMappedRow(
            $record,
            function (array $row, int $lineNumber) use ($record, &$summary, &$importedStructureIds, &$rowsSinceProgressSave, &$deferred, &$nextLine, $deadline): bool {
                $payload = $this->payloadBuilder->activePayload($record, $row);

                DB::transaction(function () use ($record, $payload, &$summary, &$importedStructureIds): void {
                    $summary['prepared_rows']++;

                    if ($this->skipDuplicate($record, $payload, $summary)) {
                        return;
                    }

                    $interaction = InteractionActive::query()->create($payload);
                    $this->duplicateChecker->remember($record, $interaction, $payload);

                    $importedStructureIds[] = (int) $payload['structure_id'];
                    $summary['created_rows']++;
                    if (count($summary['sample_rows']) < 5) {
                        $summary['sample_rows'][] = $payload;
                    }
                });

                $rowsSinceProgressSave++;
                $nextLine = $lineNumber + 1;

                if ($rowsSinceProgressSave >= 50 || $this->deadlineReached($deadline)) {
                    $this->saveImportProgress($record, $summary, $nextLine);
                    $rowsSinceProgressSave = 0;
                }

                if ($this->deadlineReached($deadline)) {
                    $deferred = true;

                    return false;
                }

                return true;
            },
            $progress['next_line'],
        );

        $summary['skipped_rows'] += $result['skipped_rows'];
        $nextLine = max($nextLine, $result['last_line_number'] + 1);
        if ($rowsSinceProgressSave > 0 || $result['skipped_rows'] > 0) {
            $this->saveImportProgress($record, $summary, $nextLine);
        }

        if ($deferred) {
            $this->checkInternalIdentifiers($importedStructureIds, $summary);

            return $this->deferredImportResult($summary, $nextLine);
        }

        $this->failIfNothingWasImported($summary);
        $this->checkInternalIdentifiers($importedStructureIds, $summary);
        $record->config = $record->config->withoutProcessingProgress();
        $record->save();

        return $summary;
    }

    /**
     * @param  array<int, int>  $structureIds
     * @param  array<string, mixed>  $summary
     */
    private function checkInternalIdentifiers(array $structureIds, array &$summary): void
    {
        $ids = collect($structureIds)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            $summary['internal_identifier_rows'] = 0;

            return;
        }

        $missingIdentifierIds = Structure::query()
            ->whereIn('id', $ids)
            ->whereNull('identifier')
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        $summary['internal_identifier_rows'] = count($missingIdentifierIds);

        if ($missingIdentifierIds === []) {
            return;
        }

        $exitCode = Artisan::call('structures:check-internal-identifiers', [
            '--ids' => $missingIdentifierIds,
        ]);

        if ($exitCode !== 0) {
            throw new RuntimeException('Imported interactions were saved, but internal structure identifiers could not be generated.');
        }
    }

    /**
     * @param  callable(array<string, string>, int): bool|null  $callback
     * @return array{prepared_rows: int, skipped_rows: int, last_line_number: int, rows: array<int, array<string, string>>}
     */
    private function forEachMappedRow(UploadQueue $record, callable $callback, int $startLine = 1): array
    {
        return $this->readMappedRows($record, 0, $callback, $startLine);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $summary
     */
    private function skipDuplicate(UploadQueue $record, array $payload, array &$summary): bool
    {
        $duplicate = $this->duplicateChecker->check($record, $payload, $this->interactionValueColumns($record));
        if ($duplicate === null) {
            return false;
        }

        $summary['skipped_rows']++;

        if ($duplicate['status'] === 'same') {
            $summary['duplicate_rows']++;
            $summary['duplicate_warnings'][] = $this->duplicateWarningMessage($duplicate['existing_id']);

            return true;
        }

        $summary['duplicate_conflict_rows']++;
        $summary['duplicate_errors'][] = $this->duplicateConflictMessage($duplicate['existing_id'], $duplicate['differences']);

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function interactionValueColumns(UploadQueue $record): array
    {
        return array_values($this->columns->interactionValueColumns($record));
    }

    private function duplicateWarningMessage(int $existingId): string
    {
        return "Interaction already exists in database as ID {$existingId} with the same values. Row was skipped.";
    }

    /**
     * @param  array<string, array{database: mixed, upload: mixed}>  $differences
     */
    private function duplicateConflictMessage(int $existingId, array $differences): string
    {
        $values = collect($differences)
            ->map(fn (array $difference, string $column): string => "{$column}: DB={$difference['database']}, upload={$difference['upload']}")
            ->implode('; ');

        return "Interaction already exists in database as ID {$existingId}, but has different values after rounding to 2 decimals ({$values}). Row was skipped.";
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function failIfNothingWasImported(array $summary): void
    {
        if (($summary['prepared_rows'] ?? 0) > 0 && ($summary['created_rows'] ?? 0) === 0 && ($summary['skipped_rows'] ?? 0) >= ($summary['prepared_rows'] ?? 0)) {
            throw new RuntimeException('All upload rows were skipped. There is nothing to import.');
        }
    }

    /**
     * @return array{next_line: int, processed_rows: int, created_rows: int, skipped_rows: int, duplicate_rows: int, duplicate_conflict_rows: int}
     */
    private function resumeImportProgress(UploadQueue $record, string $mode): array
    {
        $firstDataLine = $record->config->skipFirstRow() === 1 ? 2 : 1;
        $progress = $record->config->processingProgress();
        $configHash = $this->importConfigHash($record, $mode);

        if (($progress['phase'] ?? null) !== 'import' || ($progress['config_hash'] ?? null) !== $configHash) {
            return [
                'next_line' => $firstDataLine,
                'processed_rows' => 0,
                'created_rows' => 0,
                'skipped_rows' => 0,
                'duplicate_rows' => 0,
                'duplicate_conflict_rows' => 0,
            ];
        }

        return [
            'next_line' => max($firstDataLine, (int) ($progress['next_line'] ?? $firstDataLine)),
            'processed_rows' => max(0, (int) ($progress['processed_rows'] ?? 0)),
            'created_rows' => max(0, (int) ($progress['created_rows'] ?? 0)),
            'skipped_rows' => max(0, (int) ($progress['skipped_rows'] ?? 0)),
            'duplicate_rows' => max(0, (int) ($progress['duplicate_rows'] ?? 0)),
            'duplicate_conflict_rows' => max(0, (int) ($progress['duplicate_conflict_rows'] ?? 0)),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function saveImportProgress(UploadQueue $record, array $summary, int $nextLine): void
    {
        $record->config = $record->config->withProcessingProgress([
            'phase' => 'import',
            'mode' => $summary['mode'] ?? null,
            'config_hash' => $this->importConfigHash($record, (string) ($summary['mode'] ?? 'import')),
            'next_line' => $nextLine,
            'processed_rows' => (int) ($summary['prepared_rows'] ?? 0),
            'created_rows' => (int) ($summary['created_rows'] ?? 0),
            'skipped_rows' => (int) ($summary['skipped_rows'] ?? 0),
            'duplicate_rows' => (int) ($summary['duplicate_rows'] ?? 0),
            'duplicate_conflict_rows' => (int) ($summary['duplicate_conflict_rows'] ?? 0),
            'total_rows' => $record->config->validatedRows(),
            'heartbeat_at' => now()->toISOString(),
        ]);

        $record->save();
    }

    private function importConfigHash(UploadQueue $record, string $mode): string
    {
        return hash('sha256', json_encode([
            'mode' => $mode,
            'type' => (int) $record->type,
            'separator' => $this->csvParser->normalizeSeparator($record->config->separator()),
            'skip_first_row' => $record->config->skipFirstRow(),
            'attributes' => $record->config->attributes(),
            'validated_rows' => $record->config->validatedRows(),
        ], JSON_THROW_ON_ERROR));
    }

    private function deadlineReached(?DateTimeInterface $deadline): bool
    {
        return $deadline !== null && now()->greaterThanOrEqualTo($deadline);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function deferredImportResult(array $summary, int $nextLine): array
    {
        return [
            ...$summary,
            'deferred' => true,
            'next_line' => $nextLine,
        ];
    }

    /**
     * @return array{prepared_rows: int, skipped_rows: int, last_line_number: int, rows: array<int, array<string, string>>}
     */
    private function readMappedRows(UploadQueue $record, int $sampleLimit, ?callable $onRow = null, int $startLine = 1): array
    {
        $disk = $record->file?->storage;
        if (! is_string($disk) || trim($disk) === '') {
            throw new RuntimeException('Uploaded file storage is not configured.');
        }

        $path = $record->file?->path;
        if (! is_string($path) || trim($path) === '') {
            throw new RuntimeException('Uploaded file is missing on storage.');
        }

        // The import job can run in a long-lived queue worker process. Laravel caches
        // resolved disks (and, for remote drivers such as SFTP, their connection) for
        // the lifetime of that process, so a connection that died since the worker's
        // last job would otherwise make every later import fail. Force a fresh resolve.
        Storage::forgetDisk($disk);

        try {
            $stream = Storage::disk($disk)->readStream($path);
        } catch (Throwable $throwable) {
            Log::channel('upload')->warning('Failed to open uploaded file for import.', [
                'record_id' => $record->id,
                'disk' => $disk,
                'path' => $path,
                'exception' => $throwable->getMessage(),
            ]);
            $stream = false;
        }

        if (! $stream) {
            throw new RuntimeException('Cannot open uploaded file for import.');
        }

        $separator = $this->csvParser->normalizeSeparator($record->config->separator());
        $attributes = $record->config->attributes();
        $skipFirstRow = $record->config->skipFirstRow() === 1;
        $preparedRows = 0;
        $skippedRows = 0;
        $lineNumber = 0;
        $sampleRows = [];

        try {
            while (($line = fgets($stream)) !== false) {
                $lineNumber++;
                $line = trim(mb_convert_encoding($line, 'UTF-8', 'auto'));

                if ($line === '') {
                    continue;
                }

                if ($skipFirstRow && $lineNumber === 1) {
                    continue;
                }

                if ($lineNumber < $startLine) {
                    continue;
                }

                $mappedRow = $this->mapRow($this->csvParser->parseLine($line, $separator), $attributes);
                if ($mappedRow === []) {
                    $skippedRows++;

                    continue;
                }

                $preparedRows++;
                if ($onRow) {
                    if ($onRow($mappedRow, $lineNumber) === false) {
                        break;
                    }
                }

                if ($sampleLimit > 0 && count($sampleRows) < $sampleLimit) {
                    $sampleRows[] = $mappedRow;
                }
            }
        } finally {
            fclose($stream);
        }

        return [
            'prepared_rows' => $preparedRows,
            'skipped_rows' => $skippedRows,
            'last_line_number' => $lineNumber,
            'rows' => $sampleRows,
        ];
    }

    /**
     * @param  array<int, string|null>  $attributes
     * @return array<string, string>
     */
    private function mapRow(array $values, array $attributes): array
    {
        $mappedRow = [];

        foreach ($attributes as $index => $columnKey) {
            if (! is_string($columnKey) || $columnKey === self::IGNORE_COLUMN) {
                continue;
            }

            $value = $values[$index] ?? null;
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $mappedRow[$columnKey] = trim($value);
        }

        return $mappedRow;
    }
}
