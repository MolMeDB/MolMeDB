<?php

use Illuminate\Support\Facades\Storage;
use Modules\PredictionWorkers\DTO\JobResult;
use Modules\PredictionWorkers\DTO\PredictionResultJson;
use Modules\PredictionWorkers\DTO\SoluteResult;
use Modules\PredictionWorkers\Exceptions\InvalidCosmoResultException;
use Modules\PredictionWorkers\Exceptions\UnreadableCosmoResultException;
use Modules\PredictionWorkers\Models\PredictionFile;
use Modules\PredictionWorkers\Services\CosmoXmlParser;

test('cosmo xml parser parses jobs and solutes from valid xml file', function () {
    Storage::fake('predictions-test');

    Storage::disk('predictions-test')->put('results/cosmo.xml', <<<'XML'
<micoutput>
    <joblist>
        <job>
            <jobnumber>1</jobnumber>
            <symmetry>planar</symmetry>
            <nrlayer>3</nrlayer>
            <layerposition>0.0 1.234 2.345</layerposition>
            <temperature>310.15</temperature>
            <solutelist>
                <solute>
                    <meanposition>1.5</meanposition>
                    <logP_micelle_water>2.75</logP_micelle_water>
                    <logPerm_membrane_cm_s>-4.5</logPerm_membrane_cm_s>
                    <distribution>0.5 0.25 0.125</distribution>
                </solute>
            </solutelist>
        </job>
    </joblist>
</micoutput>
XML);

    $file = new PredictionFile([
        'storage' => 'predictions-test',
        'path' => 'results/cosmo.xml',
    ]);

    $result = app(CosmoXmlParser::class)->parse($file);

    expect($result)->toBeInstanceOf(PredictionResultJson::class)
        ->and($result->jobs)->toHaveCount(1)
        ->and($result->jobs[0]->jobNumber)->toBe('1')
        ->and($result->jobs[0]->symmetry)->toBe('planar')
        ->and($result->jobs[0]->layerCount)->toBe(3)
        ->and($result->jobs[0]->layerPositions)->toBe([0.0, 1.2, 2.3])
        ->and($result->jobs[0]->temperature)->toBe(37.0)
        ->and($result->jobs[0]->solutes)->toHaveCount(1)
        ->and($result->jobs[0]->solutes[0]->meanPosition)->toBe(1.5)
        ->and($result->jobs[0]->solutes[0]->logK)->toBe(2.75)
        ->and($result->jobs[0]->solutes[0]->logPerm)->toBe(-4.5)
        ->and($result->jobs[0]->solutes[0]->energyValues)->toBe([-0.85, -0.43, 0.0]);
});

test('cosmo xml parser throws when xml file is missing', function () {
    Storage::fake('predictions-test');

    $file = new PredictionFile([
        'storage' => 'predictions-test',
        'path' => 'missing.xml',
    ]);

    expect(fn () => app(CosmoXmlParser::class)->parse($file))
        ->toThrow(UnreadableCosmoResultException::class, 'COSMO XML file not found on disk [predictions-test] path [missing.xml]');
});

test('cosmo xml parser does not judge a file it read only in part', function (string $read, int $size, string $reason) {
    $disk = Mockery::mock(Illuminate\Contracts\Filesystem\Filesystem::class);
    $disk->shouldReceive('exists')->andReturn(true);
    $disk->shouldReceive('get')->andReturn($read);
    $disk->shouldReceive('size')->andReturn($size);
    Storage::shouldReceive('disk')->with('predictions-test')->andReturn($disk);

    $file = new PredictionFile(['storage' => 'predictions-test', 'path' => 'results/cosmo.xml']);

    expect(fn () => app(CosmoXmlParser::class)->parse($file))->toThrow(UnreadableCosmoResultException::class, $reason);
})->with([
    'nothing read of a file with content' => ['', 2048, 'read only in part (0 of 2048 bytes)'],
    'a part read' => ['<micoutput><joblist>', 2048, 'read only in part (20 of 2048 bytes)'],
]);

