<?php

namespace App\Support\JsonLd;

/**
 * Shared JSON-LD building blocks for the MolMeDB mappers.
 */
class JsonLdContext
{
    /**
     * schema.org plus the Dublin Core prefix used by Bioschemas `dct:conformsTo`.
     */
    public const SCHEMA_ORG_WITH_DCT = [
        'https://schema.org',
        ['dct' => 'http://purl.org/dc/terms/'],
    ];

    /**
     * IRI of a resource in the MolMeDB RDF namespace, e.g. interaction/membrane13.
     */
    public static function rdfIri(string $path): string
    {
        return rtrim((string) config('fair.rdf.base_url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * Short reference to the whole MolMeDB dataset, for `isPartOf`.
     *
     * @return array{'@type': string, '@id': string, name: string}
     */
    public static function datasetReference(): array
    {
        return [
            '@type' => 'Dataset',
            '@id' => config('fair.frontend_url'),
            'name' => config('fair.dataset_name'),
        ];
    }

    /**
     * Dataset authors as schema.org Persons identified by their ORCID iD.
     *
     * @return array<int, array<string, string>>
     */
    public static function authors(): array
    {
        return array_map(fn (array $author): array => array_filter([
            '@type' => 'Person',
            '@id' => isset($author['orcid']) ? "https://orcid.org/{$author['orcid']}" : null,
            'givenName' => $author['given_name'],
            'familyName' => $author['family_name'],
            'name' => "{$author['given_name']} {$author['family_name']}",
        ]), config('fair.authors', []));
    }

    /**
     * @return array{'@type': string, name: string, url: string, address: string}
     */
    public static function publisher(): array
    {
        return [
            '@type' => 'Organization',
            'name' => config('fair.publisher.name'),
            'url' => config('fair.publisher.url'),
            'address' => config('fair.publisher.address'),
        ];
    }
}
