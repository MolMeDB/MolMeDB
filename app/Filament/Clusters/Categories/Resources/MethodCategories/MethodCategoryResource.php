<?php

namespace App\Filament\Clusters\Categories\Resources\MethodCategories;

use App\Enums\IconEnums;
use App\Filament\Clusters\Categories\CategoriesCluster;
use App\Filament\Clusters\Categories\Concerns\RefusesToDeleteCategoriesInUse;
use App\Filament\Clusters\Categories\Resources\MethodCategories\Pages\CreateMethodCategory;
use App\Filament\Clusters\Categories\Resources\MethodCategories\Pages\EditMethodCategory;
use App\Filament\Clusters\Categories\Resources\MethodCategories\Pages\ListMethodCategories;
use App\Filament\Clusters\Categories\Resources\MethodCategories\Pages\MethodCategoryTree;
use App\Filament\Clusters\Categories\Resources\MethodCategories\RelationManagers\MethodsRelationManager;
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

class MethodCategoryResource extends Resource
{
    use RefusesToDeleteCategoriesInUse;

    protected static ?string $model = Category::class;

    protected static ?string $navigationLabel = 'Method';

    protected static string|Htmlable|null $navigationBadgeTooltip = 'Manage method categories';

    protected static string|\BackedEnum|null $navigationIcon = IconEnums::METHOD->value;

    protected static ?string $cluster = CategoriesCluster::class;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', Category::TYPE_METHOD);
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::query()->where('type', Category::TYPE_METHOD)->count();
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
                    ->default(Category::TYPE_METHOD),

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
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('methods_count')
                    ->label('# Methods')
                    ->counts('methods')
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

            ])
            ->toolbarActions([
                BulkActionGroup::make([
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MethodsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMethodCategories::route('/'),
            'create' => CreateMethodCategory::route('/create'),
            'edit_record' => EditMethodCategory::route('/{record}/edit'),
            'categoryTree' => MethodCategoryTree::route('/manage'),
        ];
    }
}
