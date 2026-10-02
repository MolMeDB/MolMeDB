<?php

namespace App\Models;

use Database\Factories\PublicationFactory;
use EloquentFilter\Filterable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\References\EuropePMC\Enums\Sources;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Publication extends BaseModel
{
    use Filterable;

    /** @use HasFactory<PublicationFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'validated_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::deleting(function (Publication $publication) {
            // Delete related data
            foreach ($publication->datasets as $ds) {
                $ds->delete();
            }
            $publication->interactionsActive()->delete();
            $publication->interactionsPassive()->delete();
        });

        static::restoring(function (Publication $publication) {
            // Restore related data
            foreach ($publication->datasets()->withTrashed()->get() as $ds) {
                $ds->restore();
            }
            $publication->interactionsActive()->restore();
            $publication->interactionsPassive()->restore();
        });

        static::forceDeleting(function (Publication $publication) {
            foreach ($publication->datasets()->withTrashed()->get() as $ds) {
                $ds->forceDelete();
            }
            $publication->membranes()->withTrashed()->get()->detach();
            $publication->methods()->withTrashed()->get()->detach();
            $publication->interactionsActive()->forceDelete();
            $publication->interactionsPassive()->forceDelete();
        });
    }

    /**
     * TYPES
     */
    const TYPE_PUBCHEM = 1;

    const TYPE_CHEMBL = 2;

    const TYPE_COSMO = 3;

    private static $enum_types = [
        self::TYPE_PUBCHEM => 'Pubchem',
        self::TYPE_CHEMBL => 'ChEMBL',
        self::TYPE_COSMO => 'COSMO',
    ];

    public static function types(): array
    {
        return self::$enum_types;
    }

    /**
     * Returns enum type
     */
    public static function enumType($type): ?string
    {
        if (isset(self::$enum_types[$type])) {
            return self::$enum_types[$type];
        }

        return null;
    }

    public function getSelectTitle(): string
    {
        return "[$this->id]: $this->citation";
    }

    /**
     * Returns the PubMed ID, which is only available for publications
     * identified by a PubMed/Medline identifier.
     */
    public function getPmidAttribute(): ?string
    {
        return $this->identifier_source === Sources::MED->value
            ? $this->identifier
            : null;
    }

    /**
     * Returns all assigned membranes, which are described in the current publication
     */
    public function membranes(): BelongsToMany
    {
        return $this->belongsToMany(Membrane::class, 'model_has_publications', 'publication_id', 'model_id')
            ->withPivot('model_type', 'model_id')
            ->wherePivot('model_type', Membrane::class);
    }

    /**
     * Returns all assigned methods, which are described in the current publication
     */
    public function methods(): BelongsToMany
    {
        return $this->belongsToMany(Method::class, 'model_has_publications', 'publication_id', 'model_id')
            ->withPivot('model_type', 'model_id')
            ->wherePivot('model_type', Method::class);
    }

    public function datasets(): BelongsToMany
    {
        return $this->belongsToMany(Dataset::class, 'model_has_publications', 'publication_id', 'model_id')
            ->withPivot('model_type', 'model_id')
            ->wherePivot('model_type', Dataset::class);
    }

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class, 'model_has_files', 'model_id')
            ->wherePivot('model_type', self::class)
            ->orderBy('created_at', 'desc');
    }

    public function interactionsPassive(): HasMany
    {
        return $this->hasMany(InteractionPassive::class);
    }

    public function interactionsActive(): HasMany
    {
        return $this->hasMany(InteractionActive::class);
    }

    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class, 'publication_has_authors');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->logOnlyDirty();
    }
}
