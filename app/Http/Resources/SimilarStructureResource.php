<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * A related or similar structure on the structure page, with its number of
 * interactions and (for similar ones) its similarity.
 */
class SimilarStructureResource extends StructureResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'similarity' => $this->similarity === null ? null : [
                'tanimoto' => round((float) $this->similarity, 3),
            ],
            'total' => [
                'interactions_passive' => $this->interactions_passive_count,
                'interactions_active' => $this->interactions_active_count,
            ],
        ];
    }
}
