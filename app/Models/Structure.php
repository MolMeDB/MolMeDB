<?php

namespace App\Models;

use Database\Factories\SubstanceFactory;
use EloquentFilter\Filterable;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Modules\PredictionWorkers\Models\PredictionStructure;

class Structure extends BaseModel
{
    use Filterable;

    /** @use HasFactory<SubstanceFactory> */
    use HasFactory, SoftDeletes;

    protected $with = ['identifiers'];

    public function scopeContainingSubstructure(Builder $query, string $smiles): Builder
    {
        $canonicalSmiles = $query->getModel()->qualifyColumn('canonical_smiles');

        return $query
            ->whereNotNull($canonicalSmiles)
            ->whereRaw("bingo.checkMolecule($canonicalSmiles) IS NULL")
            ->whereRaw(
                "$canonicalSmiles @ (?, '')::bingo.sub",
                [trim($smiles)],
            );
    }

    /**
     * Matches structures that are the same molecule as the given SMILES,
     * regardless of the exact notation used (bingo normalizes the structure
     * itself, unlike a plain string comparison of canonical_smiles).
     */
    public function scopeExactMolecule(Builder $query, string $smiles): Builder
    {
        $canonicalSmiles = $query->getModel()->qualifyColumn('canonical_smiles');

        return $query
            ->whereNotNull($canonicalSmiles)
            ->whereRaw("bingo.checkMolecule($canonicalSmiles) IS NULL")
            ->whereRaw(
                "$canonicalSmiles @ (?, '')::bingo.exact",
                [trim($smiles)],
            );
    }

    /**
     * Structures similar to the given SMILES by the Tanimoto coefficient of
     * Bingo fingerprints (at least $threshold), most similar first, with it
     * in the "similarity" column. Like the substructure search, the
     * conditions match the partial Bingo index; without them the planner
     * scans the whole table (~55 s instead of tens of milliseconds).
     */
    public function scopeSimilarTo(Builder $query, string $smiles, float $threshold): Builder
    {
        $canonicalSmiles = $query->getModel()->qualifyColumn('canonical_smiles');
        $smiles = trim($smiles);

        if ($query->getQuery()->columns === null) {
            $query->select($query->getModel()->qualifyColumn('*'));
        }

        return $query
            ->selectRaw("bingo.getSimilarity($canonicalSmiles, ?, 'Tanimoto') AS similarity", [$smiles])
            ->whereNotNull($canonicalSmiles)
            ->whereRaw("bingo.checkMolecule($canonicalSmiles) IS NULL")
            ->whereRaw("$canonicalSmiles @ (?, 1, ?, 'Tanimoto')::bingo.sim", [$threshold, $smiles])
            ->orderByDesc('similarity')
            ->orderBy($query->getModel()->qualifyColumn('id'));
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function (Structure $structure) {
            // Delete related data
            $structure->identifiers()->delete();
            $structure->interactionsActive()->delete();
            $structure->interactionsPassive()->delete();
            foreach ($structure->chargedChildren as $ion) {
                $ion->delete();
            }
        });

        static::restoring(function (Structure $structure) {
            // Restore related data
            $structure->identifiers()->restore();
            $structure->interactionsActive()->restore();
            $structure->interactionsPassive()->restore();
            foreach ($structure->chargedChildren()->withTrashed()->get() as $ion) {
                $ion->restore();
            }
        });

