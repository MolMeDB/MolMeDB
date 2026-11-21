<?php

namespace App\Http\Resources\Api\Public\V1;

use Illuminate\Http\Request;

/**
 * A structure found by a similarity search, with its similarity.
 */
class SimilarStructureResource extends StructureResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            /**
             * Tanimoto coefficient of the Bingo fingerprints of both structures (0-1).
             *
             * @var float
             */
            'similarity' => round((float) $this->similarity, 4),
        ];
    }
}
