<?php

use App\Models\File;
use App\Models\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const VOCABULARY_SOURCE = 'https://vocabulary.test/vocabulary.owl';

function rdfVocabulary(string $namespace = 'https://rdf.molmedb.upol.cz/vocabulary#'): string
{
    return <<<XML
        <?xml version="1.0"?>
        <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:owl="http://www.w3.org/2002/07/owl#">
            <owl:Ontology rdf:about="https://rdf.molmedb.upol.cz/vocabulary.owl"/>
            <owl:Class rdf:about="{$namespace}LogK"/>
            <owl:Class rdf:about="{$namespace}LogPerm"/>
        </rdf:RDF>
        XML;
}

beforeEach(function () {
    config(['fair.rdf.vocabulary_source' => VOCABULARY_SOURCE]);

    $this->rdfDisk = Filesystem::where('type', Filesystem::TYPE_RDF_STORAGE)->firstOrFail()->systemName;
    config()->set("filesystems.disks.{$this->rdfDisk}", ['driver' => 'local']);
    Storage::fake($this->rdfDisk);
});

test('a new vocabulary version is stored in the RDF storage and served', function () {
    Http::fake([VOCABULARY_SOURCE => Http::response(rdfVocabulary())]);
    Cache::put('rdf:vocabulary', 'old version');

    $this->artisan('rdf:update-vocabulary')
        ->expectsOutputToContain('valid: 2 terms')
        ->assertSuccessful();

    $file = File::query()->where('type', File::TYPE_RDF_VOCABULARY)->latest('id')->firstOrFail();

    expect($file->storage)->toBe($this->rdfDisk)
        ->and($file->hash)->toBe(md5(rdfVocabulary()))
        ->and(Storage::disk($this->rdfDisk)->get($file->path))->toBe(rdfVocabulary())
        ->and(Cache::has('rdf:vocabulary'))->toBeFalse();

    $this->get('/api/rdf/vocabulary')->assertOk()->assertContent(rdfVocabulary());
    $this->get('/api/rdf/vocabulary.owl')->assertOk()->assertContent(rdfVocabulary());
});

test('the same version is not stored twice', function () {
    Http::fake([VOCABULARY_SOURCE => Http::response(rdfVocabulary())]);

    $this->artisan('rdf:update-vocabulary')->assertSuccessful();
    $this->artisan('rdf:update-vocabulary')
        ->expectsOutputToContain('already this version')
        ->assertSuccessful();

    expect(File::query()->where('type', File::TYPE_RDF_VOCABULARY)->count())->toBe(1);
});

test('a dry run stores nothing', function () {
    Http::fake([VOCABULARY_SOURCE => Http::response(rdfVocabulary())]);

    $this->artisan('rdf:update-vocabulary', ['--dry-run' => true])->assertSuccessful();

    expect(File::query()->where('type', File::TYPE_RDF_VOCABULARY)->exists())->toBeFalse();
});

test('a vocabulary that does not match the published RDF is rejected', function (string $body, string $error) {
    Http::fake([VOCABULARY_SOURCE => Http::response($body)]);

    $this->artisan('rdf:update-vocabulary')
        ->expectsOutputToContain($error)
        ->assertFailed();

    expect(File::query()->where('type', File::TYPE_RDF_VOCABULARY)->exists())->toBeFalse();
})->with([
    'old http namespace' => [rdfVocabulary('http://rdf.molmedb.upol.cz/vocabulary#'), 'old http namespace'],
    'not XML' => ['<html>404', 'not well-formed XML'],
    'no ontology' => ['<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"/>', 'owl:Ontology'],
]);

test('a local file can be imported', function () {
    $path = tempnam(sys_get_temp_dir(), 'vocabulary');
    file_put_contents($path, rdfVocabulary());

    $this->artisan('rdf:update-vocabulary', ['source' => $path])->assertSuccessful();

    expect(File::query()->where('type', File::TYPE_RDF_VOCABULARY)->value('comment'))->toBe("Imported from {$path}");

    unlink($path);
});
