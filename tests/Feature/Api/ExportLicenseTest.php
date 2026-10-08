<?php

require_once __DIR__.'/api_test_helpers.php';

use App\Libraries\Export\ExportLicenseFiles;
use App\Models\File;

test('export archives get a README with license and citation and a CC0 LICENSE', function () {
    $path = tempnam(sys_get_temp_dir(), 'export').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('passive_interactions.csv', 'data');
    ExportLicenseFiles::addTo($zip, 'passive interactions');
    $zip->close();

    $zip->open($path);

    expect($zip->getNameIndex(0))->toBe('passive_interactions.csv')
        ->and($zip->getFromName('README.txt'))
        ->toContain('Contents: passive interactions')
        ->toContain('CC0 1.0 (https://creativecommons.org/publicdomain/zero/1.0/)')
        ->toContain('https://doi.org/10.1093/database/baz078')
        ->toContain('https://doi.org/10.1186/s13321-026-01208-3')
        ->toContain('Contact: molmedb@upol.cz')
        ->and($zip->getFromName('LICENSE.txt'))->toContain('CC0 1.0 Universal');

    $zip->close();
    unlink($path);
});

test('public API downloads link their license and citation', function () {
    $membrane = createApiMembrane();
    createApiExportFile($membrane, File::TYPE_EXPORT_INTERACTIONS_MEMBRANE, 'membrane export contents');

    $link = $this->getJson("/api/v1/membranes/{$membrane->id}/interactions")
        ->assertOk()
        ->headers->get('Link');

    expect($link)
        ->toContain('<https://creativecommons.org/publicdomain/zero/1.0/>; rel="license"')
        ->toContain('<https://doi.org/10.1093/database/baz078>; rel="cite-as"')
        ->toContain('<https://molmedb.upol.cz/api/v1/about>; rel="describedby"');
});

test('direct CSV exports link their license and citation', function () {
    $structure = createApiStructure(['identifier' => 'MM00040']);

    $response = $this->get("/export/structure/{$structure->id}/passiveInteractions");

    $response->assertOk();

    expect($response->headers->get('Link'))->toContain('rel="license"');
});
