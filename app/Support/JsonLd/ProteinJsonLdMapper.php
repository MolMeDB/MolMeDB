<?php

namespace App\Support\JsonLd;

use App\Models\Protein;
use App\Models\ProteinIdentifier;

/**
 * Maps a transporter Protein to a Bioschemas "Protein" document
 * (https://bioschemas.org/profiles/Protein/0.11-RELEASE), with its MolMeDB RDF
 * IRI as @id and the UniProt entry (its purl.uniprot.org RDF IRI) as sameAs.
 */
class ProteinJsonLdMapper
{
    public const PROFILE = 'https://bioschemas.org/profiles/Protein/0.11-RELEASE';

    public const TRANSPORTER = 'http://www.bioassayontology.org/bao#BAO_0000283';

    public function map(Protein $protein): array
    {
        $landingPage = config('fair.frontend_url')."/protein/{$protein->id}";
        $geneName = $protein->relationLoaded('identifiers')
            ? $protein->identifiers
                ->where('type', ProteinIdentifier::TYPE_NAME)
                ->whereIn('state', [ProteinIdentifier::STATE_NEW, ProteinIdentifier::STATE_VALIDATED])
                ->sortByDesc('state')
                ->first()?->value
            : null;

        return array_filter([
            '@context' => JsonLdContext::SCHEMA_ORG_WITH_DCT,
            '@type' => 'Protein',
            '@id' => JsonLdContext::rdfIri("transporter/target{$protein->id}"),
            'dct:conformsTo' => ['@id' => self::PROFILE],
            'additionalType' => self::TRANSPORTER,
            'identifier' => $protein->uniprot_id,
            'name' => $geneName ?? $protein->uniprot_id,
            // The UniProt RDF IRI, as MolMeDB RDF and UniProt's own RDF use it.
            'sameAs' => $protein->uniprot_id ? "http://purl.uniprot.org/uniprot/{$protein->uniprot_id}" : null,
            'url' => $landingPage,
            'mainEntityOfPage' => $landingPage,
            'isPartOf' => JsonLdContext::datasetReference(),
        ], fn ($value) => $value !== null);
    }
}
