<?php

namespace App\Support\JsonLd;

use App\Models\Publication;
use App\Support\PublicApiUrl;
use Modules\References\EuropePMC\Enums\Sources;

/**
 * Maps a Publication to a plain schema.org "ScholarlyArticle" — no
 * Bioschemas-specific profile needed here, core vocabulary already covers
 * bibliographic metadata.
 */
class PublicationJsonLdMapper
{
    public function map(Publication $publication): array
    {
        $pmid = $publication->identifier_source === Sources::MED->value ? $publication->identifier : null;

        // full_name isn't backfilled for older/imported rows — fall back to
        // first_name/last_name (first_name alone often already holds the
        // full display name for those legacy records).
        $authors = $publication->relationLoaded('authors')
            ? $publication->authors->map(fn ($author) => array_filter([
                '@type' => 'Person',
                'name' => $author->full_name ?: trim("{$author->first_name} {$author->last_name}"),
            ]))->values()->all()
            : null;

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'ScholarlyArticle',
            '@id' => $publication->doi ? "https://doi.org/{$publication->doi}" : PublicApiUrl::to("publications/{$publication->id}"),
            'identifier' => $pmid ? "PMID:{$pmid}" : null,
            'name' => $publication->title,
            'headline' => $publication->title,
            'datePublished' => $publication->year ? (string) $publication->year : null,
            'isPartOf' => $publication->journal ? ['@type' => 'Periodical', 'name' => $publication->journal] : null,
            'sameAs' => JsonLdContext::rdfIri("reference/ref{$publication->id}"),
            'author' => $authors ?: null,
            'url' => config('fair.frontend_url')."/publication/{$publication->id}",
        ], fn ($value) => $value !== null);
    }
}
