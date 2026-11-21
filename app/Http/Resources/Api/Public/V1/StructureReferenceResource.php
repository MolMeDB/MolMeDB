<?php

namespace App\Http\Resources\Api\Public\V1;

use App\Support\PublicApiUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Minimal reference to a structure, enough for a client to follow up with
 * GET /structures/{identifier}.
 */
class StructureReferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'identifier' => $this->identifier,
            'name' => $this->name,
            'url' => PublicApiUrl::to("structures/{$this->identifier}"),
        ];
    }
}
