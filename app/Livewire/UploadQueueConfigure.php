<?php

namespace App\Livewire;

use App\Models\UploadQueue;
use App\Rules\UploadFile\ActiveInteractions\ColumnEc50;
use App\Rules\UploadFile\ActiveInteractions\ColumnEc50Acc;
use App\Rules\UploadFile\ActiveInteractions\ColumnIc50;
use App\Rules\UploadFile\ActiveInteractions\ColumnIc50Acc;
use App\Rules\UploadFile\ActiveInteractions\ColumnInteractionType;
use App\Rules\UploadFile\ActiveInteractions\ColumnKi;
use App\Rules\UploadFile\ActiveInteractions\ColumnKiAcc;
use App\Rules\UploadFile\ActiveInteractions\ColumnKm;
use App\Rules\UploadFile\ActiveInteractions\ColumnKmAcc;
use App\Rules\UploadFile\ActiveInteractions\ColumnProteinName;
use App\Rules\UploadFile\ActiveInteractions\ColumnTarget;
use App\Rules\UploadFile\ColumnComment;
use App\Rules\UploadFile\ColumnLogP;
use App\Rules\UploadFile\ColumnPrimaryReference;
// use App\Rules\UploadFile\ColumnSecondaryReference;
use App\Rules\UploadFile\Identifiers\ColumnChebi;
use App\Rules\UploadFile\Identifiers\ColumnChembl;
use App\Rules\UploadFile\Identifiers\ColumnDrugbank;
use App\Rules\UploadFile\Identifiers\ColumnName;
use App\Rules\UploadFile\Identifiers\ColumnPdb;
use App\Rules\UploadFile\Identifiers\ColumnPubchem;
use App\Rules\UploadFile\Identifiers\ColumnSmiles;
// use App\Rules\UploadFile\Identifiers\ColumnUniprot;
use App\Rules\UploadFile\PassiveInteractions\ColumnGpen;
use App\Rules\UploadFile\PassiveInteractions\ColumnGpenAcc;
use App\Rules\UploadFile\PassiveInteractions\ColumnGwat;
use App\Rules\UploadFile\PassiveInteractions\ColumnGwatAcc;
use App\Rules\UploadFile\PassiveInteractions\ColumnLogK;
use App\Rules\UploadFile\PassiveInteractions\ColumnLogKAcc;
use App\Rules\UploadFile\PassiveInteractions\ColumnLogPerm;
use App\Rules\UploadFile\PassiveInteractions\ColumnLogPermAcc;
use App\Rules\UploadFile\PassiveInteractions\ColumnXmin;
use App\Rules\UploadFile\PassiveInteractions\ColumnXminAcc;
use App\Services\UploadQueueCsvParser;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;

class UploadQueueConfigure extends Component
{
    public UploadQueue $record;

    public array $previewRows = [];

    public array $columnMapping = [];

    public array $columnValidTypes = [];

    public int $skipFirstRow = 0;

    public int $totalRows = 0;

    public int $startLine = 0;

    public string $separator = ',';

    public bool $isValidated = false;

    public bool $isValidating = false;

    public array $validColumnTypes = [];

    public array $errorMessages = [];

    public array $warningMessages = [];

    public bool $skipWarnings = false;

    public array $validSeparators = [
        ';',
        ',',
        '\t',
    ];

    const IGNORE_COLUMN = 'ignore';

    private array $columnTypeOptions = [
        'passive' => [
            ColumnName::class,
            ColumnSmiles::class,
            ColumnPubchem::class,
            ColumnPdb::class,
            ColumnChembl::class,
            ColumnChebi::class,
            ColumnDrugbank::class,
            ColumnComment::class,
            ColumnPrimaryReference::class,
            // ColumnSecondaryReference::class,
            ColumnLogP::class,
            ColumnXmin::class,
            ColumnXminAcc::class,
            ColumnGpen::class,
            ColumnGpenAcc::class,
            ColumnGwat::class,
            ColumnGwatAcc::class,
            ColumnLogK::class,
            ColumnLogKAcc::class,
            ColumnLogPerm::class,
            ColumnLogPermAcc::class,
        ],
        'active' => [
            ColumnName::class,
            ColumnSmiles::class,
            // ColumnUniprot::class,
            ColumnPubchem::class,
            ColumnPdb::class,
            ColumnChembl::class,
            ColumnChebi::class,
            ColumnDrugbank::class,
            ColumnComment::class,
            ColumnPrimaryReference::class,
            // ColumnSecondaryReference::class,
            ColumnLogP::class,
            ColumnTarget::class,
            ColumnProteinName::class,
            ColumnInteractionType::class,
            ColumnEc50::class,
            ColumnEc50Acc::class,
            ColumnIc50::class,
            ColumnIc50Acc::class,
            ColumnKi::class,
            ColumnKiAcc::class,
            ColumnKm::class,
            ColumnKmAcc::class,
        ],
    ];

