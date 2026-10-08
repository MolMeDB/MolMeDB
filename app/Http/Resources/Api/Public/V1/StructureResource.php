<?php

namespace App\Http\Resources\Api\Public\V1;

use App\Models\Identifier;
use App\Support\JsonLd\StructureJsonLdMapper;
use App\Support\PublicApiUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CdkDepict\CdkDepict;

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
            /**
             * Net charge of the structure, when recorded.
             *
             * @var int|null
             */
            'charge' => $this->when($this->detailed, $this->charge),
            'identifiers' => $this->when($this->detailed, fn () => StructureIdentifierResource::collection(
                $this->identifiers
                    ->whereIn('state', [Identifier::STATE_NEW, Identifier::STATE_VALIDATED, Identifier::STATE_ACTIVE])
                    ->whereNotIn('type', [Identifier::TYPE_NAME, Identifier::TYPE_MOLMEDB])
                    ->values()
            )),
            /** The parent molecule this structure is a form of (e.g. an ionization state or a stereoisomer), or null. */
            'parent' => $this->when($this->detailed, fn () => $this->parent?->identifier
                ? StructureReferenceResource::make($this->parent)
                : null),
            /** Forms of this molecule (e.g. ionization states or stereoisomers), identified as <identifier>.<n>. */
            'forms' => $this->when($this->detailed, fn () => StructureReferenceResource::collection(
                $this->children->whereNotNull('identifier')->sortBy('identifier')->values()
            )),
            /** 2D depiction of the structure (SVG). */
            'depiction_url' => $this->when($this->detailed, fn () => CdkDepict::depictionUrl($this->canonical_smiles)),
            /** 3D structure as an MDL molfile. */
            'molfile_url' => $this->when($this->detailed, fn () => PublicApiUrl::to("structures/{$this->identifier}/molfile")),
            'url' => PublicApiUrl::to("structures/{$this->identifier}"),
            'landing_page' => config('fair.frontend_url')."/mol/{$this->identifier}",
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
