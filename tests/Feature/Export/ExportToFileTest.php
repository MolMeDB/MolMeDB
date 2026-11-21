<?php

require_once __DIR__.'/../Api/api_test_helpers.php';

use App\Console\Commands\UpdateExportFiles;
use App\Libraries\Export\ExportFileHeader;
use App\Libraries\Export\ExportToFile;
use App\Models\Dataset;
use App\Models\File;
use App\Models\Filesystem;
use App\Models\Identifier;
use App\Models\Membrane;
use Modules\References\EuropePMC\Enums\Sources;

/*
 * ExportToFile writes the CSV files of the daily membrane/method/publication
 * exports and of the downloader into the exports filesystem and packs them,
 * with the licence, into a ZIP.
 */

beforeEach(function () {
    $this->exportsFilesystem = Filesystem::query()->where('type', Filesystem::TYPE_EXPORTS)->firstOrFail();
    config()->set("filesystems.disks.{$this->exportsFilesystem->systemName}", ['driver' => 'local']);
    Storage::fake($this->exportsFilesystem->systemName);
    $this->exportsDisk = Storage::disk($this->exportsFilesystem->systemName);
});

function makeExport(?string $filename = 'export', string $folder = '7'): ExportToFile
{
    return new ExportToFile(ExportToFile::CONTEXT_MEMBRANE, $filename, $folder, ExportToFile::TYPE_CSV, test()->exportsFilesystem);
}

function smallExportHeader(): ExportFileHeader
{
    return ExportFileHeader::make()->structure()->passiveInteraction();
}

/**
 * @return array<string, string> file name => contents, in archive order
 */
function readZipEntries(string $path): array
{
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $entries = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $entries[$zip->getNameIndex($index)] = (string) $zip->getFromIndex($index);
    }

    $zip->close();

    return $entries;
}

function writeFinishedExport(ExportToFile $export, string $note = 'row'): ExportToFile
{
    return $export->setHeader(smallExportHeader())
        ->writeHeader()
        ->writeRow(['identifier' => 'MM00001', 'note' => $note])
        ->closeFile();
}

test('an export without a file name is named after today and stored under its context and folder', function () {
    $today = date('Y-m-d');

    $export = new ExportToFile(ExportToFile::CONTEXT_METHOD, null, '/12/', ExportToFile::TYPE_CSV, $this->exportsFilesystem);

    expect($export->filename)->toBe($today)
        ->and($export->getTargetFolder())->toBe('method/12')
        ->and($export->getFileName())->toBe("method/12/{$today}.csv")
        ->and($export->getZipFilePath())->toBe("method/12/{$today}.zip")
        ->and($export->getRelativeZipFilePath())->toBe("method/12/{$today}.zip");
});

test('the header is written once and rows of every kind follow it in its column order', function () {
    $export = makeExport();
    $header = ExportFileHeader::make()->structure();
    $export->setHeader($header)->writeHeader()->writeHeader();

    $export->writeRow((object) ['identifier' => 'MM00001', 'name' => 'Ethanol', 'logp' => -0.31])
        ->writeRow(createApiStructure(['identifier' => 'MM00002', 'canonical_smiles' => 'CCO']))
        ->writeRow(['raw', 'array'])
        ->writeRow('a pre-formatted line')
        ->closeFile();

    $lines = explode(PHP_EOL, trim($this->exportsDisk->get('membrane/7/export.csv')));

    expect($lines)->toHaveCount(5)
        ->and($lines[0])->toBe(implode(';', $header->as_array()))
        ->and(str_getcsv($lines[1], ';', '"', '\\'))->toBe(['MM00001', 'Ethanol', '', '', '', '-0.31', '', '', '', '', ''])
        ->and(str_getcsv($lines[2], ';', '"', '\\')[0])->toBe('MM00002')
        ->and(str_getcsv($lines[2], ';', '"', '\\')[2])->toBe('CCO')
        ->and($lines[3])->toBe('raw;array')
        ->and($lines[4])->toBe('a pre-formatted line');
});

