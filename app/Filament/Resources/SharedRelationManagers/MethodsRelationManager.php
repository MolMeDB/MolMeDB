<?php

namespace App\Filament\Resources\SharedRelationManagers;

use App\Enums\IconEnums;
use App\Filament\Resources\Methods\MethodResource;
use App\Models\Method;
use App\Models\Publication;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class MethodsRelationManager extends RelationManager
{
    protected static string $relationship = 'methods';

    protected static string|\BackedEnum|null $icon = IconEnums::METHOD->value;

    public function form(Schema $schema): Schema
    {
        return MethodResource::form($schema);
    }

    public function table(Table $table): Table
    {
        $isParentTrashed = $this->ownerRecord->trashed();

        return MethodResource::table($table)
            ->description($this->getTableDescriptions())
            ->query(null)
            ->filters([
                TrashedFilter::make()
                    ->default($isParentTrashed ? 1 : null),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->url(fn (Method $record) => MethodResource::getUrl('edit', ['record' => $record]))
                    ->icon(IconEnums::NEWTAB->value)
                    ->openUrlInNewTab(),
            ]);
    }

    private function getTableDescriptions(): string
    {
        return match ($this->ownerRecord::class) {
            Publication::class => 'Methods originating from the publication.',
            default => 'Attached methods.'
        };
    }
}
