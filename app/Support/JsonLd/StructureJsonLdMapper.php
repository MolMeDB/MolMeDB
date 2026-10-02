<?php

namespace App\Support\JsonLd;

use App\Models\Identifier;
use App\Models\Structure;
use App\Support\ExternalIdentifierResolver;
use App\Support\PublicApiUrl;

/**
 * Maps a Structure to a schema.org/Bioschemas "MolecularEntity" JSON-LD
 * document (https://bioschemas.org/profiles/MolecularEntity). Chemistry
 * fields with no native schema.org property (SMILES, InChI, InChIKey) are
 * carried as `additionalProperty` PropertyValue pairs, the standard
 * Bioschemas pattern for this.
 */
class StructureJsonLdMapper
{
    public function map(Structure $structure): array
    {
        $additionalProperties = array_filter([
            $structure->canonical_smiles ? $this->property('SMILES', $structure->canonical_smiles) : null,
            $structure->inchi ? $this->property('InChI', $structure->inchi) : null,
            $structure->inchikey ? $this->property('InChIKey', $structure->inchikey) : null,
            $structure->logp !== null ? $this->property('LogP', (string) $structure->logp) : null,
        ]);

        $crossReferences = ($structure->relationLoaded('identifiers') ? $structure->identifiers : collect())
            ->whereIn('state', [Identifier::STATE_NEW, Identifier::STATE_VALIDATED, Identifier::STATE_ACTIVE])
            ->whereNotIn('type', [Identifier::TYPE_NAME, Identifier::TYPE_MOLMEDB])
            ->map(fn (Identifier $identifier) => ExternalIdentifierResolver::resolve($identifier->type, $identifier->value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => ['MolecularEntity', 'ChemicalSubstance'],
            'identifier' => $structure->identifier,
            'name' => $structure->name,
            'molecularWeight' => $structure->molecular_weight,
            'url' => PublicApiUrl::to("structures/{$structure->identifier}"),
            'mainEntityOfPage' => rtrim(config('fair.frontend_url'), '/')."/mol/{$structure->identifier}",
            'sameAs' => $crossReferences ?: null,
            'additionalProperty' => $additionalProperties ?: null,
            'license' => config('fair.data_license.url'),
        ], fn ($value) => $value !== null);
    }

    private function property(string $name, string $value): array
    {
        return [
            '@type' => 'PropertyValue',
            'name' => $name,
            'value' => $value,
        ];
    }
}
