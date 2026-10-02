<?php

namespace App\Support\JsonLd;

use App\Models\Membrane;
use App\Models\Method;

/**
 * Maps membranes and methods to JSON-LD. schema.org has no class for membrane
 * models or assay methods, so they are typed with the MolMeDB RDF classes
 * (mmdbvoc:MembraneModel, bao:BAO_0002753 assay method component) and use
 * their MolMeDB RDF IRIs as @id.
 */
class AssayComponentJsonLdMapper
{
    public const MEMBRANE_MODEL = 'https://rdf.molmedb.upol.cz/vocabulary#MembraneModel';

    public const ASSAY_METHOD_COMPONENT = 'http://www.bioassayontology.org/bao#BAO_0002753';

    public function membrane(Membrane $membrane): array
    {
        return $this->map(
            JsonLdContext::rdfIri("interaction/membrane{$membrane->id}"),
            self::MEMBRANE_MODEL,
            config('fair.frontend_url')."/membrane/{$membrane->id}",
            $membrane->name,
            $membrane->abbreviation,
            $membrane->description,
        );
    }

    public function method(Method $method): array
    {
        return $this->map(
            JsonLdContext::rdfIri("interaction/method{$method->id}"),
            self::ASSAY_METHOD_COMPONENT,
            config('fair.frontend_url')."/method/{$method->id}",
            $method->name,
            $method->abbreviation,
            $method->description,
        );
    }

    private function map(string $iri, string $class, string $landingPage, ?string $name, ?string $abbreviation, ?string $description): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Thing',
            '@id' => $iri,
            'additionalType' => $class,
            'name' => $name,
            'alternateName' => $abbreviation && $abbreviation !== $name ? $abbreviation : null,
            'description' => $description ? trim(html_entity_decode(strip_tags($description))) ?: null : null,
            'url' => $landingPage,
            'mainEntityOfPage' => $landingPage,
            'isPartOf' => JsonLdContext::datasetReference(),
        ], fn ($value) => $value !== null);
    }
}
