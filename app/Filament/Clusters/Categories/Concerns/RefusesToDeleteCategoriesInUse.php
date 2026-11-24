<?php

namespace App\Filament\Clusters\Categories\Concerns;

use App\Models\Category;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * For the category resources: a category with assigned records or with child
 * categories is not deleted, from its page or from the tree.
 */
trait RefusesToDeleteCategoriesInUse
{
    public static function checkIfDeletable(Action $action, Category $record): void
    {
        $reason = match (true) {
            ! $record->isDeletable() => 'The category still has records assigned to it.',
            $record->hasChildren() => 'The category still has child categories.',
            default => null,
        };

        if ($reason === null) {
            return;
        }

        Notification::make()
            ->danger()
            ->title('The record cannot be deleted!')
            ->body($reason)
            ->send();

        $action->cancel();
    }
}
