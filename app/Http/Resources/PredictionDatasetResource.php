<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionDataset;

class PredictionDatasetResource extends JsonResource
{
    public $include_stats = true;

    public function ignoreStats(): self
    {
        $this->include_stats = false;

        return $this;
    }

    private function ownerVisibleTo(Request $request): bool
    {
        $viewer = $request->user();

        return $viewer !== null && Gate::forUser($viewer)->allows('view', $this->resource);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $progressStats = $this->cachedProgressStats();

        return [
            'id' => $this->id,
            'comment' => $this->comment,
            'token' => $this->token,
            'user_id' => $this->user_id,
            // Token holders (share links) do not get the owner's e-mail.
            'user' => $this->ownerVisibleTo($request) ? UserResource::make($this->user) : null,
            'temperature' => $this->temperature,
            'membrane' => PredictionMembraneResource::make($this->predictionMembrane),
            'method_type' => $this->method_type,
            'method' => PredictionDataset::method($this->method_type),
            'remote_method' => Prediction::remoteMethodKeyFor($this->method_type),
            'priority' => $this->priority,
            'state' => $progressStats['state'],
            'enum_state' => $progressStats['enum_state'],
            'stats' => $progressStats['stats'],
            'overall_stats' => $progressStats['overall_stats'],
            'updated_at' => $this->updated_at->format('Y/m/d H:i:s'),
            'created_at' => $this->created_at->format('Y/m/d H:i:s'),
            'predictions' => PredictionResource::collection($this->whenLoaded('predictions')),
        ];
    }
}