    public function mount(UploadQueue $record)
    {
        $this->record = $record;

        $variant = match ($this->record->type) {
            UploadQueue::TYPE_PASSIVE_DATASET => 'passive',
            UploadQueue::TYPE_ACTIVE_DATASET => 'active',
            default => null
        };

        $this->validColumnTypes = $this->columnTypeOptions[$variant] ?? [];

        $this->validColumnTypes = [
            self::IGNORE_COLUMN => 'Ignore',
            ...(
                array_combine(
                    array_map(
                        fn ($column) => $column::$key,
                        $this->validColumnTypes,
                    ),
                    array_map(
                        fn ($column) => $column::$label,
                        $this->validColumnTypes,
                    ))
            ),
        ];

        if ($this->record->config) {
            $this->skipFirstRow = $this->record->config->skipFirstRow($this->skipFirstRow);
            $this->separator = in_array($this->record->config->separator(), $this->validSeparators) ? $this->record->config->separator() : $this->separator;
            $this->columnMapping = $this->record->config->attributes() ? array_filter(
                $this->record->config->attributes(),
                fn ($val) => array_key_exists($val, $this->validColumnTypes)
            ) : $this->columnMapping;
        }

        $this->reloadTableContent();

        return $this;
    }

    private function reloadTableContent($updateColumnOptions = true)
    {
        $disk = $this->record->file?->storage;
        $path = $this->record->file?->path;
        if (is_string($disk) && trim($disk) !== '' && $this->record->file?->existsOnDisk($disk)) {
            $stream = Storage::disk($disk)->readStream($path);

            if (! $stream) {
                $this->errorMessages[] = 'Cannot read uploaded file. Please, try again later.';
                $this->isValidated = false;

                return;
            }

            $i = 0;
            $this->previewRows = [];
            while (($line = fgets($stream)) !== false) {
                $i++;
                if (($this->skipFirstRow && $i == 1) ||
                    $i < $this->startLine) {
                    continue;
                }

                if (count($this->previewRows) < 6) {
                    $this->previewRows[] = app(UploadQueueCsvParser::class)->parseLine($line, $this->separator);
                }
            }

            $this->totalRows = $i;
            fclose($stream);

            if (count($this->previewRows) && $updateColumnOptions) {
                $total_columns = count($this->previewRows[0] ?? []);
                $this->columnMapping = count($this->columnMapping) == $total_columns ? $this->columnMapping : array_fill(0, $total_columns, null);
                $this->updateColumnTypeOptions();
            }
        } else {
            $this->errorMessages[] = 'Uploaded file not found on remote server.';

            return;
        }
    }

    private function updateColumnTypeOptions()
    {
        $used = $this->columnMapping;

        $t = array_fill(0, count($used), $this->validColumnTypes);

        $this->columnValidTypes = array_map(
            fn ($types, $key) => array_filter($types, function ($value) use ($used, $key) {
                return ! in_array($value, $used) || $value === $used[$key] || $value === self::IGNORE_COLUMN;
            }, ARRAY_FILTER_USE_KEY),
            $t,
            array_keys($t)
        );
    }

    public function updatedSkipFirstRow()
    {
        $this->isValidated = false;
        $this->reloadTableContent(updateColumnOptions: false);
    }

    public function updatedSeparator()
    {
        $this->isValidated = false;
        $this->reloadTableContent();
    }

    public function updatedColumnMapping()
    {
        $this->isValidated = false;
        $this->updateColumnTypeOptions();
    }

    public function render()
    {
        return view('livewire.upload-queue-configure');
    }

