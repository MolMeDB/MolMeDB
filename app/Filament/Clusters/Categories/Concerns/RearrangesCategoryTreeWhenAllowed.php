<?php

namespace App\Filament\Clusters\Categories\Concerns;

use App\Enums\PermissionEnums;

/**
 * For the category tree pages: dragging a category moves and reorders the
 * whole tree, so it takes the permission to manage all categories. Viewing
 * the page does not give it.
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

        return parent::updateTree($list);
    }
}
