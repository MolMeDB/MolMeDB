<?php

namespace App\Models;

use App\Models\Concerns\VisibleInPublicApi;
use Database\Factories\InteractionPassiveFactory;
use EloquentFilter\Filterable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InteractionPassive extends Model
{
    protected $table = 'interactions_passive';

    use Filterable;

    /** @use HasFactory<InteractionPassiveFactory> */
    use HasFactory, SoftDeletes;

    use VisibleInPublicApi;

    protected $guarded = [];

    /**
     * Returns assigned substance
     */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(Structure::class);
    }

    /**
     * Returns assigned dataset
     */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    /**
     * Returns assigned publication
     */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    public function isRestoreable()
    {
        if (! $this?->id) {
            return false;
        }

        return $this->structure && $this->dataset && $this->publication;
    }
}
