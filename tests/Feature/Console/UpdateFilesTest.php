<?php

use App\Models\File;
use App\Models\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File as FileFacade;
use Illuminate\Support\Facades\Storage;
use Modules\PredictionWorkers\Models\PredictionFile;
use Modules\PredictionWorkers\Models\PredictionMembrane;

/*
 * files:remap-old moves the records of files from the old media/files layout
 * (without a hash) to the RDF and upload filesystems, and COSMO membrane
 * files to the predictions database. A file missing in the new storage, or
 * without a target, is skipped.
 */

beforeEach(function () {
    $this->rdfDisk = useLocalRemapDisk(Filesystem::TYPE_RDF_STORAGE, 'rdf');
    $this->uploadDisk = useLocalRemapDisk(Filesystem::TYPE_UPLOAD_STORAGE, 'upload');
    $this->legacyDisk = 'legacy-remap-'.getmypid();
    config()->set("filesystems.disks.{$this->legacyDisk}", ['driver' => 'local', 'root' => sys_get_temp_dir().'/molmedb-remap-test-'.getmypid().'-legacy']);
});

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/molmedb-remap-test-'.getmypid().'-*') ?: [] as $root) {
        FileFacade::deleteDirectory($root);
    }
});

function useLocalRemapDisk(int $type, string $name): string
{
    $diskName = Filesystem::query()->where('type', $type)->firstOrFail()->systemName;
    $root = sys_get_temp_dir().'/molmedb-remap-test-'.getmypid().'-'.$name;

    FileFacade::deleteDirectory($root);
    config()->set("filesystems.disks.{$diskName}", ['driver' => 'local', 'root' => $root]);
    Storage::forgetDisk($diskName);

    return $diskName;
}

/**
 * A file record of the old layout: no hash yet (inserted directly, the model would compute one).
 */
function createLegacyFileRecord(string $path, ?string $hash = null): File
{
    $id = DB::table('files')->insertGetId([
        'type' => File::TYPE_COSMO_MEMBRANE,
        'name' => basename($path),
        'mime' => 'text/plain',
        'path' => $path,
        'storage' => test()->legacyDisk,
        'hash' => $hash,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return File::query()->findOrFail($id);
}

test('RDF and dataset files are remapped to their new filesystems and get a hash', function () {
    Storage::disk($this->rdfDisk)->put('vocabulary.owl', 'owl');
    Storage::disk($this->uploadDisk)->put('Datasets/12/upload.csv', 'csv');
    $rdf = createLegacyFileRecord('media/files/rdf/vocabulary.owl');
    $dataset = createLegacyFileRecord('media/files/datasets/12/upload.csv');

    $this->artisan('files:remap-old')->assertSuccessful();

    expect($rdf->refresh()->only(['path', 'storage', 'hash']))->toBe(['path' => 'vocabulary.owl', 'storage' => $this->rdfDisk, 'hash' => md5('owl')])
        ->and($dataset->refresh()->only(['path', 'storage', 'hash']))->toBe(['path' => 'Datasets/12/upload.csv', 'storage' => $this->uploadDisk, 'hash' => md5('csv')]);
});

test('a file missing in the new storage is skipped', function (string $path, string $newPath) {
    PredictionMembrane::query()->create(['remote_id' => 7, 'name' => 'DOPC', 'abbreviation' => 'DOPC']);
    $file = createLegacyFileRecord($path);

    $this->artisan('files:remap-old')
        ->expectsOutputToContain($newPath.' does not exist in new storage')
        ->assertSuccessful();

    expect($file->refresh()->only(['path', 'storage', 'hash']))->toBe(['path' => $path, 'storage' => $this->legacyDisk, 'hash' => null])
        ->and(PredictionFile::query()->count())->toBe(0);
})->with([
    'rdf' => ['media/files/rdf/vocabulary.owl', 'vocabulary.owl'],
    'dataset' => ['media/files/datasets/12/upload.csv', 'Datasets/12/upload.csv'],
    'membrane' => ['media/files/membranes/7/cosmo/membrane.mic', 'Membranes/7/cosmo/membrane.mic'],
]);

test('a COSMO membrane file moves to the predictions database', function () {
    PredictionMembrane::query()->create(['remote_id' => 7, 'name' => 'DOPC', 'abbreviation' => 'DOPC']);
    Storage::disk($this->uploadDisk)->put('Membranes/7/cosmo/membrane.mic', 'mic');
    $file = createLegacyFileRecord('media/files/membranes/7/cosmo/membrane.mic');

    $this->artisan('files:remap-old')->assertSuccessful();

    expect(File::withTrashed()->find($file->id))->toBeNull()
        ->and(PredictionFile::query()->sole()->only(['type', 'name', 'mime', 'storage', 'path', 'hash']))->toBe([
            'type' => PredictionFile::TYPE_MEMBARNE_COSMO,
            'name' => 'membrane.mic',
            'mime' => 'text/plain',
            'storage' => $this->uploadDisk,
            'path' => 'Membranes/7/cosmo/membrane.mic',
            'hash' => md5('mic'),
        ]);
});

test('a COSMO file of an unknown membrane is skipped', function () {
    // Another membrane must not be mistaken for the one in the path.
    PredictionMembrane::query()->create(['remote_id' => 1, 'name' => 'DOPC', 'abbreviation' => 'DOPC']);
    Storage::disk($this->uploadDisk)->put('Membranes/7/cosmo/membrane.mic', 'mic');
    $file = createLegacyFileRecord('media/files/membranes/7/cosmo/membrane.mic');

    $this->artisan('files:remap-old')
        ->expectsOutput('No membrane for media/files/membranes/7/cosmo/membrane.mic. Skipping.')
        ->assertSuccessful();

    expect(File::query()->find($file->id))->not->toBeNull()
        ->and(PredictionFile::query()->count())->toBe(0);
});

test('a file without a target filesystem is skipped and the others are still remapped', function () {
    $unknown = createLegacyFileRecord('media/files/other/readme.txt');
    Storage::disk($this->rdfDisk)->put('vocabulary.owl', 'owl');
    $rdf = createLegacyFileRecord('media/files/rdf/vocabulary.owl');

    $this->artisan('files:remap-old')
        ->expectsOutput('No filesystem for media/files/other/readme.txt')
        ->assertSuccessful();

    expect($unknown->refresh()->path)->toBe('media/files/other/readme.txt')
        ->and($rdf->refresh()->path)->toBe('vocabulary.owl');
});

test('files that already have a hash are left alone', function () {
    Storage::disk($this->rdfDisk)->put('vocabulary.owl', 'owl');
    $file = createLegacyFileRecord('media/files/rdf/vocabulary.owl', md5('old'));

    $this->artisan('files:remap-old')->assertSuccessful();

    expect($file->refresh()->path)->toBe('media/files/rdf/vocabulary.owl');
});
