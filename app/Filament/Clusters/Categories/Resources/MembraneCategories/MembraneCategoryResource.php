<?php

namespace App\Filament\Clusters\Categories\Resources\MembraneCategories;

use App\Enums\IconEnums;
use App\Filament\Clusters\Categories\CategoriesCluster;
use App\Filament\Clusters\Categories\Concerns\RefusesToDeleteCategoriesInUse;
use App\Filament\Clusters\Categories\Resources\MembraneCategories\Pages\CreateMembraneCategory;
use App\Filament\Clusters\Categories\Resources\MembraneCategories\Pages\EditMembraneCategory;
use App\Filament\Clusters\Categories\Resources\MembraneCategories\Pages\ListMembraneCategories;
use App\Filament\Clusters\Categories\Resources\MembraneCategories\Pages\MembraneCategoryTree;
use App\Filament\Clusters\Categories\Resources\MembraneCategories\RelationManagers\MembranesRelationManager;
use App\Models\Category;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class MembraneCategoryResource extends Resource
{
    use RefusesToDeleteCategoriesInUse;

    protected static ?string $model = Category::class;

    protected static ?string $navigationLabel = 'Membrane';

    protected static string|Htmlable|null $navigationBadgeTooltip = 'Manage membrane categories';

    protected static string|\BackedEnum|null $navigationIcon = IconEnums::MEMBRANE->value;

    protected static ?string $cluster = CategoriesCluster::class;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', Category::TYPE_MEMBRANE);
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::query()->where('type', Category::TYPE_MEMBRANE)->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return self::getNavigationBadge() > 0 ? 'primary' : 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->hint('Maximum 255 characters.')
                    ->columnSpanFull()
                    ->maxLength(255),
                Hidden::make('type')
                    ->default(Category::TYPE_MEMBRANE),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('parent.title')
                    ->badge()
                    ->color('warning')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('membranes_count')
                    ->label('# Membranes')
                    ->counts('membranes')
                    ->badge()
                    ->alignCenter()
                    ->colors([
                        'danger' => fn ($state) => $state === 0,
                        'success' => fn ($state) => $state > 0,
                    ])
                    ->sortable(),
                TextColumn::make('children_count')
                    ->label('# Children')
                    ->counts('children')
                    ->badge()
                    ->alignCenter()
                    ->color('primary')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                // Tables\Actions\ViewAction::make()
                //     ->modalWidth('7xl')
                //     ->modalContent(function (Category $record) {
                //        return view('filament.clusters.category.pages.membrane', [
                //            'record' => $record,
                //            'relationManagers' => self::getRelations()
                //        ]);
                //     }),
                // Tables\Actions\DeleteAction::make()
                //     ->before(fn (Tables\Actions\DeleteAction $action, Category $record) => self::checkIfDeletable($action, $record))
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MembranesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembraneCategories::route('/'),
            'create' => CreateMembraneCategory::route('/create'),
            'edit_record' => EditMembraneCategory::route('/{record}/edit'),
            'categoryTree' => MembraneCategoryTree::route('/manage'),
        ];
    }
}
