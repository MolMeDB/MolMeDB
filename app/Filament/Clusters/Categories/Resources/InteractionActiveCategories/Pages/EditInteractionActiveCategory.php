<?php

namespace App\Filament\Clusters\Categories\Resources\InteractionActiveCategories\Pages;

use App\Filament\Clusters\Categories\Resources\InteractionActiveCategories\InteractionActiveCategoryResource;
use App\Models\Category;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInteractionActiveCategory extends EditRecord
{
    protected static string $resource = InteractionActiveCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(fn (DeleteAction $action, Category $record) => InteractionActiveCategoryResource::checkIfDeletable($action, $record)),
        ];
    }
}