test('cosmo xml parser does not judge a file the storage could not read', function () {
    $disk = Mockery::mock(Illuminate\Contracts\Filesystem\Filesystem::class);
    $disk->shouldReceive('exists')->andReturn(true);
    $disk->shouldReceive('get')->andReturn(null);
    Storage::shouldReceive('disk')->with('predictions-test')->andReturn($disk);

    $file = new PredictionFile(['storage' => 'predictions-test', 'path' => 'results/cosmo.xml']);

    expect(fn () => app(CosmoXmlParser::class)->parse($file))
        ->toThrow(UnreadableCosmoResultException::class, 'Unable to read COSMO XML file on disk [predictions-test] path [results/cosmo.xml]');
});

test('cosmo xml parser tells content that is not a result from a file it could not read', function (string $content, string $reason) {
    Storage::fake('predictions-test');
    Storage::disk('predictions-test')->put('results/cosmo.xml', $content);

    $file = new PredictionFile(['storage' => 'predictions-test', 'path' => 'results/cosmo.xml']);

    expect(fn () => app(CosmoXmlParser::class)->parse($file))->toThrow(InvalidCosmoResultException::class, $reason);
})->with([
    // What the remote service leaves behind when its calculation stops half way.
    'cut short' => ["<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n\n<micoutput>\n  <joblist>\n\n", 'not well-formed XML (line '],
    'not XML' => ['Calculation failed.', 'not well-formed XML'],
    'another document' => ['<html><body>Not found</body></html>', 'Expected <micoutput>'],
    'without jobs' => ['<micoutput></micoutput>', 'Missing <joblist>'],
    'empty' => ["  \n", 'The file is empty'],
]);

/**
 * A zip archive with the given files, as its bytes.
 *
 * @param  array<string, string>  $files
 */
function cosmoResultArchive(array $files): string
{
    $path = tempnam(sys_get_temp_dir(), 'cosmo-archive-test-');
    $archive = new ZipArchive;
    $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($files as $name => $content) {
        $archive->addFromString($name, $content);
    }

    $archive->close();
    $bytes = file_get_contents($path);
    unlink($path);

    return $bytes;
}

test('cosmo xml parser reads the result out of a stored archive', function () {
    Storage::fake('predictions-test');
    Storage::disk('predictions-test')->put('results/cosmo.xml', cosmoResultArchive([
        'calculation/job.inp' => 'input',
        'calculation/settings.xml' => '<settings/>',
        'calculation/cosmo.XML' => '<micoutput><joblist><job><jobnumber>7</jobnumber><temperature>298.15</temperature></job></joblist></micoutput>',
    ]));

    $result = app(CosmoXmlParser::class)->parse(new PredictionFile(['storage' => 'predictions-test', 'path' => 'results/cosmo.xml']));

    expect($result->jsonSerialize())->toHaveCount(1)
        ->and($result->jsonSerialize()[0]['temperature'])->toEqualWithDelta(25.0, 0.001);
});

test('cosmo xml parser gives up an archive without a result', function (Closure $content, string $reason) {
    Storage::fake('predictions-test');
    Storage::disk('predictions-test')->put('results/cosmo.xml', $content());

    expect(fn () => app(CosmoXmlParser::class)->parse(new PredictionFile(['storage' => 'predictions-test', 'path' => 'results/cosmo.xml'])))
        ->toThrow(InvalidCosmoResultException::class, $reason);
})->with([
    'no XML inside' => [fn (): string => cosmoResultArchive(['calculation/job.inp' => 'input']), 'holds no COSMO XML file'],
    'another XML inside' => [fn (): string => cosmoResultArchive(['settings.xml' => '<settings/>']), 'holds no COSMO XML file'],
    'an archive that cannot be opened' => [fn (): string => "PK\x03\x04rest", 'holds no COSMO XML file'],
    'a result cut short inside' => [fn (): string => cosmoResultArchive(['cosmo.xml' => "<micoutput>\n  <joblist>\n"]), 'not well-formed XML'],
]);

