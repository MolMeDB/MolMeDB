<?php

require_once __DIR__.'/upload_pipeline_helpers.php';

use App\Models\UploadQueue;
use App\Rules\UploadFile\ActiveInteractions\ColumnIc50;
use App\Rules\UploadFile\ActiveInteractions\ColumnIc50Acc;
use App\Rules\UploadFile\ActiveInteractions\ColumnProteinName;
use App\Rules\UploadFile\ColumnAccuracy;
use App\Rules\UploadFile\ColumnCharge;
use App\Rules\UploadFile\ColumnComment;
use App\Rules\UploadFile\ColumnLogP;
use App\Rules\UploadFile\ColumnPh;
use App\Rules\UploadFile\ColumnTemperature;
use App\Rules\UploadFile\FastColumnTypeRule;
use App\Rules\UploadFile\Identifiers\ColumnChebi;
use App\Rules\UploadFile\Identifiers\ColumnChembl;
use App\Rules\UploadFile\Identifiers\ColumnDrugbank;
use App\Rules\UploadFile\Identifiers\ColumnName;
use App\Rules\UploadFile\Identifiers\ColumnPdb;
use App\Rules\UploadFile\Identifiers\ColumnPubchem;
use App\Rules\UploadFile\Identifiers\ColumnSmiles;
use App\Rules\UploadFile\PassiveInteractions\ColumnGpen;
use App\Rules\UploadFile\PassiveInteractions\ColumnGpenAcc;
use App\Rules\UploadFile\PassiveInteractions\ColumnGwat;
use App\Rules\UploadFile\PassiveInteractions\ColumnLogK;
use App\Rules\UploadFile\PassiveInteractions\ColumnLogPerm;
use App\Rules\UploadFile\PassiveInteractions\ColumnXmin;
use App\Services\UploadQueueColumnRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    fakeUploadChemistryServices(invalidSmiles: ['not-a-smiles'], knownUnichemIds: ['2244', 'CHEMBL25', '1ABC']);
});

/**
 * @param  array<string, mixed>  $row  Further columns of the same row.
 */
function columnPasses(ValidationRule $rule, string $key, mixed $value, array $row = []): bool
{
    return Validator::make([...$row, $key => $value], [$key => $rule])->passes();
}

test('numeric and range columns accept valid values and reject the rest', function (string $ruleClass, string $value, bool $valid) {
    expect(columnPasses(new $ruleClass, $ruleClass::$key, $value))->toBe($valid);
})->with([
    'temperature' => [ColumnTemperature::class, '25.5', true],
    'negative temperature' => [ColumnTemperature::class, '-1', false],
    'text temperature' => [ColumnTemperature::class, 'warm', false],
    'pH' => [ColumnPh::class, '7.4', true],
    'pH above 14' => [ColumnPh::class, '14.1', false],
    'pH below 0' => [ColumnPh::class, '-0.1', false],
    'charge' => [ColumnCharge::class, '-2', true],
    'fractional charge' => [ColumnCharge::class, '1.5', false],
    'charge above 20' => [ColumnCharge::class, '21', false],
    'logP' => [ColumnLogP::class, '-0.31', true],
    'text logP' => [ColumnLogP::class, 'n/a', false],
    'Gpen' => [ColumnGpen::class, '-12.4', true],
    'text Gpen' => [ColumnGpen::class, 'abc', false],
    'Gwat' => [ColumnGwat::class, '3e-2', true],
    'LogK' => [ColumnLogK::class, '1.2', true],
    'text LogK' => [ColumnLogK::class, 'x', false],
    'LogPerm' => [ColumnLogPerm::class, '-5.1', true],
    'text LogPerm' => [ColumnLogPerm::class, '-', false],
    'Xmin' => [ColumnXmin::class, '1.9', true],
    'IC50' => [ColumnIc50::class, '0.5', true],
    'text IC50' => [ColumnIc50::class, 'high', false],
]);

test('text columns enforce their length limits', function (string $ruleClass, string $value, bool $valid) {
    expect(columnPasses(new $ruleClass, $ruleClass::$key, $value))->toBe($valid);
})->with([
    'comment' => [ColumnComment::class, 'Measured twice.', true],
    'too long comment' => [ColumnComment::class, str_repeat('a', 256), false],
    'name' => [ColumnName::class, 'caffeine', true],
    'too short name' => [ColumnName::class, 'ab', false],
    'too long name' => [ColumnName::class, str_repeat('a', 256), false],
    'protein name' => [ColumnProteinName::class, 'Cytochrome P450 3A4', true],
]);

