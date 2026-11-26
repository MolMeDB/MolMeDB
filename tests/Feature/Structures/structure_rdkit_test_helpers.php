<?php

require_once __DIR__.'/../Api/api_test_helpers.php';

use App\Models\Dataset;
use App\Models\InteractionPassive;
use App\Models\Structure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Answers of the fake RDKit service. A SMILES missing from a map is answered
 * unchanged; a SMILES mapped to null makes that endpoint fail (the batch
 * endpoint answers null for it).
 */
final class FakeRdkitService
{
    public static bool $reachable = true;

    /** Whether the batch endpoint answers (an older service has none) */
    public static bool $batchAvailable = true;

    /** @var array<string, string|null> */
    public static array $canonical = [];

    /** @var array<string, string|null> */
    public static array $representants = [];

    /** @var array<string, array<string, mixed>|null> */
    public static array $info = [];

    public static ?Dataset $dataset = null;
}

/**
 * Points the RDKit module at one fake service that answers from
 * FakeRdkitService, so a test changes the answers without re-faking.
 */
function fakeRdkitService(): void
{
    FakeRdkitService::$reachable = true;
    FakeRdkitService::$batchAvailable = true;
    FakeRdkitService::$canonical = [];
    FakeRdkitService::$representants = [];
    FakeRdkitService::$info = [];
    FakeRdkitService::$dataset = null;

    config()->set('services.rdkit.url', 'https://rdkit.test');
    resetApiRouteRdkitState();

    Http::fake(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $smiles = $query['smi'] ?? '';

        $answer = fn (array $map) => array_key_exists($smiles, $map) ? $map[$smiles] : $smiles;

        $data = match ($path) {
            '/test' => FakeRdkitService::$reachable ? [] : null,
            '/structure/canonize' => $answer(FakeRdkitService::$canonical),
            '/structure/canonize/batch' => ! FakeRdkitService::$batchAvailable ? null : ['canonical_smiles' => array_map(
                fn (string $smiles) => array_key_exists($smiles, FakeRdkitService::$canonical) ? FakeRdkitService::$canonical[$smiles] : $smiles,
                $request->data()['smiles'] ?? [],
            )],
            '/structure/representant' => $answer(FakeRdkitService::$representants),
            '/structure/info' => FakeRdkitService::$info[$smiles] ?? null,
            default => null,
        };

        return $data === null
            ? Http::response(['error' => 'failed'], 500)
            : Http::response(['data' => $data], 200);
    });
}

/**
 * A structure with a passive interaction, so it counts as having data.
 *
 * @param  array<string, mixed>  $attributes
 */
function createStructureWithData(array $attributes = []): Structure
{
    $structure = createApiStructure($attributes);
    createStructureInteraction($structure);

    return $structure;
}

function createStructureInteraction(Structure $structure): InteractionPassive
{
    FakeRdkitService::$dataset ??= createApiDataset(['type' => Dataset::TYPE_PASSIVE]);

    return createApiPassiveInteraction([
        'structure' => $structure,
        'dataset' => FakeRdkitService::$dataset,
        'publication' => FakeRdkitService::$dataset->publications()->first(),
    ]);
}
