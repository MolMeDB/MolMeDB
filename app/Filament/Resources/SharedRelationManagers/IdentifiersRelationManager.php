<?php

namespace App\Filament\Resources\SharedRelationManagers;

use App\Enums\IconEnums;
use App\Filament\Resources\Structures\StructureResource;
use App\Models\Dataset;
use App\Models\Identifier;
use App\Models\Structure;
use App\Models\User;
use App\Rules\SubstanceIdentifier as RulesIdentifier;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class IdentifiersRelationManager extends RelationManager
{
    protected static string $relationship = 'identifiers';

    protected static string|\BackedEnum|null $icon = IconEnums::IDENTIFIERS->value;

    protected static ?string $title = 'Identifiers';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('value')
                    ->required()
                    ->hint('The value will be validated before saving if possible.')
                    ->columnSpanFull()
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                            $rule = new RulesIdentifier($this->ownerRecord, $get('type'));
                            $rule->validate($attribute, $value, $fail);
                        },

                    ])
                    ->maxLength(255),
                Select::make('type')
                    ->required()
                    ->columnSpanFull()
                    ->options(Identifier::types()),
                Select::make('state_visible')
                    ->hint('Remember, no additional validation is provided after saving.')
                    ->columnSpanFull()
                    ->hiddenOn('edit')
                    ->options(Identifier::states())
                    ->default(Identifier::STATE_VALIDATED)
                    ->disabled(),
                Hidden::make('source_id')
                    ->default(Auth::user()->id),
                Hidden::make('source_type')
                    ->default(User::class),
                Hidden::make('state')
                    ->default(Identifier::STATE_VALIDATED),
            ]);
    }

    public function table(Table $table): Table
    {
        $isParentTrashed = $this->ownerRecord->trashed();

        return $table
            ->recordTitleAttribute('value')
            ->description(fn (): ?string => $this->getDescription())
            // ->query(null)
            ->columns([
                TextColumn::make('id')
                    ->color(fn (Identifier $record) => $record->trashed() ? 'danger' : null)
                    ->tooltip(fn (Identifier $record) => $record->trashed() ? 'Deleted record' : null)
                    ->sortable(),
                TextColumn::make('structure.identifier')
                    ->label('Structure')
                    ->sortable()
                    ->visible(fn (): bool => ! $this->isSourceTypeOwner())
                    ->color('warning'),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Identifier::enumType($state))
                    ->sortable()
                    ->color('primary'),
                TextColumn::make('value')
                    ->wrap()
                    ->searchable()
                    ->sortable()
                    ->columnSpan(2),
                TextColumn::make('source')
                    ->badge()
                    ->label('Source')
                    ->sortable()
                    ->wrap()
                    ->tooltip('The source of the identifier.')
                    ->formatStateUsing(fn (Identifier $record): string => Str::limit($record->source?->name() ?? 'System', 20))
                    ->visible(fn (): bool => ! $this->isSourceTypeOwner())
                    ->color('success')
                    ->toggleable(isToggledHiddenByDefault: false),
                IconColumn::make('state')
                    ->alignCenter()
                    ->label('State')
                    ->sortable()
                    ->icon(fn (?string $state): string => match ($state) {
                        strval(Identifier::STATE_NEW) => IconEnums::STATE_NEW->value,
                        strval(Identifier::STATE_VALIDATED) => IconEnums::STATE_VALIDATED->value,
                        strval(Identifier::STATE_INVALID) => IconEnums::STATE_INVALID->value,
                        strval(Identifier::STATE_ACTIVE) => IconEnums::STATE_ACTIVE->value,
                        strval(Identifier::STATE_OBSOLETE) => IconEnums::STATE_OBSOLETE->value,
                        default => IconEnums::QUESTION_MARK->value,
                    })
                    ->tooltip(fn (?string $state): string => match ($state) {
                        strval(Identifier::STATE_NEW) => 'Waiting for validation',
                        strval(Identifier::STATE_VALIDATED) => 'Validated',
                        strval(Identifier::STATE_INVALID) => 'Invalid identifier',
                        strval(Identifier::STATE_ACTIVE) => 'Primary',
                        strval(Identifier::STATE_OBSOLETE) => 'Obsolete',
                        default => 'Unknown state',
                    }),
                TextColumn::make('created_at')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make()
                    ->default($isParentTrashed ? 1 : null),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->createButtonVisible()),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Identifier $record): bool => $record->source_type == User::class),
                DeleteAction::make()
                    ->visible(fn (Identifier $record): bool => $record->source_type == User::class),
                Action::make('compound_detail')
                    ->label('Structure')
                    ->icon(IconEnums::VIEW->value)
                    ->url(fn ($record) => StructureResource::getUrl('edit', ['record' => $record->structure]))
                    ->visible(fn (): bool => $this->isSourceTypeOwner()),
                Action::make('activate')
                    ->label('Set as primary')
                    ->icon(IconEnums::CHECK->value)
                    ->action(fn (Identifier $record) => $record->activate())
                    ->visible(fn (Identifier $record): bool => ! $this->isSourceTypeOwner()
                        && $record->type == Identifier::TYPE_NAME
                        && $record->state !== Identifier::STATE_ACTIVE),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private function createButtonVisible(): bool
    {
        return ! in_array($this->ownerRecord::class, [
            Dataset::class,
        ]);
    }

    private function isSourceTypeOwner(): bool
    {
        return ! in_array($this->ownerRecord::class, [
            Structure::class,
        ]);
    }

    private function getDescription(): ?string
    {
        return match ($this->ownerRecord::class) {
            Dataset::class => 'Structure identifiers added from current dataset',
            default => null
        };
    }
}
