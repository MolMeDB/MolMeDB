<?php

namespace App\Http\Resources\Api\Public\V1;

use App\Models\Identifier;
use App\Support\ExternalIdentifierResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StructureIdentifierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => strtolower(Identifier::enumType($this->type)),
            'value' => $this->value,
            'uri' => ExternalIdentifierResolver::resolve($this->type, $this->value),
            'verified' => $this->when($this->state === Identifier::STATE_NEW, false),
        ];
    }
}
