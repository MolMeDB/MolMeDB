<?php

namespace App\Filament\Resources\SharedRelationManagers;

use App\Enums\IconEnums;
use App\Filament\Resources\InteractionActives\InteractionActiveResource;
use App\Filament\Resources\Structures\StructureResource;
use App\Models\Category;
use App\Models\Dataset;
use App\Models\InteractionActive;
use App\Models\Membrane;
use App\Models\Method;
use App\Models\Protein;
use App\Models\Publication;
use App\Models\Structure;
use App\Models\UploadQueue;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class InteractionsActiveRelationManager extends RelationManager
{
    protected static string $relationship = 'interactionsActive';

    protected static ?string $title = 'A. interactions';

    protected static string|\BackedEnum|null $icon = IconEnums::INTERACTIONS->value;

    private function getTableDescriptions(): string
    {
        $deletedParent = method_exists($this->ownerRecord, 'trashed') && $this->ownerRecord->trashed();

        return match ($this->ownerRecord::class) {
            Structure::class => 'Active interactions assigned to the structure.',
            Dataset::class => 'Active interactions originating from the dataset.',
            Protein::class => 'Active interactions assigned to the protein.',
            UploadQueue::class => 'Active interactions imported from this upload.',
            Method::class => $deletedParent ? 'Warning! Interactions labeled as "deleted" are hidden. Restore this record to see all assigned interaction.' : 'Active interactions assigned to the method.',
            Membrane::class => $deletedParent ? 'Warning! Interactions labeled as "deleted" are hidden. Restore this record to see all assigned interaction.' : 'Active interactions assigned to the membrane.',
            Publication::class => 'Interactions with current record as PRIMARY reference.',
            Category::class => 'Interactions of this type/category.',
            default => ''
        };
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if ($ownerRecord::class == Dataset::class) {
            return match ($ownerRecord->type) {
                Dataset::TYPE_ACTIVE => parent::canViewForRecord($ownerRecord, $pageClass),
                default => false
            };
        }

        if ($ownerRecord::class == UploadQueue::class) {
            return match ($ownerRecord->type) {
                UploadQueue::TYPE_ACTIVE_DATASET => parent::canViewForRecord($ownerRecord, $pageClass),
                default => false
            };
        }

        return true;
    }

    public function table(Table $table): Table
    {
        $isParentTrashed = method_exists($this->ownerRecord, 'trashed') && $this->ownerRecord->trashed();

        return InteractionActiveResource::table($table)
            ->description($this->getTableDescriptions())
            ->query(null)
            ->filters([
                TrashedFilter::make()
                    ->default($isParentTrashed ? 1 : null),
            ])
            ->recordActions([
                ...($this->ownerRecord::class === Structure::class ? [] : [
                    Action::make('compound_detail')
                        ->label('Structure')
                        ->icon(IconEnums::VIEW->value)
                        ->url(fn ($record) => StructureResource::getUrl('edit', ['record' => $record->structure])),
                ]),
                EditAction::make()
                    ->color('warning')
                    ->url(fn ($record) => InteractionActiveResource::getUrl('edit', ['record' => $record])),
                RestoreAction::make()
                    ->disabled(fn (InteractionActive $record) => ! $record->isRestoreable()),
            ]);
    }
}
