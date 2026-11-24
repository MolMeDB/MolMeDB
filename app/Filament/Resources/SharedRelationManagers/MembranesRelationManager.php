<?php

namespace App\Filament\Resources\SharedRelationManagers;

use App\Enums\IconEnums;
use App\Filament\Resources\Membranes\MembraneResource;
use App\Models\Membrane;
use App\Models\Publication;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class MembranesRelationManager extends RelationManager
{
    protected static string $relationship = 'membranes';

    protected static string|\BackedEnum|null $icon = IconEnums::MEMBRANE->value;

    public function form(Schema $schema): Schema
    {
        return MembraneResource::form($schema);
    }

    public function table(Table $table): Table
    {
        $isParentTrashed = $this->ownerRecord->trashed();

        return MembraneResource::table($table)
            ->description($this->getTableDescriptions())
            ->query(null)
            ->filters([
                TrashedFilter::make()
                    ->default($isParentTrashed ? 1 : null),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->url(fn (Membrane $record) => MembraneResource::getUrl('edit', ['record' => $record]))
                    ->icon(IconEnums::NEWTAB->value)
                    ->openUrlInNewTab(),
            ]);
    }

    private function getTableDescriptions(): string
    {
        return match ($this->ownerRecord::class) {
            Publication::class => 'Membranes originating from the publication.',
            default => 'Attached membranes.'
        };
    }
}
