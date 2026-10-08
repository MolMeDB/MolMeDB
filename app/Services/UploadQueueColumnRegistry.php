<?php

namespace App\Services;

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
use App\Rules\UploadFile\ColumnCharge;
use App\Rules\UploadFile\ColumnComment;
use App\Rules\UploadFile\ColumnLogP;
use App\Rules\UploadFile\ColumnPh;
use App\Rules\UploadFile\ColumnPrimaryReference;
// use App\Rules\UploadFile\ColumnSecondaryReference;
use App\Rules\UploadFile\ColumnTemperature;
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
use RuntimeException;

class UploadQueueColumnRegistry
{
    /**
     * @return array<int, class-string>
     */
    public function validatorClasses(UploadQueue|int $record): array
    {
        $type = $record instanceof UploadQueue ? $record->type : $record;

        return match ($type) {
            UploadQueue::TYPE_ACTIVE_DATASET => [
                ColumnName::class,
                ColumnSmiles::class,
                // ColumnUniprot::class,
                ColumnPubchem::class,
                ColumnPdb::class,
                ColumnChembl::class,
                ColumnChebi::class,
                ColumnDrugbank::class,
                ColumnTemperature::class,
                ColumnCharge::class,
                ColumnPh::class,
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
            UploadQueue::TYPE_PASSIVE_DATASET => [
                ColumnName::class,
                ColumnSmiles::class,
                ColumnPubchem::class,
                ColumnPdb::class,
                ColumnChembl::class,
                ColumnChebi::class,
                ColumnDrugbank::class,
                ColumnTemperature::class,
                ColumnCharge::class,
                ColumnPh::class,
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
            default => throw new RuntimeException('Unsupported upload queue type: '.$type),
        };
    }

    /**
     * @return array<string, string>
     */
    public function commonValueColumns(): array
    {
        return $this->databaseColumns([
            ColumnTemperature::class,
            ColumnCharge::class,
            ColumnPh::class,
            ColumnComment::class,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function interactionValueColumns(UploadQueue|int $record): array
    {
        $type = $record instanceof UploadQueue ? $record->type : $record;

        return match ($type) {
            UploadQueue::TYPE_ACTIVE_DATASET => $this->databaseColumns([
                ColumnKm::class,
                ColumnKmAcc::class,
                ColumnEc50::class,
                ColumnEc50Acc::class,
                ColumnKi::class,
                ColumnKiAcc::class,
                ColumnIc50::class,
                ColumnIc50Acc::class,
            ]),
            UploadQueue::TYPE_PASSIVE_DATASET => $this->databaseColumns([
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
            ]),
            default => throw new RuntimeException('Unsupported upload queue type: '.$type),
        };
    }

    /**
     * @param  array<int, class-string>  $classes
     * @return array<string, string>
     */
    private function databaseColumns(array $classes): array
    {
        $columns = [];

        foreach ($classes as $className) {
            $columns[$className::$key] = property_exists($className, 'databaseColumn')
                ? $className::$databaseColumn
                : $className::$key;
        }

        return $columns;
    }
}
