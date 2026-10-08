<?php

namespace App\Http\Resources\Api\Public\V1;

use App\Support\JsonLd\PublicationJsonLdMapper;
use App\Support\PublicApiUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\References\EuropePMC\Enums\Sources;

class PublicationResource extends JsonResource
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
            return app(PublicationJsonLdMapper::class)->map($this->resource);
        }

        $pmid = $this->identifier_source === Sources::MED->value ? $this->identifier : null;

        return [
            'id' => $this->id,
            'citation' => $this->citation,
            'title' => $this->title,
            'doi' => $this->doi,
            'doi_url' => $this->when($this->doi, fn () => "https://doi.org/{$this->doi}"),
            'pmid' => $pmid,
            'pmid_url' => $this->when($pmid, fn () => "https://pubmed.ncbi.nlm.nih.gov/{$pmid}/"),
            'year' => $this->year,
            'journal' => $this->when($this->detailed, $this->journal),
            'volume' => $this->when($this->detailed, $this->volume),
            'issue' => $this->when($this->detailed, $this->issue),
            'page' => $this->when($this->detailed, $this->page),
            'authors' => $this->when($this->detailed, fn () => AuthorResource::collection($this->authors)),
            'url' => PublicApiUrl::to("publications/{$this->id}"),
            'landing_page' => config('fair.frontend_url')."/publication/{$this->id}",
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
