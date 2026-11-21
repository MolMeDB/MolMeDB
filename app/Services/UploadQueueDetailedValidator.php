<?php

namespace App\Services;

use App\Models\UploadQueue;
use App\Rules\UploadFile\ActiveInteractions\ColumnEc50;
use App\Rules\UploadFile\ActiveInteractions\ColumnIc50;
use App\Rules\UploadFile\ActiveInteractions\ColumnKi;
use App\Rules\UploadFile\ActiveInteractions\ColumnKm;
use App\Rules\UploadFile\ActiveInteractions\ColumnTarget;
use App\Rules\UploadFile\Identifiers\ColumnSmiles;
use App\Rules\UploadFile\PassiveInteractions\ColumnGpen;
use App\Rules\UploadFile\PassiveInteractions\ColumnGwat;
use App\Rules\UploadFile\PassiveInteractions\ColumnLogK;
use App\Rules\UploadFile\PassiveInteractions\ColumnLogPerm;
use App\Rules\UploadFile\PassiveInteractions\ColumnXmin;
use App\ValueObjects\UploadQueueConfig;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class UploadQueueDetailedValidator
{
    private const IGNORE_COLUMN = 'ignore';

    public function __construct(
        private readonly UploadQueueInteractionPayloadBuilder $payloadBuilder,
        private readonly UploadQueueDuplicateInteractionChecker $duplicateChecker,
        private readonly UploadQueueColumnRegistry $columns,
        private readonly UploadQueueCsvParser $csvParser,
    ) {}

    public function validate(UploadQueue $record, ?DateTimeInterface $deadline = null): array
    {
        $disk = $record->file?->storage;
        if (! is_string($disk) || trim($disk) === '') {
            return [
                'ok' => false,
                'errors' => ['Uploaded file storage is not configured.'],
                'warnings' => [],
                'config' => null,
            ];
        }

        $path = $record->file?->path;
        if (! is_string($path) || $path === '') {
            return [
                'ok' => false,
                'errors' => ['Uploaded file was not found on storage.'],
                'warnings' => [],
                'config' => null,
            ];
        }

        // Long-running queue workers keep a resolved disk (and its underlying connection,
        // e.g. SFTP) cached for the lifetime of the worker process. Forcing a fresh resolve
        // here avoids reusing a connection that died since the last job this worker ran.
        Storage::forgetDisk($disk);

        try {
            $stream = Storage::disk($disk)->readStream($path);
        } catch (Throwable $throwable) {
            Log::channel('upload')->warning('Failed to open uploaded file for detailed validation.', [
                'record_id' => $record->id,
                'disk' => $disk,
                'path' => $path,
                'exception' => $throwable->getMessage(),
            ]);
            $stream = false;
        }

        if (! $stream) {
            return [
                'ok' => false,
                'errors' => ['Cannot open uploaded file.'],
                'warnings' => [],
                'config' => null,
            ];
        }

        $configured = $record->config->toArray();
        $hasManualConfig = is_array($configured['attributes'] ?? null) &&
            isset($configured['separator']) &&
            isset($configured['skip_first_row']);

        if ($hasManualConfig) {
            $separator = $this->csvParser->normalizeSeparator((string) $configured['separator']);
            $columnKeys = array_map(function ($value) {
                if (! is_string($value) || trim($value) === '') {
                    return self::IGNORE_COLUMN;
                }

                return trim($value);
            }, $configured['attributes']);

            $skipFirstRow = (int) $configured['skip_first_row'] === 1 ? 1 : 0;
            $firstDataLine = $skipFirstRow === 1 ? 2 : 1;
            $currentLineNumber = 0;
        } else {
            $headerLine = null;
            $currentLineNumber = 0;

            while (($line = fgets($stream)) !== false) {
                $currentLineNumber++;
                $line = $this->normalizeLine($line);
                if ($line === '') {
                    continue;
                }

                $headerLine = $line;
                break;
            }

            if ($headerLine === null) {
                fclose($stream);

                return [
                    'ok' => false,
                    'errors' => ['File must contain a header and at least one data row.'],
                    'warnings' => [],
                    'config' => null,
                ];
            }

            $separator = $this->detectSeparator($headerLine);
            $headerRow = $this->csvParser->parseLine($headerLine, $separator);
            $columnKeys = $this->buildColumnMapping($record, $headerRow);
            $skipFirstRow = 1;
            $firstDataLine = $currentLineNumber + 1;
        }

        $requiredError = $this->validateRequiredColumns($record, $columnKeys);
        if ($requiredError !== null) {
            fclose($stream);

            return [
                'ok' => false,
                'errors' => [$requiredError],
                'warnings' => [],
                'config' => null,
            ];
        }

        $progress = $this->resumeProgress($record, $separator, $columnKeys, $firstDataLine);
        $nextLine = $progress['next_line'];
        $validatedRows = $progress['validated_rows'];
        $processedRows = $progress['processed_rows'];
        $skippedDuplicateRows = $progress['skipped_duplicate_rows'];
        $validators = $this->validatorsByKey($record);
        // Lines before next_line are skipped on resume, so the messages they
        // produced come back from the checkpoint instead of being lost.
        $errors = $progress['errors'];
        $rowErrors = $progress['row_errors'];
        $warnings = $progress['warnings'];
        $maxErrors = 20;
        $maxRowErrors = 20;
        $maxWarnings = 20;
        $rowsSinceProgressSave = 0;

        while (($line = fgets($stream)) !== false) {
            $currentLineNumber++;
            $line = $this->normalizeLine($line);
            if ($line === '') {
                continue;
            }

            if ($currentLineNumber < $nextLine) {
                continue;
            }

            $rawValues = $this->csvParser->parseLine($line, $separator);

            if (count($rawValues) !== count($columnKeys)) {
                $errors[] = "Line {$currentLineNumber}: number of values does not match header column count.";
                if (count($errors) >= $maxErrors) {
                    break;
                }

                continue;
            }

            $row = [];
            foreach ($rawValues as $index => $value) {
                $columnKey = $columnKeys[$index] ?? self::IGNORE_COLUMN;
                if (! is_string($columnKey) || $columnKey === self::IGNORE_COLUMN) {
                    continue;
                }

                $row[$columnKey] = is_string($value) ? trim($value) : $value;
            }

            $rules = [];
            foreach (array_keys($row) as $columnKey) {
                if (isset($validators[$columnKey])) {
                    $rules[$columnKey] = $validators[$columnKey];
                }
            }

            $validator = Validator::make($row, $rules);
            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) {
                    $errors[] = "Line {$currentLineNumber}: {$message}";
                    if (count($errors) >= $maxErrors) {
                        break 2;
                    }
                }

                continue;
            }

            $validatedRows++;
            $processedRows++;
            $rowsSinceProgressSave++;
            $duplicate = $this->duplicateForRow($record, $row);
            if ($duplicate === null) {
                if ($this->shouldSaveProgress($rowsSinceProgressSave, $deadline)) {
                    $this->saveProgress($record, $separator, $columnKeys, $firstDataLine, $currentLineNumber + 1, $validatedRows, $processedRows, $skippedDuplicateRows, $errors, $rowErrors, $warnings);
                    $rowsSinceProgressSave = 0;
                }

                if ($this->deadlineReached($deadline)) {
                    fclose($stream);

                    return $this->deferredResult($currentLineNumber + 1, $validatedRows, $processedRows);
                }

                continue;
            }

            $skippedDuplicateRows++;

            if ($duplicate['status'] === 'same') {
                if (count($warnings) < $maxWarnings) {
                    $warnings[] = $this->duplicateWarningMessage($currentLineNumber, $duplicate['existing_id']);
                }
            } elseif (count($rowErrors) < $maxRowErrors) {
                $rowErrors[] = $this->duplicateConflictMessage($currentLineNumber, $duplicate['existing_id'], $duplicate['differences']);
            }

            if ($this->shouldSaveProgress($rowsSinceProgressSave, $deadline)) {
                $this->saveProgress($record, $separator, $columnKeys, $firstDataLine, $currentLineNumber + 1, $validatedRows, $processedRows, $skippedDuplicateRows, $errors, $rowErrors, $warnings);
                $rowsSinceProgressSave = 0;
            }

            if ($this->deadlineReached($deadline)) {
                fclose($stream);

                return $this->deferredResult($currentLineNumber + 1, $validatedRows, $processedRows);
            }
        }

        fclose($stream);

        if ($processedRows < 1) {
            // When every row failed, its line errors explain why; the generic
            // message is only for a file without data rows.
            return [
                'ok' => false,
                'errors' => $errors !== [] ? $errors : ['File does not contain rows for validation.'],
                'warnings' => [],
                'config' => null,
            ];
        }

        if ($skippedDuplicateRows === $processedRows) {
            $errors[] = 'All rows would be skipped because they already exist in the database. There is nothing to import.';
            $errors = array_values(array_unique(array_merge($errors, $rowErrors, $warnings)));
        }

        if (count($errors) > 0) {
            return [
                'ok' => false,
                'errors' => $errors,
                'row_errors' => $rowErrors,
                'warnings' => $warnings,
                'config' => null,
            ];
        }

        $record->config = $record->config->withoutProcessingProgress();
        $record->save();

        return [
            'ok' => true,
            'errors' => [],
            'row_errors' => $rowErrors,
            'warnings' => $warnings,
            'config' => UploadQueueConfig::configured(
                $separator,
                $skipFirstRow,
                $columnKeys,
            )
                ->withDetailedValidation(
                    true,
                    $validatedRows,
                    now()->toISOString(),
                )
                ->withoutProcessingProgress()
                ->toArray(),
        ];
    }

    private function normalizeLine(string $line): string
    {
        return trim(mb_convert_encoding($line, 'UTF-8', 'auto'));
    }

    /**
     * @param  array<int, string|null>  $columnKeys
     * @return array{next_line: int, validated_rows: int, processed_rows: int, skipped_duplicate_rows: int, errors: array<int, string>, row_errors: array<int, string>, warnings: array<int, string>}
     */
    private function resumeProgress(UploadQueue $record, string $separator, array $columnKeys, int $firstDataLine): array
    {
        $progress = $record->config->processingProgress();
        $configHash = $this->validationConfigHash($separator, $columnKeys, $firstDataLine);

        if (($progress['phase'] ?? null) !== 'detailed_validation' || ($progress['config_hash'] ?? null) !== $configHash) {
            return [
                'next_line' => $firstDataLine,
                'validated_rows' => 0,
                'processed_rows' => 0,
                'skipped_duplicate_rows' => 0,
                'errors' => [],
                'row_errors' => [],
                'warnings' => [],
            ];
        }

        return [
            'next_line' => max($firstDataLine, (int) ($progress['next_line'] ?? $firstDataLine)),
            'validated_rows' => max(0, (int) ($progress['validated_rows'] ?? 0)),
            'processed_rows' => max(0, (int) ($progress['processed_rows'] ?? 0)),
            'skipped_duplicate_rows' => max(0, (int) ($progress['skipped_duplicate_rows'] ?? 0)),
            'errors' => array_values(array_filter((array) ($progress['errors'] ?? []), 'is_string')),
            'row_errors' => array_values(array_filter((array) ($progress['row_errors'] ?? []), 'is_string')),
            'warnings' => array_values(array_filter((array) ($progress['warnings'] ?? []), 'is_string')),
        ];
    }

    /**
     * @param  array<int, string|null>  $columnKeys
     * @param  array<int, string>  $errors
     * @param  array<int, string>  $rowErrors
     * @param  array<int, string>  $warnings
     */
    private function saveProgress(
        UploadQueue $record,
        string $separator,
        array $columnKeys,
        int $firstDataLine,
        int $nextLine,
        int $validatedRows,
        int $processedRows,
        int $skippedDuplicateRows,
        array $errors,
        array $rowErrors,
        array $warnings,
    ): void {
        $record->config = $record->config->withProcessingProgress([
            'phase' => 'detailed_validation',
            'config_hash' => $this->validationConfigHash($separator, $columnKeys, $firstDataLine),
            'next_line' => $nextLine,
            'validated_rows' => $validatedRows,
            'processed_rows' => $processedRows,
            'skipped_duplicate_rows' => $skippedDuplicateRows,
            'errors' => $errors,
            'row_errors' => $rowErrors,
            'warnings' => $warnings,
            'heartbeat_at' => now()->toISOString(),
        ]);

        $record->save();
    }

    /**
     * @param  array<int, string|null>  $columnKeys
     */
    private function validationConfigHash(string $separator, array $columnKeys, int $firstDataLine): string
    {
        return hash('sha256', json_encode([
            'separator' => $separator,
            'column_keys' => array_values($columnKeys),
            'first_data_line' => $firstDataLine,
        ], JSON_THROW_ON_ERROR));
    }

    private function shouldSaveProgress(int $rowsSinceProgressSave, ?DateTimeInterface $deadline): bool
    {
        return $rowsSinceProgressSave >= 100 || $this->deadlineReached($deadline);
    }

    private function deadlineReached(?DateTimeInterface $deadline): bool
    {
        return $deadline !== null && now()->greaterThanOrEqualTo($deadline);
    }

    /**
     * @return array{ok: false, deferred: true, next_line: int, validated_rows: int, processed_rows: int, errors: array<int, string>, warnings: array<int, string>, config: null}
     */
    private function deferredResult(int $nextLine, int $validatedRows, int $processedRows): array
    {
        return [
            'ok' => false,
            'deferred' => true,
            'next_line' => $nextLine,
            'validated_rows' => $validatedRows,
            'processed_rows' => $processedRows,
            'errors' => [],
            'warnings' => [],
            'config' => null,
        ];
    }

    private function detectSeparator(string $headerLine): string
    {
        $candidates = [',', ';', "\t"];
        $bestSeparator = ',';
        $bestColumns = 1;

        foreach ($candidates as $candidate) {
            $columns = count($this->csvParser->parseLine($headerLine, $candidate));
            if ($columns > $bestColumns) {
                $bestColumns = $columns;
                $bestSeparator = $candidate;
            }
        }

        return $bestSeparator;
    }

    private function buildColumnMapping(UploadQueue $record, array $headers): array
    {
        $options = $this->validatorClasses($record);
        // An exact name wins over a normalized one, and an attribute goes to
        // the first column naming it: the same attribute twice would import
        // the wrong value.
        $exactLookup = [];
        $normalizedLookup = [];
        foreach ($options as $className) {
            if (! property_exists($className, 'key')) {
                continue;
            }

            $key = $className::$key;
            $names = property_exists($className, 'label') ? [$key, $className::$label] : [$key];
            foreach ($names as $name) {
                $exactLookup[mb_strtolower(trim($name))] ??= $key;
                $normalizedLookup[$this->normalizeKey($name)] ??= $key;
            }
        }

        $mapping = [];
        $taken = [];

        foreach ($headers as $header) {
            $header = (string) $header;
            $key = $exactLookup[mb_strtolower(trim($header))]
                ?? $normalizedLookup[$this->normalizeKey($header)]
                ?? null;

            if ($key === null || in_array($key, $taken, true)) {
                $mapping[] = self::IGNORE_COLUMN;

                continue;
            }

            $mapping[] = $key;
            $taken[] = $key;
        }

        return $mapping;
    }

    /**
     * Letters and digits only, with the accuracy marker ("+/-" or "±") kept
     * as "pm", so "+/-Gpen" and "± Gpen" still differ from "Gpen".
     */
    private function normalizeKey(string $value): string
    {
        $value = str_replace(['+/-', '±'], 'pm', trim($value));

        return mb_strtolower(
            preg_replace('/[^a-z0-9]+/i', '', $value) ?? ''
        );
    }

    private function validateRequiredColumns(UploadQueue $record, array $columnKeys): ?string
    {
        if (! in_array(ColumnSmiles::$key, $columnKeys, true)) {
            return 'Column '.ColumnSmiles::$label.' is required.';
        }

        if ($record->type === UploadQueue::TYPE_ACTIVE_DATASET) {
            if (! in_array(ColumnTarget::$key, $columnKeys, true)) {
                return 'Column '.ColumnTarget::$label.' is required for active interactions.';
            }

            if (
                ! in_array(ColumnEc50::$key, $columnKeys, true) &&
                ! in_array(ColumnIc50::$key, $columnKeys, true) &&
                ! in_array(ColumnKi::$key, $columnKeys, true) &&
                ! in_array(ColumnKm::$key, $columnKeys, true)
            ) {
                return 'At least one active interaction value column is required (EC50, IC50, Ki or Km).';
            }
        }

        if ($record->type === UploadQueue::TYPE_PASSIVE_DATASET) {
            if (
                ! in_array(ColumnGpen::$key, $columnKeys, true) &&
                ! in_array(ColumnGwat::$key, $columnKeys, true) &&
                ! in_array(ColumnLogK::$key, $columnKeys, true) &&
                ! in_array(ColumnLogPerm::$key, $columnKeys, true) &&
                ! in_array(ColumnXmin::$key, $columnKeys, true)
            ) {
                return 'At least one passive interaction value column is required (Gpen, Gwat, LogK, LogPerm or Xmin).';
            }
        }

        return null;
    }

    private function validatorsByKey(UploadQueue $record): array
    {
        $validators = [];
        foreach ($this->validatorClasses($record) as $className) {
            if (! property_exists($className, 'key')) {
                continue;
            }
            $validators[$className::$key] = new $className;
        }

        return $validators;
    }

    private function validatorClasses(UploadQueue $record): array
    {
        return $this->columns->validatorClasses($record);
    }

    /**
     * @param  array<string, string>  $row
     * @return array{status: 'same'|'conflict', existing_id: int, differences: array<string, array{database: mixed, upload: mixed}>}|null
     */
    private function duplicateForRow(UploadQueue $record, array $row): ?array
    {
        $payload = $record->type === UploadQueue::TYPE_ACTIVE_DATASET
            ? $this->payloadBuilder->activePayload($record, $row, false)
            : $this->payloadBuilder->passivePayload($record, $row, false);

        return $this->duplicateChecker->check($record, $payload, $this->interactionValueColumns($record));
    }

    /**
     * @return array<int, string>
     */
    private function interactionValueColumns(UploadQueue $record): array
    {
        return array_values($this->columns->interactionValueColumns($record));
    }

    private function duplicateWarningMessage(int $rowIndex, int $existingId): string
    {
        return "Line {$rowIndex}: interaction already exists in database as ID {$existingId} with the same values. Row will be skipped during import.";
    }

    /**
     * @param  array<string, array{database: mixed, upload: mixed}>  $differences
     */
    private function duplicateConflictMessage(int $rowIndex, int $existingId, array $differences): string
    {
        $values = collect($differences)
            ->map(fn (array $difference, string $column): string => "{$column}: DB={$difference['database']}, upload={$difference['upload']}")
            ->implode('; ');

        return "Line {$rowIndex}: interaction already exists in database as ID {$existingId}, but has different values after rounding to 2 decimals ({$values}). Row will be skipped.";
    }
}