test('accuracy columns need a non-negative number and the value they belong to', function () {
    expect(columnPasses(new ColumnGpenAcc, ColumnGpenAcc::$key, '0.2', [ColumnGpen::$key => '-12.4']))->toBeTrue()
        ->and(columnPasses(new ColumnGpenAcc, ColumnGpenAcc::$key, '-0.2', [ColumnGpen::$key => '-12.4']))->toBeFalse()
        ->and(columnPasses(new ColumnGpenAcc, ColumnGpenAcc::$key, '0.2'))->toBeFalse()
        ->and(columnPasses(new ColumnIc50Acc, ColumnIc50Acc::$key, '0.1', [ColumnIc50::$key => '0.5']))->toBeTrue();
});

test('every accuracy column accepts a value when the value it belongs to is present', function (int $type) {
    $classes = app(UploadQueueColumnRegistry::class)->validatorClasses($type);
    $keys = array_map(fn (string $class): string => $class::$key, $classes);

    foreach ($classes as $class) {
        if (! is_subclass_of($class, ColumnAccuracy::class)) {
            continue;
        }

        $parentKey = property_exists($class, 'parentKey') ? $class::$parentKey : str_replace('_acc', '', $class::$key);

        expect($keys)->toContain($parentKey)
            ->and(columnPasses(new $class, $class::$key, '0.1', [$parentKey => '1.0']))->toBeTrue("{$class} with {$parentKey}");
    }
})->with([
    'passive' => UploadQueue::TYPE_PASSIVE_DATASET,
    'active' => UploadQueue::TYPE_ACTIVE_DATASET,
]);

test('identifier columns check their format without asking external services', function (string $ruleClass, string $value, bool $valid) {
    expect(columnPasses(new FastColumnTypeRule(new $ruleClass), $ruleClass::$key, $value))->toBe($valid);
})->with([
    'DrugBank' => [ColumnDrugbank::class, 'DB00201', true],
    'DrugBank biologic' => [ColumnDrugbank::class, 'BE0000048', true],
    'short DrugBank' => [ColumnDrugbank::class, 'DB201', false],
    'ChEBI' => [ColumnChebi::class, 'CHEBI:27732', true],
    'ChEBI without prefix' => [ColumnChebi::class, '27732', false],
    'ChEMBL' => [ColumnChembl::class, 'CHEMBL113', true],
    'lowercase ChEMBL' => [ColumnChembl::class, 'chembl113', false],
    'PubChem' => [ColumnPubchem::class, '2519', true],
    'PubChem with letters' => [ColumnPubchem::class, 'CID2519', false],
    'PDB' => [ColumnPdb::class, '1ABC', true],
    'PDB with a dash' => [ColumnPdb::class, '1-ABC', false],
]);

test('external identifier columns must exist in UniChem', function (string $ruleClass, string $value, bool $valid) {
    expect(columnPasses(new $ruleClass, $ruleClass::$key, $value))->toBe($valid);
})->with([
    'known PubChem' => [ColumnPubchem::class, '2244', true],
    'unknown PubChem' => [ColumnPubchem::class, '999999999', false],
    'known ChEMBL' => [ColumnChembl::class, 'CHEMBL25', true],
    'unknown ChEMBL' => [ColumnChembl::class, 'CHEMBL999999', false],
    'known PDB' => [ColumnPdb::class, '1ABC', true],
]);

test('SMILES columns must be canonicalizable by RDKit', function () {
    expect(columnPasses(new ColumnSmiles, ColumnSmiles::$key, 'CCO'))->toBeTrue()
        ->and(columnPasses(new ColumnSmiles, ColumnSmiles::$key, 'not-a-smiles'))->toBeFalse()
        ->and(columnPasses(new ColumnSmiles, ColumnSmiles::$key, str_repeat('C', ColumnSmiles::$maxLength + 1)))->toBeFalse();
});

test('every upload column has a unique key and a label', function (int $type) {
    $classes = app(UploadQueueColumnRegistry::class)->validatorClasses($type);
    $keys = array_map(fn (string $class): string => $class::$key, $classes);

    expect($keys)->toBe(array_values(array_unique($keys)));

    foreach ($classes as $class) {
        expect($class::$label)->toBeString()->not->toBe('');
    }
})->with([
    'passive' => UploadQueue::TYPE_PASSIVE_DATASET,
    'active' => UploadQueue::TYPE_ACTIVE_DATASET,
]);
