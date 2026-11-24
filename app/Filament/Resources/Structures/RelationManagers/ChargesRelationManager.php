<?php

namespace App\Filament\Resources\Structures\RelationManagers;

use App\Enums\IconEnums;
use App\Filament\Resources\Structures\StructureResource;
use App\Models\Structure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ChargesRelationManager extends RelationManager
{
    protected static string $relationship = 'chargedChildren';

    protected static ?string $title = 'Related structures';

    protected static string|\BackedEnum|null $icon = IconEnums::CHARGED_MOL->value;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord::class == Structure::class && ! $ownerRecord->parent()->withTrashed()->first()?->id;
    }

    public function table(Table $table): Table
    {
        $isParentTrashed = $this->ownerRecord->trashed();

        return StructureResource::getDefaultTable($table)
            ->filters([
                TrashedFilter::make()
                    ->default($isParentTrashed ? 1 : null),
            ])
            ->headerActions([
                // Tables\Actions\CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make()
                    ->openUrlInNewTab()
                    ->url(fn (Structure $record) => StructureResource::getUrl('edit', ['record' => $record])),
                RestoreAction::make()
                    ->disabled(fn (Structure $record) => ! $record->isRestoreable()),
                // Tables\Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
