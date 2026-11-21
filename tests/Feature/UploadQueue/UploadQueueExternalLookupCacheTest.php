<?php

require_once __DIR__.'/upload_pipeline_helpers.php';

use App\Services\External\Chemical\Unichem\Unichem;
use App\Services\UploadQueueExternalLookupCache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    fakeUploadChemistryServices(invalidSmiles: ['not-a-smiles'], knownUnichemIds: ['2244', 'CHEMBL25']);
});

test('SMILES that differ only in letter case are canonicalized separately', function () {
    $cache = app(UploadQueueExternalLookupCache::class);

    // Aromatic benzene and aliphatic cyclohexane: a case-insensitive cache key
    // would hand the second molecule the first one's structure.
    expect($cache->canonicalSmiles('c1ccccc1'))->toBe('canon:c1ccccc1')
        ->and($cache->canonicalSmiles('C1CCCCC1'))->toBe('canon:C1CCCCC1');
});

test('a canonical SMILES is looked up once and then served from the cache', function () {
    $cache = app(UploadQueueExternalLookupCache::class);

    $cache->canonicalSmiles('CCO');
    $cache->canonicalSmiles(' CCO ');

    Http::assertSentCount(2); // the RDKit reachability test and one canonicalization
});

test('invalid or empty SMILES have no canonical form', function (string $smiles) {
    expect(app(UploadQueueExternalLookupCache::class)->canonicalSmiles($smiles))->toBeNull();
})->with([
    'rejected by RDKit' => 'not-a-smiles',
    'empty' => '   ',
]);

test('UniChem identifiers exist only when UniChem returns a compound with an InChI', function () {
    $cache = app(UploadQueueExternalLookupCache::class);

    expect($cache->unichemIdentifierExists(Unichem::SOURCE_PUBCHEM, '2244'))->toBeTrue()
        ->and($cache->unichemIdentifierExists(Unichem::SOURCE_CHEMBL, 'CHEMBL25'))->toBeTrue()
        ->and($cache->unichemIdentifierExists(Unichem::SOURCE_PUBCHEM, '999999999'))->toBeFalse()
        ->and($cache->unichemIdentifierExists(Unichem::SOURCE_PUBCHEM, ' '))->toBeFalse();
});
