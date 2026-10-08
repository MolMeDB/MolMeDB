<?php

namespace App\Models;

use App\Models\Concerns\VisibleInPublicApi;
use Database\Factories\InteractionActiveFactory;
use EloquentFilter\Filterable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InteractionActive extends Model
{
    protected $table = 'interactions_active';

    use Filterable;

    /** @use HasFactory<InteractionActiveFactory> */
    use HasFactory, SoftDeletes;

    use VisibleInPublicApi;

    protected $guarded = [];

    public static function enumCategories(): array
    {
        return Category::where('type', Category::TYPE_ACTIVE_INTERACTION)->pluck('title', 'id')->toArray();
    }

    /**
     * Returns assigned dataset
     */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Returns assigned substance
     */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(Structure::class);
    }

    /**
     * Returns assigned protein
     */
    public function protein(): BelongsTo
    {
        return $this->belongsTo(Protein::class);
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    public function isRestoreable()
    {
        if (! $this?->id) {
            return false;
        }

        return $this->structure && $this->dataset && $this->publication && $this->protein;
    }
}
