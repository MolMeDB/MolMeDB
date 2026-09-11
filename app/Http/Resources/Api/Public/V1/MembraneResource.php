<?php

namespace App\Http\Resources\Api\Public\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MembraneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'abbreviation' => $this->abbreviation,
            'description' => $this->description,
            'categories' => $this->whenLoaded('categories', fn () => CategoryResource::collection($this->categories)),
            'url' => url("/api/public/v1/membranes/{$this->id}"),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
