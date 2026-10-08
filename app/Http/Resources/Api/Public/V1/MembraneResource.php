<?php

namespace App\Http\Resources\Api\Public\V1;

use App\Support\JsonLd\AssayComponentJsonLdMapper;
use App\Support\PublicApiUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MembraneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if ($request->attributes->get('response_format') === 'jsonld') {
            return app(AssayComponentJsonLdMapper::class)->membrane($this->resource);
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'abbreviation' => $this->abbreviation,
            'description' => $this->description,
            'categories' => $this->whenLoaded('categories', fn () => CategoryResource::collection($this->categories)),
            'url' => PublicApiUrl::to("membranes/{$this->id}"),
            'landing_page' => config('fair.frontend_url')."/membrane/{$this->id}",
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
