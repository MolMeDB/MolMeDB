<?php

namespace App\Filament\Clusters\Categories\Resources\ProteinCategories\Pages;

use App\Enums\IconEnums;
use App\Filament\Clusters\Categories\Concerns\RearrangesCategoryTreeWhenAllowed;
use App\Filament\Clusters\Categories\Resources\ProteinCategories\ProteinCategoryResource;
use App\Models\Category;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\FilamentTree\Actions;
use SolutionForest\FilamentTree\Actions\DeleteAction;
use SolutionForest\FilamentTree\Actions\EditAction;
use SolutionForest\FilamentTree\Actions\ViewAction;
use SolutionForest\FilamentTree\Resources\Pages\TreePage as BasePage;

class ProteinCategoryTree extends BasePage
{
    use RearrangesCategoryTreeWhenAllowed;

    protected static string $resource = ProteinCategoryResource::class;
    // protected static ?string $cluster = Categories::class;

    protected static int $maxDepth = 5;

    protected function getFormSchema(): array
    {
        return [
            TextInput::make('title'),
            Hidden::make('type')
                ->default(Category::TYPE_METHOD),
        ];
    }

    /**
     * Set custom page title
     */
    public function getTitle(): string
    {
        return 'Manage protein categories';
    }

    /**
     * Set custom breadcrumb label
     */
    public function getBreadcrumb(): ?string
    {
        return 'Protein';
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
        $total_proteins = $record->proteins()->count();

        return ($parent ? "{$parent} >> " : '')."{$title}".($parent || $total_proteins ? " (# Proteins: {$total_proteins})" : '');
    }

    /**
     * Change default query to filter just membrane type
     */
    public function getTreeQuery(): Builder
    {
        return Category::query()->where('type', Category::TYPE_PROTEIN);
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
            CreateAction::make(),
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
                ->icon(IconEnums::PROTEIN->value)
                ->color('warning')
                ->tooltip('Manage assigned proteins')
                ->visible(fn (Category $record) => static::getResource()::canEdit($record))
                ->modal(false)
                ->url(function (Category $record) {
                    return static::getResource()::getUrl('edit', ['record' => $record]);
                }),
            DeleteAction::make()
                ->tooltip('Delete category')
                ->before(fn (DeleteAction $action, Category $record) => ProteinCategoryResource::checkIfDeletable($action, $record)),
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