        static::forceDeleting(function (Structure $structure) {
            $structure->identifiers()->forceDelete();
            $structure->interactionsActive()->forceDelete();
            $structure->interactionsPassive()->forceDelete();
            foreach ($structure->chargedChildren()->withTrashed()->get() as $ion) {
                $ion->forceDelete();
            }
        });
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(Identifier::class, 'structure_id', 'id')
            ->orderBy('id', 'asc');
    }

    public function changeMainIdentifier($newIdentifier)
    {
        if (! $newIdentifier) {
            return;
        }

        $existsInStructures = self::query()
            ->where('identifier', $newIdentifier)
            ->where('id', '!=', $this->id)
            ->exists();

        $existsInMolmedbIdentifiers = Identifier::withTrashed()
            ->where('type', Identifier::TYPE_MOLMEDB)
            ->where('value', $newIdentifier)
            ->where('structure_id', '!=', $this->id)
            ->exists();

        if ($existsInStructures || $existsInMolmedbIdentifiers) {
            throw new Exception('Identifier '.$newIdentifier.' is already in use.');
        }

        DB::transaction(function () use ($newIdentifier): void {
            $oldIdentifier = $this->identifier;

            $this->identifier = $newIdentifier;
            $this->save();

            Identifier::query()
                ->where('structure_id', $this->id)
                ->where('type', Identifier::TYPE_MOLMEDB)
                ->where('state', Identifier::STATE_ACTIVE)
                ->update(['state' => Identifier::STATE_VALIDATED]);

            $newMainIdentifier = Identifier::withTrashed()
                ->firstOrCreate([
                    'structure_id' => $this->id,
                    'type' => Identifier::TYPE_MOLMEDB,
                    'value' => $newIdentifier,
                ], [
                    'state' => Identifier::STATE_ACTIVE,
                    'source_id' => null,
                    'source_type' => null,
                ]);

            if ($newMainIdentifier->trashed()) {
                $newMainIdentifier->restore();
            }

            $newMainIdentifier->state = Identifier::STATE_ACTIVE;
            $newMainIdentifier->source_id = null;
            $newMainIdentifier->source_type = null;
            $newMainIdentifier->save();

            if (! $oldIdentifier || $oldIdentifier === $newIdentifier) {
                return;
            }

            $legacyIdentifier = Identifier::withTrashed()
                ->firstOrCreate([
                    'structure_id' => $this->id,
                    'type' => Identifier::TYPE_MOLMEDB,
                    'value' => $oldIdentifier,
                ], [
                    'state' => Identifier::STATE_OBSOLETE,
                    'source_id' => $newMainIdentifier->id,
                    'source_type' => Identifier::class,
                ]);

            if ($legacyIdentifier->trashed()) {
                $legacyIdentifier->restore();
            }

            $legacyIdentifier->state = Identifier::STATE_OBSOLETE;
            $legacyIdentifier->source_id = $newMainIdentifier->id;
            $legacyIdentifier->source_type = Identifier::class;
            $legacyIdentifier->save();
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Structure::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Structure::class, 'parent_id');
    }

    public function interactionsPassive(): HasMany
    {
        return $this->hasMany(InteractionPassive::class);
    }

    public function interactionsActive(): HasMany
    {
        return $this->hasMany(InteractionActive::class);
    }

    public function fluorescentProperties(): HasMany
    {
        return $this->hasMany(FluorescentProperty::class);
    }

    public function predictionStructure(): HasOne
    {
        return $this->hasOne(PredictionStructure::class, 'remote_id');
    }

    public function chargedChildren(): HasMany
    {
        return $this->hasMany(Structure::class, 'parent_id');
    }

    public function isForceDeletable(): bool
    {
        return $this->interactionsPassive()->withTrashed()->count() == 0
            && $this->interactionsActive()->withTrashed()->count() == 0
            && $this->fluorescentProperties()->withTrashed()->count() == 0
            && $this->children()->withTrashed()->count() == 0;
    }

    public function isRestoreable(): bool
    {
        if (! $this?->id) {
            return false;
        }

        return $this->parent && true;
    }

    public function setParent(Structure $parent)
    {
        foreach ($this->children()->get() as $child) {
            $child->parent_id = $parent->id;
            $child->save();
        }

        $this->parent_id = $parent->id;
        $this->save();
    }

    public function getNameAttribute()
    {
        return $this->identifiers
            ->where('type', Identifier::TYPE_NAME)
            ->where('state', '!=', Identifier::STATE_INVALID)
            ->sortByDesc('state')
            ->first()?->value;
    }

    public function getPdbAttribute()
    {
        return $this->identifiers
            ->where('type', Identifier::TYPE_PDB)
            ->where('state', '!=', Identifier::STATE_INVALID)
            ->sortByDesc('state')
            ->first()?->value;
    }

    public function getPubchemAttribute()
    {
        return $this->identifiers
            ->where('type', Identifier::TYPE_PUBCHEM)
            ->where('state', '!=', Identifier::STATE_INVALID)
            ->sortByDesc('state')
            ->first()?->value;
    }

    public function getDrugbankAttribute()
    {
        return $this->identifiers
            ->where('type', Identifier::TYPE_DRUGBANK)
            ->where('state', '!=', Identifier::STATE_INVALID)
            ->sortByDesc('state')
            ->first()?->value;
    }

    public function getChemblAttribute()
    {
        return $this->identifiers
            ->where('type', Identifier::TYPE_CHEMBL)
            ->where('state', '!=', Identifier::STATE_INVALID)
            ->sortByDesc('state')
            ->first()?->value;
    }

    public function getChebiAttribute()
    {
        return $this->identifiers
            ->where('type', Identifier::TYPE_CHEBI)
            ->where('state', '!=', Identifier::STATE_INVALID)
            ->sortByDesc('state')
            ->first()?->value;
    }

    public static function join_structures(self $structure_1, self $structure_2)
    {
        // Reassign all related data from structure_2 to structure_1
        foreach ($structure_2->identifiers as $identifier) {
            // Check if exists
            if ($structure_1->identifiers()
                ->where('type', $identifier->type)
                ->where('value', $identifier->value)
                ->where('source_type', $identifier->source_type)
                ->where('source_id', $identifier->source_id)
                ->exists()) {
                continue;
            }

            $identifier->structure_id = $structure_1->id;
            $identifier->save();
        }

        foreach ($structure_2->interactionsActive as $interaction) {
            $interaction->structure_id = $structure_1->id;
            $interaction->save();
        }

        foreach ($structure_2->interactionsPassive as $interaction) {
            $interaction->structure_id = $structure_1->id;
            $interaction->save();
        }

        foreach ($structure_2->fluorescentProperties as $property) {
            $property->structure_id = $structure_1->id;
            $property->save();
        }

        if ($structure_2->identifier && $structure_2->identifier !== $structure_1->identifier) {
            $activeMolmedbIdentifier = Identifier::query()
                ->where('structure_id', $structure_1->id)
                ->where('type', Identifier::TYPE_MOLMEDB)
                ->where('value', $structure_1->identifier)
                ->first();

            $legacyMolmedbIdentifier = Identifier::withTrashed()
                ->firstOrCreate([
                    'structure_id' => $structure_1->id,
                    'type' => Identifier::TYPE_MOLMEDB,
                    'value' => $structure_2->identifier,
                ], [
                    'state' => Identifier::STATE_OBSOLETE,
                    'source_id' => $activeMolmedbIdentifier?->id,
                    'source_type' => $activeMolmedbIdentifier ? Identifier::class : null,
                ]);

            if ($legacyMolmedbIdentifier->trashed()) {
                $legacyMolmedbIdentifier->restore();
            }

            $legacyMolmedbIdentifier->state = Identifier::STATE_OBSOLETE;
            $legacyMolmedbIdentifier->source_id = $activeMolmedbIdentifier?->id;
            $legacyMolmedbIdentifier->source_type = $activeMolmedbIdentifier ? Identifier::class : null;
            $legacyMolmedbIdentifier->save();
        }

        // Delete structure_2
        $structure_2->delete();
    }
}
