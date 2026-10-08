<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentArticle extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'synced_from_source' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('position')
            ->orderBy('title');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * Published from a file in resources/docs (see `php artisan docs:sync`),
     * not edited in the administration.
     */
    public function isManaged(): bool
    {
        return $this->synced_from_source && $this->source !== null;
    }

    /**
     * The source file in the repository, for the administration.
     */
    public function sourceUrl(): ?string
    {
        return $this->source === null
            ? null
            : rtrim((string) config('documentation.source_url'), '/').'/'.$this->source;
    }

    public function fullSlug(): string
    {
        if ($this->parent?->slug) {
            return $this->parent->slug.'/'.$this->slug;
        }

        return $this->slug;
    }
}
