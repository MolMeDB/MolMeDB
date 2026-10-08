<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InteractionPassiveResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dataset' => DatasetResource::make($this->dataset),
            'structure_id' => $this->structure_id,
            'temperature' => $this->temperature,
            'ph' => $this->ph,
            'charge' => $this->charge,
            'note' => $this->note,
            'measurements' => [
                'x_min' => $this->getAccuracyValue('x_min'),
                'gpen' => $this->getAccuracyValue('gpen'),
                'gwat' => $this->getAccuracyValue('gwat'),
                'logk' => $this->getAccuracyValue('logk'),
                'logperm' => $this->getAccuracyValue('logperm'),
            ],
            'primary_reference' => PublicationResource::make($this->publication)->ignoreStats(),
            // The reference of the whole dataset (Dataset has no single "publication" relation).
            'secondary_reference' => PublicationResource::make($this->dataset?->publications?->first())->ignoreStats(),
        ];
    }

    private function getAccuracyValue(string $key)
    {
        $acc_key = $key.'_accuracy';

        return [
            'value' => $this->$key,
            'accuracy' => $this->$acc_key,
        ];
    }
}
