<?php

namespace App\Http\Controllers\Api\Public\V1;

use App\Http\Controllers\Controller;
use App\Support\JsonLd\DatasetJsonLdMapper;
use App\Support\PublicApiUrl;
use Illuminate\Http\Request;

/**
 * Machine-readable description of MolMeDB as a dataset (name, license,
 * citation, contact, identifier scheme) — the FAIR "Findable"/"Reusable"
 * landing point for anyone (human or machine/registry) discovering the API
 * without prior knowledge of it.
 */
class AboutController extends Controller
{
    /**
     * Units and meaning of the measured values of interactions.
     *
     * @var array<string, array<string, array{unit: string, description: string}>>
     */
    public const UNITS = [
        'interactions_passive' => [
            'x_min' => ['unit' => 'nm', 'description' => 'Position of the free energy minimum across the membrane.'],
            'gpen' => ['unit' => 'kcal/mol', 'description' => 'Free energy barrier of membrane penetration.'],
            'gwat' => ['unit' => 'kcal/mol', 'description' => 'Free energy in the minimum relative to water.'],
            'logk' => ['unit' => 'log10(membrane/water)', 'description' => 'Membrane/water partition coefficient.'],
            'logperm' => ['unit' => 'log10(cm/s)', 'description' => 'Membrane permeability coefficient.'],
            'temperature' => ['unit' => '°C', 'description' => 'Temperature of the measurement or calculation.'],
        ],
        'interactions_active' => [
            'km' => ['unit' => '-log10(M)', 'description' => 'pKm, Michaelis constant of transport.'],
            'ec50' => ['unit' => '-log10(M)', 'description' => 'pEC50, half maximal effective concentration.'],
            'ki' => ['unit' => '-log10(M)', 'description' => 'pKi, inhibition constant.'],
            'ic50' => ['unit' => '-log10(M)', 'description' => 'pIC50, half maximal inhibitory concentration.'],
            'temperature' => ['unit' => '°C', 'description' => 'Temperature of the measurement.'],
        ],
    ];

    public function index(Request $request)
    {
        if ($request->attributes->get('response_format') === 'jsonld') {
            return response()->json(app(DatasetJsonLdMapper::class)->map());
        }

        return response()->json([
            'data' => [
                'name' => config('fair.dataset_name'),
                'description' => config('fair.dataset_description'),
                'version' => config('fair.version'),
                'url' => config('fair.frontend_url'),
                'repository' => config('fair.repository_url'),
                'contact' => config('fair.contact_email'),
                'license' => [
                    'data' => config('fair.data_license'),
                    'code' => config('fair.code_license'),
                ],
                'citation' => config('fair.citation'),
                'identifier_scheme' => [
                    'pattern' => config('fair.identifier_pattern'),
                    'example' => config('fair.identifier_example'),
                    'resolver' => rtrim(config('fair.frontend_url'), '/').'/mol/{identifier}',
                    'identifiers_org' => 'https://identifiers.org/'.config('fair.identifiers_org_namespace').'/{identifier}',
                ],
                'rdf' => [
                    'base' => config('fair.rdf.base_url'),
                    'vocabulary' => config('fair.rdf.vocabulary_url'),
                    'sparql_endpoint' => config('fair.rdf.sparql_endpoint'),
                    'dump' => config('fair.rdf.dump_url'),
                    'article' => config('fair.related_publications.0.url'),
                ],
                'units' => self::UNITS,
                'links' => [
                    'openapi' => PublicApiUrl::to('openapi.json'),
                    'mcp' => PublicApiUrl::to('mcp'),
                    'sitemap' => config('fair.frontend_url').'/sitemap.xml',
                ],
            ],
        ]);
    }
}
