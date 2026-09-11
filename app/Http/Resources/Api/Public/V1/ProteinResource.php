<?php

namespace App\Http\Resources\Api\Public\V1;

use App\Models\ProteinIdentifier;
use App\Support\ExternalIdentifierResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProteinResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uniprot_id' => $this->uniprot_id,
            'uniprot_uri' => $this->when($this->uniprot_id, fn () => ExternalIdentifierResolver::resolveUniprot($this->uniprot_id)),
            'identifiers' => $this->whenLoaded('identifiers', fn () => ProteinIdentifierResource::collection(
                $this->identifiers
                    ->whereIn('state', [ProteinIdentifier::STATE_NEW, ProteinIdentifier::STATE_VALIDATED])
                    ->values()
            )),
            'url' => url("/api/public/v1/proteins/{$this->id}"),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
