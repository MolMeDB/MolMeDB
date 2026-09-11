<?php

namespace App\Http\Resources\Api\Public\V1;

use App\Models\Identifier;
use App\Support\JsonLd\StructureJsonLdMapper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StructureResource extends JsonResource
{
    private bool $detailed = false;

    public function withDetails(): self
    {
        $this->detailed = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        if ($request->attributes->get('response_format') === 'jsonld') {
            return app(StructureJsonLdMapper::class)->map($this->resource);
        }

        return [
            'identifier' => $this->identifier,
            'name' => $this->name,
            'canonical_smiles' => $this->canonical_smiles,
            'molecular_weight' => $this->molecular_weight,
            'logp' => $this->logp,
            'inchi' => $this->when($this->detailed, $this->inchi),
            'inchikey' => $this->when($this->detailed, $this->inchikey),
            'identifiers' => $this->when($this->detailed, fn () => StructureIdentifierResource::collection(
                $this->identifiers
                    ->whereIn('state', [Identifier::STATE_NEW, Identifier::STATE_VALIDATED, Identifier::STATE_ACTIVE])
                    ->whereNotIn('type', [Identifier::TYPE_NAME, Identifier::TYPE_MOLMEDB])
                    ->values()
            )),
            'url' => url("/api/public/v1/structures/{$this->identifier}"),
            'landing_page' => rtrim(config('fair.frontend_url'), '/')."/mol/{$this->identifier}",
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
