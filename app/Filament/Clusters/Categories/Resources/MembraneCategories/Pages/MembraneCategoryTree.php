<?php

namespace App\Filament\Clusters\Categories\Resources\MembraneCategories\Pages;

use App\Enums\IconEnums;
use App\Filament\Clusters\Categories\Concerns\RearrangesCategoryTreeWhenAllowed;
use App\Filament\Clusters\Categories\Resources\MembraneCategories\MembraneCategoryResource;
use App\Models\Category;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\FilamentTree\Actions;
use SolutionForest\FilamentTree\Actions\DeleteAction;
use SolutionForest\FilamentTree\Actions\EditAction;
use SolutionForest\FilamentTree\Actions\ViewAction;
use SolutionForest\FilamentTree\Resources\Pages\TreePage as BasePage;

class MembraneCategoryTree extends BasePage
{
    use RearrangesCategoryTreeWhenAllowed;

    protected static string $resource = MembraneCategoryResource::class;

    protected static int $maxDepth = 2;

    protected function getFormSchema(): array
    {
        return [
            TextInput::make('title'),
            Hidden::make('type')
                ->default(Category::TYPE_MEMBRANE),
        ];
    }

    /**
     * Set custom page title
     */
    public function getTitle(): string
    {
        return 'Manage membrane categories';
    }

    /**
     * Set custom breadcrumb label
     */
    public function getBreadcrumb(): ?string
    {
        return 'Membrane';
    }

    /**
     * Set record title in the tree
     */
    public function getTreeRecordTitle(?Model $record = null): string
    {
        if (! $record) {
            return '';
        }
        $title = $record->title;
        $parent = $record->parent?->title;
        $total_membranes = $record->membranes()->count();

        return ($parent ? "{$parent} >> " : '')."{$title}".($parent || $total_membranes ? " (# Membranes: {$total_membranes})" : '');
    }

    /**
     * Change default query to filter just membrane type
     */
    public function getTreeQuery(): Builder
    {
        return Category::query()->where('type', Category::TYPE_MEMBRANE);
    }

    /**
     * Hide this page in the main navigation
     */
    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return false;
    }

    /**
     * Add custom actions
     */
    protected function getActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make(),
        ];
    }

    /**
     * Adjust actions for each record
     */
    protected function getTreeActions(): array
    {
        return [
            EditAction::make()
                ->tooltip('Change category name'),
            ViewAction::make()
                ->icon(IconEnums::MEMBRANE->value)
                ->color('warning')
                ->tooltip('Manage assigned methods')
                ->modal(false)
                ->visible(fn (Category $record) => static::getResource()::canEdit($record))
                ->url(function (Category $record) {
                    return static::getResource()::getUrl('edit_record', ['record' => $record]);
                }),
            DeleteAction::make()
                ->tooltip('Delete category')
                ->before(fn (DeleteAction $action, Category $record) => MembraneCategoryResource::checkIfDeletable($action, $record)),
        ];
    }

    protected function hasDeleteAction(): bool
    {
        return true;
    }

    protected function hasEditAction(): bool
    {
        return true;
    }

    protected function hasViewAction(): bool
    {
        return true;
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    protected function getFooterWidgets(): array
    {
        return [];
    }

    // CUSTOMIZE ICON OF EACH RECORD, CAN DELETE
    // public function getTreeRecordIcon(?\Illuminate\Database\Eloquent\Model $record = null): ?string
    // {
    //     return null;
    // }
}
