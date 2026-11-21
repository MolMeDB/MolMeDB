<?php

namespace App\Http\Resources\Api\Public\V1;

use App\Support\JsonLd\JsonLdContext;
use App\Support\PublicApiUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InteractionPassiveResource extends JsonResource
{
    /**
     * Relations to eager load for a list of these resources.
     *
     * @var array<int, string>
     */
    public const RELATIONS = ['structure', 'dataset.membrane', 'dataset.method', 'dataset.publications', 'publication'];

    public function toArray(Request $request): array
    {
        return [
            /** @var int */
            'id' => $this->id,
            'url' => PublicApiUrl::to("interactions/passive/{$this->id}"),
            /** IRI of the interaction in the MolMeDB RDF graph. */
            'rdf' => JsonLdContext::rdfIri("interaction/int{$this->id}"),
            'structure' => StructureReferenceResource::make($this->structure),
            'membrane' => $this->dataset?->membrane ? EntityReferenceResource::make($this->dataset->membrane) : null,
            'method' => $this->dataset?->method ? EntityReferenceResource::make($this->dataset->method) : null,
            /**
             * Temperature [°C].
             *
             * @var float|null
             */
            'temperature' => $this->temperature,
            /** @var float|null */
            'ph' => $this->ph,
            /** Charge of the measured form as recorded, e.g. "0", "+1", "-1" or "+1,-1". */
            'charge' => $this->charge,
            'note' => $this->note,
            /**
             * Xmin, the drug position with minimum energy in the membrane [nm].
             *
             * @var float|null
             */
            'x_min' => $this->x_min,
            /** @var float|null */
            'x_min_accuracy' => $this->x_min_accuracy,
            /**
             * ΔGpen, the penetration barrier [kcal/mol].
             *
             * @var float|null
             */
            'gpen' => $this->gpen,
            /** @var float|null */
            'gpen_accuracy' => $this->gpen_accuracy,
            /**
             * ΔGwat, the affinity towards the membrane [kcal/mol].
             *
             * @var float|null
             */
            'gwat' => $this->gwat,
            /** @var float|null */
            'gwat_accuracy' => $this->gwat_accuracy,
            /**
             * LogK, the logarithm of the membrane-water partition coefficient [mol_m/mol_w].
             *
             * @var float|null
             */
            'logk' => $this->logk,
            /** @var float|null */
            'logk_accuracy' => $this->logk_accuracy,
            /**
             * LogPerm, the logarithm of the permeability coefficient [cm/s].
             *
             * @var float|null
             */
            'logperm' => $this->logperm,
            /** @var float|null */
            'logperm_accuracy' => $this->logperm_accuracy,
            /** Publication the value was taken from. */
            'primary_reference' => $this->publication ? PublicationReferenceResource::make($this->publication) : null,
            /** Publication of the whole dataset (e.g. a review or a database the value was collected from). */
            'secondary_reference' => $this->dataset?->publications?->first()
                ? PublicationReferenceResource::make($this->dataset->publications->first())
                : null,
        ];
    }
}
