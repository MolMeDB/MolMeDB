<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationTemplateResource\Pages;
use App\Models\NotificationTemplate;
use BackedEnum;
use Filament\Actions;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

class NotificationTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $navigationLabel = 'Notification templates';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Template')
                    ->columns(2)
                    ->schema([
                        Select::make('key')
                            ->required()
                            ->options(fn (?NotificationTemplate $record): array => NotificationTemplate::availableKeyOptions($record))
                            ->rule(Rule::in(array_keys(NotificationTemplate::keyOptions())))
                            ->unique(ignoreRecord: true)
                            ->live()
                            ->afterStateUpdated(function (?string $state, callable $set): void {
                                $set('name', NotificationTemplate::keyOptions()[$state] ?? null);
                            })
                            ->disabledOn('edit')
                            ->helperText('Template keys are defined by the application and can be selected only once.'),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->readOnly()
                            ->dehydrated()
                            ->afterStateHydrated(function (TextInput $component, ?NotificationTemplate $record): void {
                                if ($record?->key) {
                                    $component->state(NotificationTemplate::keyOptions()[$record->key] ?? $record->name);
                                }
                            }),
                        Toggle::make('is_active')
                            ->default(true)
                            ->required(),
                    ]),
                Section::make('In-app notification')
                    ->schema([
                        TextInput::make('notification_title')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Variables use {{ variable }} syntax.'),
                        Textarea::make('notification_body')
                            ->required()
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
                Section::make('Email')
                    ->columnSpanFull()
                    ->description('Email is sent only when both subject and message are filled.')
                    ->schema([
                        TextInput::make('email_subject')
                            ->maxLength(255)
                            ->nullable(),
                        RichEditor::make('email_message')
                            ->nullable()
                            ->helperText('HTML is allowed. Variables use {{ variable }} syntax.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active')
                    ->sortable(),
                TextColumn::make('notifications_count')
                    ->counts('notifications')
                    ->label('Sent')
                    ->badge()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                Actions\EditAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationTemplates::route('/'),
            'create' => Pages\CreateNotificationTemplate::route('/create'),
            'edit' => Pages\EditNotificationTemplate::route('/{record}/edit'),
        ];
    }
}