test('an XML file that would unpack into more than the limit is not unpacked', function () {
    $parser = app(CosmoXmlParser::class);
    $archive = cosmoResultArchive(['cosmo.xml' => '<micoutput>'.str_repeat(' ', CosmoXmlParser::MAX_ARCHIVED_XML_BYTES).'</micoutput>']);

    expect($parser->xmlFromArchive($archive))->toBeNull()
        ->and($parser->xmlFromArchive('<micoutput/>'))->toBeNull();

    Storage::fake('predictions-test');
    Storage::disk('predictions-test')->put('results/cosmo.xml', $archive);

    expect(fn () => $parser->parse(new PredictionFile(['storage' => 'predictions-test', 'path' => 'results/cosmo.xml'])))
        ->toThrow(InvalidCosmoResultException::class, 'is larger than a COSMO result');
});

test('an archive this server cannot unpack is not judged', function () {
    // An encrypted entry: the archive is sound, only it cannot be unpacked here.
    $path = tempnam(sys_get_temp_dir(), 'cosmo-archive-test-');
    $archive = new ZipArchive;
    $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $archive->addFromString('cosmo.xml', '<micoutput><joblist/></micoutput>');
    $archive->setEncryptionName('cosmo.xml', ZipArchive::EM_AES_256, 'secret');
    $archive->close();
    $bytes = file_get_contents($path);
    unlink($path);

    Storage::fake('predictions-test');
    Storage::disk('predictions-test')->put('results/cosmo.xml', $bytes);
    $parser = app(CosmoXmlParser::class);

    expect(fn () => $parser->parse(new PredictionFile(['storage' => 'predictions-test', 'path' => 'results/cosmo.xml'])))
        ->toThrow(UnreadableCosmoResultException::class, 'could not be unpacked from the stored archive, on disk [predictions-test]');

    expect($parser->xmlFromArchive($bytes))->toBeNull();
});

test('cosmo xml parser leaves the XML error reporting of the application as it was', function () {
    libxml_use_internal_errors(false);

    expect(fn () => app(CosmoXmlParser::class)->parseString('<micoutput><joblist>'))->toThrow(InvalidCosmoResultException::class)
        ->and(libxml_use_internal_errors())->toBeFalse()
        ->and(libxml_get_last_error())->toBeFalse();

    // A caller that collects XML errors itself keeps doing so, with its own.
    libxml_use_internal_errors(true);
    simplexml_load_string('<mine>');
    $own = count(libxml_get_errors());

    try {
        expect(fn () => app(CosmoXmlParser::class)->parseString('<micoutput><joblist>'))
            ->toThrow(InvalidCosmoResultException::class, 'Premature end of data in tag joblist')
            ->and(libxml_use_internal_errors())->toBeTrue()
            ->and(count(libxml_get_errors()))->toBeGreaterThanOrEqual($own);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors(false);
    }
});

test('prediction result dto serializes nested jobs and solutes', function () {
    $result = new PredictionResultJson([
        new JobResult(
            jobNumber: '1',
            symmetry: 'planar',
            layerCount: 2,
            layerPositions: [0.0, 1.0],
            temperature: 25.0,
            solutes: [
                new SoluteResult(
                    meanPosition: 0.5,
                    logK: 1.2,
                    logPerm: -3.4,
                    energyValues: [0.3, 0.0],
                ),
            ],
        ),
    ]);

    expect($result->jsonSerialize())->toBe([
        [
            'symmetry' => 'planar',
            'layer_count' => 2,
            'layer_positions' => [0.0, 1.0],
            'temperature' => 25.0,
            'solutes' => [
                [
                    'mean_position' => 0.5,
                    'logK' => 1.2,
                    'logPerm' => -3.4,
                    'energy_values' => [0.3, 0.0],
                ],
            ],
        ],
    ]);
});
