<?php

namespace App\Http\Resources\Api\Public\V1;

use App\Support\JsonLd\JsonLdContext;
use App\Support\PublicApiUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InteractionActiveResource extends JsonResource
{
    /**
     * Relations to eager load for a list of these resources.
     *
     * @var array<int, string>
     */
    public const RELATIONS = ['structure', 'protein.identifiers', 'category', 'dataset.publications', 'publication'];

    public function toArray(Request $request): array
    {
        return [
            /** @var int */
            'id' => $this->id,
            'url' => PublicApiUrl::to("interactions/active/{$this->id}"),
            /** IRI of the interaction in the MolMeDB RDF graph. */
            'rdf' => JsonLdContext::rdfIri("transporter/tra{$this->id}"),
            'structure' => StructureReferenceResource::make($this->structure),
            'protein' => $this->protein ? ProteinReferenceResource::make($this->protein) : null,
            /** Type of the interaction with the transporter, e.g. "Substrate" (pKm or pEC50 ≥ 5), "Non-substrate" (< 5), "Inhibitor" (pKi or pIC50 ≥ 5), "Non-inhibitor" (< 5). */
            'type' => $this->category?->title,
            /**
             * Temperature [°C].
             *
             * @var float|null
             */
            'temperature' => $this->temperature,
            /** @var float|null */
            'ph' => $this->ph,
            /** Charge of the measured form as recorded, e.g. "0", "+1", "-1". */
            'charge' => $this->charge,
            'note' => $this->note,
            /**
             * pKm, the negative decimal logarithm of the Michaelis constant Km [M].
             *
             * @var float|null
             */
            'km' => $this->km,
            /** @var float|null */
            'km_accuracy' => $this->km_accuracy,
            /**
             * pEC50, the negative decimal logarithm of the half maximal effective concentration EC50 [M].
             *
             * @var float|null
             */
            'ec50' => $this->ec50,
            /** @var float|null */
            'ec50_accuracy' => $this->ec50_accuracy,
            /**
             * pKi, the negative decimal logarithm of the inhibition constant Ki [M].
             *
             * @var float|null
             */
            'ki' => $this->ki,
            /** @var float|null */
            'ki_accuracy' => $this->ki_accuracy,
            /**
             * pIC50, the negative decimal logarithm of the half maximal inhibitory concentration IC50 [M].
             *
             * @var float|null
             */
            'ic50' => $this->ic50,
            /** @var float|null */
            'ic50_accuracy' => $this->ic50_accuracy,
            /** Publication the value was taken from. */
            'primary_reference' => $this->publication ? PublicationReferenceResource::make($this->publication) : null,
            /** Publication of the whole dataset (e.g. a review or a database the value was collected from). */
            'secondary_reference' => $this->dataset?->publications?->first()
                ? PublicationReferenceResource::make($this->dataset->publications->first())
                : null,
        ];
    }
}
