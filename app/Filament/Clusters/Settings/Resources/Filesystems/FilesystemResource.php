<?php

namespace App\Filament\Clusters\Settings\Resources\Filesystems;

use App\Enums\IconEnums;
use App\Filament\Clusters\Settings\Resources\Filesystems\Pages\CreateFilesystem;
use App\Filament\Clusters\Settings\Resources\Filesystems\Pages\EditFilesystem;
use App\Filament\Clusters\Settings\Resources\Filesystems\Pages\FilesystemActivities;
use App\Filament\Clusters\Settings\Resources\Filesystems\Pages\ListFilesystems;
use App\Filament\Clusters\Settings\Resources\SshCredentials\SshCredentialResource;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\Filesystem;
use App\Models\SshCredential;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FilesystemResource extends Resource
{
    protected static ?string $model = Filesystem::class;

    protected static string|\BackedEnum|null $navigationIcon = IconEnums::SERVER->value;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Services';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components(self::getFormSchema())
            ->disabled(fn (?Filesystem $record) => $record && $record->type < 0);
    }

    public static function getFormSchema(): array
    {
        return [
            Select::make('type')
                ->label('Service type')
                ->options(Filesystem::types())
                ->disableOptionWhen(function ($value, ?Filesystem $record) {
                    $t = Filesystem::where('type', $value)->first();

                    return $t !== null && $t->id !== $record?->id;
                })
                ->hint('Each service type can only be added once.')
                ->hintColor('warning')
                ->columnSpanFull()
                ->required(),
            Select::make('ssh_credential_id')
                ->label('SSH Credential')
                ->searchable()
                ->preload()
                ->disabled(fn (Get $get) => $get('driver') == Filesystem::DRIVER_LOCAL)
                ->suffixAction(fn (Get $get) => $get('driver') == Filesystem::DRIVER_LOCAL ? null : Action::make('create')
                    ->label('New SSH Credential')
                    ->modal()
                    ->icon(IconEnums::ADD->value)
                    ->tooltip('Create a new SSH Credential')
                    ->authorize(fn (): bool => SshCredentialResource::canCreate())
                    ->form(SshCredentialResource::getFormSchema())
                    ->action(function (array $data, $set): void {
                        $credential = SshCredential::create($data);
                        $set('ssh_credential_id', $credential->id);
                    }))
                ->relationship('sshCredential', 'name'),
            TextInput::make('name')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            Textarea::make('description')
                ->rows(3)
                ->maxLength(255)
                ->columnSpanFull()
                ->nullable()
                ->hint('A brief description of the service.'),

            Section::make('Connection details')
                ->columnSpanFull()
                ->schema([
                    Select::make('scope_id')
                        ->relationship('scope', 'name', fn ($query) => $query->where('scope_id', null)->orderBy('id', 'asc'), true)
                        ->reactive()
                        ->afterStateUpdated(function ($set, ?string $state): void {
                            $record = Filesystem::find($state);
                            $set('driver', $record?->driver);
                            $set('host', $record?->host);
                            $set('port', $record?->port);
                        })
                        ->columnSpanFull(),
                    Group::make()
                        ->columnSpanFull()
                        ->columns(2)
                        ->reactive()
                        ->schema([
                            Select::make('driver')
                                ->options(Filesystem::drivers())
                                ->required()
                                ->disabled(fn (Get $get) => filled($get('scope_id')))
                                ->hint('Driver used by the service.'),
                            TextInput::make('host')
                                ->required(fn (Get $get) => $get('driver') !== Filesystem::DRIVER_LOCAL)
                                ->disabled(fn (Get $get) => filled($get('scope_id')))
                                ->maxLength(255),
                            TextInput::make('port')
                                ->required(fn (Get $get) => $get('driver') !== Filesystem::DRIVER_LOCAL)
                                ->numeric()
                                ->disabled(fn (Get $get) => filled($get('scope_id')))
                                ->default(22),
                            TextInput::make('root_path')
                                ->label('Root path')
                                ->default('~/')
                                ->prefix(fn (Get $get) => $get('scope_id') ? Filesystem::find($get('scope_id'))?->root_path : null)
                                ->required()
                                ->hint('The root path for this SSH connection.'),
                        ]),
                ])
                ->columns(2),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('isInitialized')
                    ->label('Initialized?')
                    ->alignCenter()
                    ->boolean(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->label('Name'),
                TextColumn::make('type')
                    ->label('Service Type')
                    ->formatStateUsing(fn (string $state): string => Filesystem::types()[$state] ?? $state)
                    ->sortable(),
                TextColumn::make('sshCredential.name')
                    ->label('SSH Credential')
                    ->sortable(),
                TextColumn::make('protocol')
                    ->sortable(),
                TextColumn::make('host')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
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
            'index' => ListFilesystems::route('/'),
            'create' => CreateFilesystem::route('/create'),
            'edit' => EditFilesystem::route('/{record}/edit'),
            'activities' => FilesystemActivities::route('/{record}/activities'),
        ];
    }
}
