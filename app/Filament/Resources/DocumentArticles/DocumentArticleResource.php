<?php

namespace App\Filament\Resources\DocumentArticles;

use App\Enums\IconEnums;
use App\Filament\Resources\DocumentArticles\Pages\CreateDocumentArticle;
use App\Filament\Resources\DocumentArticles\Pages\EditDocumentArticle;
use App\Filament\Resources\DocumentArticles\Pages\ListDocumentArticles;
use App\Filament\RichContentCustomBlocks\CaptionBlock;
use App\Filament\RichContentCustomBlocks\CodeSnippetBlock;
use App\Filament\RichContentCustomBlocks\DocumentVersionBlock;
use App\Filament\RichContentCustomBlocks\ErrorInfoboxBlock;
use App\Filament\RichContentCustomBlocks\InfoInfoboxBlock;
use App\Filament\RichContentCustomBlocks\SuccessInfoboxBlock;
use App\Filament\RichContentCustomBlocks\WarningInfoboxBlock;
use App\Models\DocumentArticle;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class DocumentArticleResource extends Resource
{
    protected static ?string $model = DocumentArticle::class;

    protected static string|\BackedEnum|null $navigationIcon = IconEnums::FILE_DOCUMENT->value;

    protected static ?string $navigationLabel = 'Documentation';

    protected static ?int $navigationSort = 30;

    protected static ?string $label = 'Articles';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make('Generated from the repository')
                    ->description(fn (?DocumentArticle $record): string => "This article is published from resources/docs/{$record?->source} by `php artisan docs:sync` on every deployment. Change it in the repository; edits made here would be overwritten.")
                    ->warning()
                    ->columnSpanFull()
                    ->visible(fn (?DocumentArticle $record): bool => (bool) $record?->isManaged()),
                Select::make('parent_id')
                    ->disabled(fn (?DocumentArticle $record): bool => (bool) $record?->isManaged())
                    ->label('Parent article')
                    ->options(fn (?DocumentArticle $record) => DocumentArticle::query()
                        ->whereNull('parent_id')
                        ->when($record?->id, fn (Builder $query) => $query->where('id', '!=', $record->id))
                        ->orderBy('position')
                        ->orderBy('title')
                        ->pluck('title', 'id'))
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('Only one nested level is supported.')
                    ->validationAttribute('parent article')
                    ->afterStateHydrated(function (?DocumentArticle $record, $state, $component): void {
                        if ($state && $record?->parent?->parent_id !== null) {
                            $component->state(null);
                        }
                    }),
                TextInput::make('title')
                    ->disabled(fn (?DocumentArticle $record): bool => (bool) $record?->isManaged())
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, $set, $get): void {
                        if (blank($get('slug')) && is_string($state)) {
                            $set('slug', str($state)->slug()->toString());
                        }
                    }),
                TextInput::make('slug')
                    ->disabled(fn (?DocumentArticle $record): bool => (bool) $record?->isManaged())
                    ->required()
                    ->maxLength(255)
                    ->rule('alpha_dash')
                    ->unique(
                        ignorable: fn (?DocumentArticle $record) => $record,
                        modifyRuleUsing: fn (Unique $rule, $get) => $rule->where(
                            'parent_id',
                            $get('parent_id') ?: null,
                        ),
                    )
                    ->helperText('Slug is unique within the same parent level.'),
                TextInput::make('position')
                    ->numeric()
                    ->default(0)
                    ->required(),
                Toggle::make('is_published')
                    ->default(true)
                    ->required(),
                RichEditor::make('content')
                    ->disabled(fn (?DocumentArticle $record): bool => (bool) $record?->isManaged())
                    ->columnSpanFull()
                    ->required()
                    ->toolbarButtons([
                        ['bold', 'italic', 'underline', 'strike', 'subscript', 'superscript', 'link', 'textColor'],
                        ['h2', 'h3'],
                        ['alignStart', 'alignCenter', 'alignEnd'],
                        ['blockquote', 'codeBlock', 'bulletList', 'orderedList'],
                        ['table', 'attachFiles', 'customBlocks'],
                        ['undo', 'redo'],
                    ])
                    ->floatingToolbars([
                        // 'paragraph' => [
                        //     'bold', 'italic', 'underline', 'strike', 'link', 'textColor', 'customBlocks',
                        // ],
                        'table' => [
                            'tableAddColumnBefore', 'tableAddColumnAfter', 'tableDeleteColumn',
                            'tableAddRowBefore', 'tableAddRowAfter', 'tableDeleteRow',
                            'tableMergeCells', 'tableSplitCell',
                            'tableToggleHeaderRow', 'tableToggleHeaderCell',
                            'tableDelete',
                        ],
                        'heading' => [
                            'h2', 'h3',
                        ],
                    ])
                    ->customBlocks([
                        'Article metadata' => [
                            DocumentVersionBlock::class,
                        ],
                        'Infoboxes' => [
                            InfoInfoboxBlock::class,
                            WarningInfoboxBlock::class,
                            ErrorInfoboxBlock::class,
                            SuccessInfoboxBlock::class,
                        ],
                        'Source code' => [
                            CodeSnippetBlock::class,
                        ],
                        'Media' => [
                            CaptionBlock::class,
                        ],
                    ])
                    ->resizableImages()
                    ->fileAttachmentsDirectory('documentation/attachments')
                    ->fileAttachmentsDisk('public')
                    ->fileAttachmentsVisibility('public')
                    ->fileAttachmentsAcceptedFileTypes(['image/png', 'image/jpeg'])
                    ->fileAttachmentsMaxSize(5120), // 5 MB,
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('parent.title')
                    ->label('Parent')
                    ->default('Top level')
                    ->badge()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable(),
                IconColumn::make('source')
                    ->label('From repository')
                    ->tooltip(fn (DocumentArticle $record): ?string => $record->source)
                    ->boolean()
                    ->getStateUsing(fn (DocumentArticle $record): bool => $record->isManaged()),
                TextColumn::make('position')
                    ->sortable()
                    ->alignCenter(),
                IconColumn::make('is_published')
                    ->boolean()
                    ->label('Published'),
                TextColumn::make('updated_at')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_published')
                    ->label('Published'),
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('parent');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentArticles::route('/'),
            'create' => CreateDocumentArticle::route('/create'),
            'edit' => EditDocumentArticle::route('/{record}/edit'),
        ];
    }
}
