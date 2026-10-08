<?php

namespace App\ModelFilters;

use App\Models\Category;

/**
 * Filters of GET /api/v1/interactions/active and the active interaction
 * listings of structures, proteins and publications.
 */
class PublicInteractionActiveFilter extends PublicInteractionFilter
{
    public static function valueColumns(): array
    {
        return ['km', 'ec50', 'ki', 'ic50'];
    }

    public function protein(int|string $id): void
    {
        $this->where($this->qualify('protein_id'), $id);
    }

    public function uniprot(string $uniprotId): void
    {
        $this->whereIn($this->qualify('protein_id'), fn ($proteins) => $proteins
            ->select('id')
            ->from('proteins')
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(uniprot_id) = ?', [strtolower(trim($uniprotId))]));
    }

    /**
     * Interaction type by its category title (e.g. "Substrate", "Non-inhibitor"),
     * case-insensitive.
     */
    public function type(string $title): void
    {
        $this->whereIn($this->qualify('category_id'), fn ($categories) => $categories
            ->select('id')
            ->from('categories')
            ->where('type', Category::TYPE_ACTIVE_INTERACTION)
            ->whereRaw('LOWER(title) = ?', [mb_strtolower(trim($title))]));
    }
}
