<?php

namespace App\Support\JsonLd;

/**
 * Whole-database schema.org "Dataset" descriptor — served from the /about
 * endpoint when `Accept: application/ld+json` is requested, letting crawlers
 * and registries (FAIRsharing, re3data, Google Dataset Search) discover
 * MolMeDB as one citable, licensed dataset.
 */
class DatasetJsonLdMapper
{
    public function map(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Dataset',
            'name' => config('fair.dataset_name'),
            'description' => config('fair.dataset_description'),
            'url' => config('fair.frontend_url'),
            'identifier' => config('fair.citation.url'),
            'license' => config('fair.data_license.url'),
            'creator' => [
                '@type' => 'Organization',
                'name' => 'Palacký University Olomouc, Department of Physical Chemistry',
            ],
            'citation' => config('fair.citation.url'),
            'version' => config('fair.version'),
        ];
    }
}
