<?php

namespace App\Support\JsonLd;

use App\Models\Structure;
use App\Support\PublicApiUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Whole-database Bioschemas "Dataset" descriptor
 * (https://bioschemas.org/profiles/Dataset/1.0-RELEASE). Served from the /about
 * endpoint for `Accept: application/ld+json` and embedded in the HTML of the
 * public home page, where crawlers and registries (Google Dataset Search,
 * FAIRsharing, re3data, F-UJI) look for it.
 */
class DatasetJsonLdMapper
{
    public const PROFILE = 'https://bioschemas.org/profiles/Dataset/1.0-RELEASE';

    public function map(): array
    {
        return array_filter([
            '@context' => JsonLdContext::SCHEMA_ORG_WITH_DCT,
            '@type' => 'Dataset',
            '@id' => config('fair.frontend_url'),
            'dct:conformsTo' => ['@id' => self::PROFILE],
            'name' => config('fair.dataset_name'),
            'description' => config('fair.dataset_description'),
            'url' => config('fair.frontend_url'),
            'identifier' => config('fair.frontend_url'),
            'keywords' => config('fair.keywords'),
            'license' => config('fair.data_license.url'),
            'isAccessibleForFree' => true,
            'version' => config('fair.version'),
            'dateModified' => $this->dateModified(),
            'creator' => JsonLdContext::authors(),
            'publisher' => JsonLdContext::publisher(),
            'provider' => JsonLdContext::publisher(),
            'funding' => $this->funding(),
            'citation' => $this->citations(),
            'distribution' => $this->distributions(),
            'codeRepository' => config('fair.repository_url'),
        ], fn ($value) => $value !== null && $value !== []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function funding(): array
    {
        return array_map(fn (array $grant): array => array_filter([
            '@type' => 'Grant',
            'identifier' => $grant['identifier'],
            'name' => isset($grant['name']) ? "{$grant['name']} ({$grant['identifier']})" : $grant['identifier'],
            'funder' => ['@type' => 'Organization', 'name' => $grant['funder']],
        ]), config('fair.funding', []));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function citations(): array
    {
        $citations = [[
            '@type' => 'ScholarlyArticle',
            '@id' => config('fair.citation.url'),
            'name' => config('fair.dataset_name'),
            'citation' => config('fair.citation.text'),
        ]];

        foreach (config('fair.related_publications', []) as $publication) {
            $citations[] = [
                '@type' => 'ScholarlyArticle',
                '@id' => $publication['url'],
                'name' => $publication['title'],
                'isPartOf' => ['@type' => 'Periodical', 'name' => $publication['journal']],
                'datePublished' => (string) $publication['year'],
            ];
        }

        return $citations;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function distributions(): array
    {
        return [
            [
                '@type' => 'DataDownload',
                'name' => 'MolMeDB REST API',
                'contentUrl' => PublicApiUrl::base(),
                'encodingFormat' => ['application/json', 'application/ld+json'],
                'description' => 'Open REST API, documented at '.PublicApiUrl::to('docs').' (OpenAPI: '.PublicApiUrl::to('openapi.json').').',
            ],
            [
                '@type' => 'DataDownload',
                'name' => 'MolMeDB RDF dump',
                'identifier' => config('fair.rdf.dump_url'),
                'contentUrl' => config('fair.rdf.dump_url'),
                'encodingFormat' => ['text/turtle', 'application/n-triples', 'application/rdf+xml'],
            ],
            [
                '@type' => 'DataDownload',
                'name' => 'MolMeDB RDF SPARQL endpoint',
                'contentUrl' => config('fair.rdf.sparql_endpoint'),
                'encodingFormat' => 'application/sparql-results+json',
            ],
            [
                '@type' => 'DataDownload',
                'name' => 'MolMeDB data exports',
                'contentUrl' => config('fair.frontend_url').'/downloader',
                'encodingFormat' => 'text/csv',
            ],
        ];
    }

    private function dateModified(): ?string
    {
        return Cache::remember('fair:dataset-date-modified', now()->addDay(), function (): ?string {
            $modified = Structure::query()->max('updated_at');

            return $modified ? Carbon::parse($modified)->toDateString() : null;
        });
    }
}
