<?php

namespace App\Filament\Clusters\Categories\Concerns;

use App\Enums\PermissionEnums;
use Illuminate\Database\Eloquent\Model;

/**
 * For the category tree pages: dragging a category moves and reorders the
 * whole tree, so it takes the permission to manage all categories. Viewing
 * the page does not give it.
 *
 * The new arrangement comes from the browser. It is taken only when it names
 * categories of this tree alone, each of them once, and leaves the tree no
 * deeper than the page allows: otherwise a crafted request could hang a
 * category under one of another type, under one that does not exist or under
 * itself.
 */
trait RearrangesCategoryTreeWhenAllowed
{
    /**
     * @param  array<int, array<string, mixed>>|null  $list
     * @return array<string, mixed>
     */
    public function updateTree(?array $list = null): array
    {
        abort_unless((bool) auth()->user()?->hasPermissionTo(PermissionEnums::CATEGORIES_MANAGE), 403);
        abort_unless($this->isArrangementOfThisTree($list ?? []), 422);

        return parent::updateTree($list);
    }

    /**
     * @param  array<array-key, mixed>  $list
     */
    private function isArrangementOfThisTree(array $list): bool
    {
        $records = $this->getRecords()->keyBy(fn (Model $record): string => (string) $record->getKey());

        /** @var array<array-key, string|null> $parents the parent each category would get, null at the top */
        $parents = [];

        $current = $records
            ->map(fn (Model $record): ?string => $records->has((string) $record->parent_id) ? (string) $record->parent_id : null)
            ->all();

        if (! $this->collectParents($list, null, $current, $parents)) {
            return false;
        }

        $depthNow = $this->depthOf($current);
        $depthThen = $this->depthOf($parents + $current);

        // A tree that is already deeper than the page allows (older data)
        // can still be rearranged, only not made deeper.
        return $depthThen !== null && $depthThen <= max(static::getMaxDepth(), $depthNow ?? 0);
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @param  array<array-key, string|null>  $known  the categories of this tree, by their key
     * @param  array<array-key, string|null>  $parents
     */
    private function collectParents(array $items, ?string $parent, array $known, array &$parents): bool
    {
        foreach ($items as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;

            if (! is_int($id) && ! is_string($id)) {
                return false;
            }

            $id = (string) $id;

            if (! array_key_exists($id, $known) || array_key_exists($id, $parents)) {
                return false;
            }

            $parents[$id] = $parent;
            $children = $item['children'] ?? [];

            if (! is_array($children) || ! $this->collectParents($children, $id, $known, $parents)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The number of levels of a tree given as category => parent, or null
     * when a category is its own ancestor.
     *
     * @param  array<array-key, string|null>  $parents
     */
    private function depthOf(array $parents): ?int
    {
        $deepest = 0;

        foreach (array_keys($parents) as $id) {
            $depth = 0;

            for ($at = (string) $id; $at !== null; $at = $parents[$at] ?? null) {
                if (++$depth > count($parents)) {
                    return null;
                }
            }

            $deepest = max($deepest, $depth);
        }

        return $deepest;
    }
}
