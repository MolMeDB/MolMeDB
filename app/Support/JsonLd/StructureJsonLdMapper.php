<?php

namespace App\Support\JsonLd;

use App\Models\Identifier;
use App\Models\Structure;
use App\Support\ExternalIdentifierResolver;

/**
 * Maps a Structure to a Bioschemas "MolecularEntity" JSON-LD document
 * (https://bioschemas.org/profiles/MolecularEntity/0.5-RELEASE).
 *
 * The node @id is the identifiers.org IRI, the same one MolMeDB RDF uses for
 * the substance, so JSON-LD and RDF describe the same resource.
 */
class StructureJsonLdMapper
{
    public const PROFILE = 'https://bioschemas.org/profiles/MolecularEntity/0.5-RELEASE';

    public function map(Structure $structure): array
    {
        $crossReferences = ($structure->relationLoaded('identifiers') ? $structure->identifiers : collect())
            ->whereNotIn('state', Identifier::NON_PUBLIC_STATES)
            ->whereNotIn('type', [Identifier::TYPE_NAME, Identifier::TYPE_MOLMEDB])
            ->map(fn (Identifier $identifier) => ExternalIdentifierResolver::resolve($identifier->type, $identifier->value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $landingPage = config('fair.frontend_url')."/mol/{$structure->identifier}";

        return array_filter([
            '@context' => JsonLdContext::SCHEMA_ORG_WITH_DCT,
            '@type' => 'MolecularEntity',
            '@id' => ExternalIdentifierResolver::resolveMolMeDb($structure->identifier),
            'dct:conformsTo' => ['@id' => self::PROFILE],
            'identifier' => $structure->identifier,
            'name' => $structure->name,
            'url' => $landingPage,
            'mainEntityOfPage' => $landingPage,
            'smiles' => $structure->canonical_smiles ? [$structure->canonical_smiles] : null,
            'inChI' => $structure->inchi ?: null,
            'inChIKey' => $structure->inchikey ?: null,
            'molecularWeight' => $structure->molecular_weight !== null ? [
                '@type' => 'QuantitativeValue',
                'value' => (float) $structure->molecular_weight,
                'unitText' => 'g/mol',
            ] : null,
            'additionalProperty' => $structure->logp !== null ? [[
                '@type' => 'PropertyValue',
                'name' => 'LogP',
                'value' => (float) $structure->logp,
            ]] : null,
            'sameAs' => $crossReferences ?: null,
            'isPartOf' => JsonLdContext::datasetReference(),
            'license' => config('fair.data_license.url'),
        ], fn ($value) => $value !== null);
    }
}