    public function validateColumns()
    {
        $this->errorMessages = [];

        $columnMapping = $this->columnMapping;

        if (empty($columnMapping) || ! is_array($columnMapping) || ! in_array(ColumnSmiles::$key, $columnMapping)) {
            $this->errorMessages[] = 'Column '.ColumnSmiles::$label.' is required.';
            $this->isValidated = false;

            return;
        }

        if ($this->record->type == UploadQueue::TYPE_ACTIVE_DATASET &&
            ! in_array(ColumnTarget::$key, $columnMapping)) {
            $this->errorMessages[] = 'Column target is required.';
            $this->isValidated = false;

            return;
        }

        if ($this->record->type == UploadQueue::TYPE_ACTIVE_DATASET &&
            ! in_array(ColumnEc50::$key, $columnMapping) &&
            ! in_array(ColumnIc50::$key, $columnMapping) &&
            ! in_array(ColumnKi::$key, $columnMapping) &&
            ! in_array(ColumnKm::$key, $columnMapping)) {
            $this->errorMessages[] = 'At least one active interaction column is required.';
            $this->isValidated = false;

            return;
        }

        if ($this->record->type == UploadQueue::TYPE_PASSIVE_DATASET &&
            ! in_array(ColumnGpen::$key, $columnMapping) &&
            ! in_array(ColumnGwat::$key, $columnMapping) &&
            ! in_array(ColumnLogK::$key, $columnMapping) &&
            ! in_array(ColumnLogPerm::$key, $columnMapping) &&
            ! in_array(ColumnXmin::$key, $columnMapping)) {
            $this->errorMessages[] = 'At least one passive interaction column is required.';
            $this->isValidated = false;

            return;
        }

        $disk = $this->record->file?->storage;
        $path = $this->record->file?->path;
        if (is_string($disk) && trim($disk) !== '' && $this->record->file?->existsOnDisk($disk)) {
            $stream = Storage::disk($disk)->readStream($path);

            if (! $stream) {
                $this->errorMessages[] = 'Cannot read uploaded file. Please, try again later.';
                $this->isValidated = false;

                return;
            }

            $i = 0;
            while (($line = fgets($stream)) !== false) {
                $i++;
                if ($this->skipFirstRow && $i == 1) {
                    continue;
                }

                $row = array_combine(
                    $this->columnMapping,
                    app(UploadQueueCsvParser::class)->parseLine($line, $this->separator)
                );

                $validator = $this->defineValidator($row);

                if ($validator->fails()) {
                    $this->errorMessages += array_map(fn ($msg) => "Line $i: $msg", $validator->errors()->all());
                    $this->isValidated = false;
                    $this->startLine = $i - 2 >= 0 ? $i - 2 : 0;
                    $this->reloadTableContent(updateColumnOptions: false);

                    return;
                }

                // Check if some warnings should be shown
                $validatorClasses = match ($this->record->type) {
                    UploadQueue::TYPE_ACTIVE_DATASET => $this->columnTypeOptions['active'],
                    UploadQueue::TYPE_PASSIVE_DATASET => $this->columnTypeOptions['passive'],
                    default => []
                };

                foreach ($validatorClasses as $validatorClass) {
                    if (! isset($row[$validatorClass::$key]) || isset($this->warningMessages[$validatorClass::$key])) {
                        continue;
                    }

                    if (method_exists($validatorClass, 'isOutOfLimits') &&
                        (new $validatorClass)->isOutOfLimits($row[$validatorClass::$key], $this->record->dataset->method)) {
                        $this->warningMessages[$validatorClass::$key] = 'Some values for column '.$validatorClass::$label.' are out of method\'s limits.';
                    }
                }
            }

            fclose($stream);

            $this->isValidated = true;
        } else {
            $this->errorMessages[] = 'Uploaded file not found on remote server.';
            $this->isValidated = false;
        }
    }

    public function defineValidator(array $data): \Illuminate\Validation\Validator
    {
        $validatorClasses = match ($this->record->type) {
            UploadQueue::TYPE_ACTIVE_DATASET => $this->columnTypeOptions['active'],
            UploadQueue::TYPE_PASSIVE_DATASET => $this->columnTypeOptions['passive'],
            default => null
        };

        if (! $validatorClasses) {
            throw new Exception('Validators not implemented for type '.$this->record->enumType($this->record->type));
        }

        return Validator::make($data, array_combine(
            array_map(fn ($validatorClass) => $validatorClass::$key, $validatorClasses),
            array_map(fn ($validatorClass) => new $validatorClass, $validatorClasses)
        ));
    }

    public function save()
    {
        $this->record->config = $this->record->config->withConfiguration(
            $this->separator,
            $this->skipFirstRow,
            $this->columnMapping,
        );
        $this->record->state = UploadQueue::STATE_CONFIGURED;
        $this->record->save();

        Notification::make()
            ->title('Settings saved')
            ->body('Please, close the config form.')
            ->success()
            ->send();
    }
}
