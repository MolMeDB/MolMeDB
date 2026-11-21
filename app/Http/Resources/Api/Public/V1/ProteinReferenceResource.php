<?php

namespace App\Http\Resources\Api\Public\V1;

use App\Models\ProteinIdentifier;
use App\Support\PublicApiUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Minimal reference to a protein, enough for a client to follow up with
 * GET /proteins/{id}.
 */
class ProteinReferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uniprot_id' => $this->uniprot_id,
            /** Gene or protein name, e.g. "SLC22A2". */
            'name' => $this->identifiers
                ->where('type', ProteinIdentifier::TYPE_NAME)
                ->where('state', '!=', ProteinIdentifier::STATE_INVALID)
                ->sortBy('id')
                ->first()?->value,
            'url' => PublicApiUrl::to("proteins/{$this->id}"),
        ];
    }
}
