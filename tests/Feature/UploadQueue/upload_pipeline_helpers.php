<?php

require_once __DIR__.'/../Api/api_test_helpers.php';

use App\Models\Dataset;
use App\Models\File;
use App\Models\UploadQueue;
use App\Models\User;
use App\ValueObjects\UploadQueueConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Fakes the chemistry services the upload pipeline calls. RDKit returns the
 * submitted SMILES prefixed with "canon:" as its canonical form, so tests can
 * tell which input a stored structure came from ($canonicalPrefix changes
 * the prefix; an empty one stores SMILES a real database can index); SMILES
 * listed in $invalidSmiles are rejected. UniChem knows the identifiers in
 * $knownUnichemIds, and UniChem's reachability follows $unichemReachable.
 * UniProt knows every accession it is asked for. A SMILES in $canonicalForms
 * gets the form given there instead (two notations of one molecule, say).
 *
 * @param  array<int, string>  $invalidSmiles
 * @param  array<int, string>  $knownUnichemIds
 * @param  array<string, string>  $canonicalForms
 */
function fakeUploadChemistryServices(
    array $invalidSmiles = [],
    array $knownUnichemIds = [],
    bool $unichemReachable = true,
    string $canonicalPrefix = 'canon:',
    array $canonicalForms = [],
): void {
    // The fake reads its settings from config at request time, so calling this
    // again within a test changes them (another Http::fake() callback would
    // never be reached, the first registered one answers).
    config()->set('testing.upload_chemistry', [
        'invalid_smiles' => $invalidSmiles,
        'known_unichem_ids' => $knownUnichemIds,
        'unichem_reachable' => $unichemReachable,
        'canonical_prefix' => $canonicalPrefix,
        'canonical_forms' => $canonicalForms,
    ]);

    if (config('testing.upload_chemistry_faked')) {
        return;
    }

    config()->set('testing.upload_chemistry_faked', true);
    config()->set('services.rdkit.url', 'https://rdkit.test');
    config()->set('services.cdk_depict_url', 'https://cdk-depict.test');
    config()->set('services.unichem.base_api_url', 'https://unichem.test/api');
    config()->set('services.chebi.base_api_url', 'https://chebi.test/api');

    resetApiRouteRdkitState();
    resetApiRouteCdkDepictState();

    Http::fake(function (Request $request) {
        [
            'invalid_smiles' => $invalidSmiles,
            'known_unichem_ids' => $knownUnichemIds,
            'unichem_reachable' => $unichemReachable,
            'canonical_prefix' => $canonicalPrefix,
            'canonical_forms' => $canonicalForms,
        ] = config('testing.upload_chemistry');
        $url = $request->url();

        if (str_starts_with($url, 'https://rdkit.test/structure/canonize')) {
            $smiles = (string) ($request->data()['smi'] ?? '');

            return in_array($smiles, $invalidSmiles, true)
                ? Http::response(['data' => null], 200)
                : Http::response(['data' => $canonicalForms[$smiles] ?? $canonicalPrefix.$smiles], 200);
        }

        if (str_starts_with($url, 'https://unichem.test/api/sources')) {
            return $unichemReachable ? Http::response(['sources' => []], 200) : Http::response([], 503);
        }

        if (str_starts_with($url, 'https://unichem.test/api/compounds')) {
            $compound = (string) ($request->data()['compound'] ?? '');

            return in_array($compound, $knownUnichemIds, true)
                ? Http::response(['compounds' => [['inchi' => ['inchi' => 'InChI=1S/test'], 'standardInchiKey' => 'TESTKEY', 'sources' => []]]], 200)
                : Http::response(['compounds' => []], 200);
        }

        if (str_starts_with($url, 'https://rest.uniprot.org/uniprotkb/')) {
            return Http::response(['primaryAccession' => basename((string) parse_url($url, PHP_URL_PATH))], 200);
        }

        return Http::response([], 200);
    });
}

/**
 * An upload queue record whose CSV is stored on the faked public disk,
 * configured like the frontend configurator leaves it: separator, header row
 * skipped, one column key per CSV column and a passed quick validation.
 *
 * @param  array<int, string|null>  $columns
 * @param  array<string, mixed>  $config
 * @param  array<string, mixed>  $attributes
 */
function createConfiguredUpload(
    string $csv,
    array $columns,
    int $type = UploadQueue::TYPE_PASSIVE_DATASET,
    array $config = [],
    array $attributes = [],
    ?Dataset $dataset = null,
): UploadQueue {
    $owner = User::factory()->create();
    $path = 'upload_queue/'.uniqid('upload_', true).'.csv';
    Storage::disk('public')->put($path, $csv);

    $file = File::query()->create([
        'path' => $path,
        'name' => basename($path),
        'type' => $type === UploadQueue::TYPE_ACTIVE_DATASET ? File::TYPE_UPLOAD_ACTIVE : File::TYPE_UPLOAD_PASSIVE,
        'storage' => 'public',
        'mime' => 'text/csv',
        'hash' => md5($csv.$path),
    ]);

    $dataset ??= createApiDataset([
        'owner' => $owner,
        'type' => $type === UploadQueue::TYPE_ACTIVE_DATASET ? Dataset::TYPE_ACTIVE : Dataset::TYPE_PASSIVE,
    ]);
    $dataset->loadMissing('publications');

    return UploadQueue::query()->create([
        'type' => $type,
        'state' => UploadQueue::STATE_PENDING,
        'file_id' => $file->id,
        'dataset_id' => $dataset->id,
        'user_id' => $owner->id,
        'config' => [
            UploadQueueConfig::SEPARATOR => ',',
            UploadQueueConfig::SKIP_FIRST_ROW => 1,
            UploadQueueConfig::ATTRIBUTES => $columns,
            UploadQueueConfig::QUICK_VALIDATION_OK => true,
            // LabUploadController stores the publication picked in the form here.
            'source_publication_id' => $dataset->publications->first()?->id,
            ...$config,
        ],
        ...$attributes,
    ])->refresh();
}