test('row values a spreadsheet would run as a formula are neutralised, also with another separator', function () {
    $export = makeExport();
    $export->setHeader(ExportFileHeader::make()->structure())->writeHeader();

    $export->writeRow((object) ['identifier' => 'MM00001', 'name' => '=1+2'], ',')
        ->writeRow(['@SUM(A1)', '-3.5'], ',')
        ->closeFile();

    $lines = explode(PHP_EOL, trim($this->exportsDisk->get('membrane/7/export.csv')));

    expect($lines[1])->toStartWith("MM00001,'=1+2,")
        ->and($lines[2])->toBe("'@SUM(A1),-3.5");
});

test('an object row cannot be written before the header', function (bool $withHeader) {
    $export = makeExport();

    if ($withHeader) {
        $export->setHeader(smallExportHeader());
    }

    $export->writeRow((object) ['identifier' => 'MM00001']);
})->with(['no header' => false, 'header set but not written' => true])
    ->throws(Exception::class, 'Header is not written to the target file.');

test('without a header nothing is written and no file is created', function () {
    expect(makeExport()->writeHeader())->toBeFalse();

    $this->exportsDisk->assertMissing('membrane/7/export.csv');
});

test('an export with rows is zipped with the licence and its working CSV can be removed', function () {
    $export = writeFinishedExport(makeExport(), 'measured');

    expect($export->zip())->toBe($export);

    $entries = readZipEntries($this->exportsDisk->path('membrane/7/export.zip'));

    expect(array_keys($entries))->toBe(['export.csv', 'README.txt', 'LICENSE.txt'])
        ->and($entries['export.csv'])->toBe($this->exportsDisk->get('membrane/7/export.csv'))
        ->and($entries['export.csv'])->toContain('measured')
        ->and($entries['README.txt'])->toContain('export.csv (semicolon separated)');

    expect($export->deleteFile())->toBe($export);

    $this->exportsDisk->assertMissing('membrane/7/export.csv');
    $this->exportsDisk->assertExists('membrane/7/export.zip');
});

test('an export without data rows is not zipped and its CSV is removed', function (Closure $write) {
    $export = makeExport();
    $write($export);
    $export->closeFile();

    expect($export->zip())->toBeNull();

    $this->exportsDisk->assertMissing('membrane/7/export.csv');
    $this->exportsDisk->assertMissing('membrane/7/export.zip');
})->with([
    'empty file' => fn (ExportToFile $export) => $export->initFile(),
    'header only' => fn (ExportToFile $export) => $export->setHeader(smallExportHeader())->writeHeader(),
    'blank line after header' => fn (ExportToFile $export) => $export->setHeader(smallExportHeader())->writeHeader()->writeRow(''),
]);

test('an export that was never written is reported as empty with a warning', function () {
    $export = makeExport();

    expect(fn () => $export->checkIfFileIsNonempty())->toThrow(ErrorException::class, 'File is not initialized or does not exist.');
});

test('closing and deleting an export that was never written does nothing', function () {
    $export = makeExport();

    expect($export->closeFile()->closeFile())->toBe($export)
        ->and($export->deleteFile())->toBe($export);
});

test('a new dump replaces the previous one when the data did not change', function () {
    $previous = writeFinishedExport(makeExport('2026-11-20'), 'same data')->zip();
    $older = writeFinishedExport(makeExport('2026-11-19'), 'same data')->zip();

    $current = writeFinishedExport(makeExport('2026-11-21'), 'same data')->zip();

    expect($current->keepLastChanged())->toBe($current);

    $this->exportsDisk->assertExists('membrane/7/2026-11-21.zip');
    $this->exportsDisk->assertMissing('membrane/7/2026-11-20.zip');
    // Only the latest previous dump is compared.
    $this->exportsDisk->assertExists('membrane/7/2026-11-19.zip');
});

test('a new dump keeps the previous one when the data changed', function () {
    writeFinishedExport(makeExport('2026-11-20'), 'old data')->zip();

    writeFinishedExport(makeExport('2026-11-21'), 'new data')->zip()->keepLastChanged();

    $this->exportsDisk->assertExists('membrane/7/2026-11-20.zip');
    $this->exportsDisk->assertExists('membrane/7/2026-11-21.zip');
});

test('keeping the last changed dump does nothing until the new dump is zipped', function () {
    writeFinishedExport(makeExport('2026-11-20'), 'same data')->zip();

    writeFinishedExport(makeExport('2026-11-21'), 'same data')->keepLastChanged();

    $this->exportsDisk->assertExists('membrane/7/2026-11-20.zip');
});

test('the active interactions of a structure are read with its preferred identifiers and both references', function () {
    $structure = createApiStructure(['identifier' => 'MM03000']);
    $datasetPublication = createApiPublication(['citation' => 'Dataset ref', 'doi' => '10.1/ds', 'identifier' => '111', 'identifier_source' => Sources::MED->value]);
    $primary = createApiPublication(['citation' => 'Primary ref', 'doi' => '10.1/pr', 'identifier' => 'PMC9', 'identifier_source' => Sources::PMC->value]);
    $interaction = createApiActiveInteraction([
        'structure' => $structure,
        'publication' => $primary,
        'protein' => createApiProtein(['uniprot_id' => 'Q99999']),
        'dataset' => createApiDataset(['type' => Dataset::TYPE_ACTIVE, 'publication' => $datasetPublication]),
    ]);
    createApiActiveInteraction();

    foreach ([
        [Identifier::TYPE_NAME, 'Invalid name', Identifier::STATE_INVALID],
        [Identifier::TYPE_NAME, 'New name', Identifier::STATE_NEW],
        [Identifier::TYPE_NAME, 'Validated name', Identifier::STATE_VALIDATED],
        [Identifier::TYPE_PUBCHEM, '702', Identifier::STATE_NEW],
        [Identifier::TYPE_PDB, 'EOH', Identifier::STATE_NEW],
        [Identifier::TYPE_DRUGBANK, 'DB00898', Identifier::STATE_NEW],
    ] as [$type, $value, $state]) {
        Identifier::create(['structure_id' => $structure->id, 'type' => $type, 'value' => $value, 'state' => $state]);
    }

    $rows = ExportToFile::structureActiveInteractionsQuery($structure)->get();

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect($row->id)->toBe($interaction->id)
        ->and($row->protein)->toBe('Q99999')
        ->and($row->identifier)->toBe('MM03000')
        ->and($row->name)->toBe('Validated name')
        ->and($row->pubchem)->toBe('702')
        ->and($row->pdb)->toBe('EOH')
        ->and($row->drugbank)->toBe('DB00898')
        ->and($row->primary_citation)->toBe('Primary ref')
        ->and($row->primary_doi)->toBe('10.1/pr')
        ->and($row->primary_pubmed_id)->toBeNull()
        ->and($row->secondary_citation)->toBe('Dataset ref')
        ->and($row->secondary_pubmed_id)->toBe('111');
});

test('the daily export writes the membrane CSV through the export file and skips membranes without data', function () {
    $structure = createApiStructure(['identifier' => 'MM03001']);
    $interaction = createApiPassiveInteraction(['structure' => $structure, 'note' => '=cmd|calc', 'gpen' => -2.5]);
    $membrane = $interaction->dataset->membrane;
    $empty = createApiMembrane(['name' => 'Empty bilayer', 'abbreviation' => 'EMPTY']);

    $this->artisan(UpdateExportFiles::class, ['--force' => true])->assertSuccessful();

    $export = $membrane->files()->where('type', File::TYPE_EXPORT_INTERACTIONS_MEMBRANE)->sole();
    $date = now()->format('Y-m-d');

    expect($export->path)->toBe("membrane/{$membrane->id}/{$date}.zip");

    $entries = readZipEntries($this->exportsDisk->path($export->path));
    $lines = explode("\n", trim($entries["{$date}.csv"]));

    expect($lines)->toHaveCount(2)
        ->and(str_getcsv($lines[0], ';', '"', '\\'))->toBe(smallExportHeader()->as_array())
        ->and($lines[0])->not->toContain("'+/-")
        ->and($lines[1])->toStartWith('MM03001;')
        ->and($lines[1])->toContain(";'=cmd|calc;")
        ->and($lines[1])->toContain(';-2.5;');

    $this->exportsDisk->assertMissing("membrane/{$membrane->id}/{$date}.csv");
    $this->exportsDisk->assertMissing("membrane/{$empty->id}/{$date}.csv");
    $this->exportsDisk->assertMissing("membrane/{$empty->id}/{$date}.zip");

    expect(Membrane::query()->find($empty->id)->files()->count())->toBe(0);
});
