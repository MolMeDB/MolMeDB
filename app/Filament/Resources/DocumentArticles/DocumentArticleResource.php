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
use App\Services\Documentation\DocumentationPublisher;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Utilities\Get;
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
                Toggle::make('synced_from_source')
                    ->label('Publish from the repository')
                    ->helperText('The title and content come from a Markdown file in the repository and are published by `php artisan docs:sync` on every deployment. Turn it off to edit the article here instead.')
                    ->live()
                    ->columnSpanFull(),
                TextInput::make('source')
                    ->label('Source file')
                    ->placeholder('rest/mcp.md.blade.php')
                    ->helperText('Path in resources/docs, or the URL of the file in the repository.')
                    ->datalist(fn (): array => app(DocumentationPublisher::class)->paths())
                    ->dehydrateStateUsing(fn (?string $state): ?string => self::sourcePath($state))
                    ->required(fn (Get $get): bool => (bool) $get('synced_from_source'))
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (filled($value) && ! in_array(self::sourcePath($value), app(DocumentationPublisher::class)->paths(), true)) {
                            $fail('There is no such file in resources/docs.');
                        }
                    })
                    ->unique(ignoreRecord: true)
                    ->hintAction(
                        Action::make('openSource')
                            ->label('Open in the repository')
                            ->icon('heroicon-m-arrow-top-right-on-square')
                            ->url(fn (?DocumentArticle $record): ?string => $record?->sourceUrl(), shouldOpenInNewTab: true)
                            ->visible(fn (?DocumentArticle $record): bool => $record?->source !== null),
                    )
                    ->columnSpanFull(),
                Callout::make('Published from the repository')
                    ->description('Changes of the title and content belong to the file in the repository; they are published on the next deployment, or now with "Publish from the repository" above.')
                    ->info()
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => (bool) $get('synced_from_source')),
                Select::make('parent_id')
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
                    ->disabled(fn (Get $get, ?DocumentArticle $record): bool => $record !== null && (bool) $get('synced_from_source'))
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, $set, $get): void {
                        if (blank($get('slug')) && is_string($state)) {
                            $set('slug', str($state)->slug()->toString());
                        }
                    }),
                TextInput::make('slug')
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
                    ->disabled(fn (Get $get): bool => (bool) $get('synced_from_source'))
                    ->columnSpanFull()
                    ->required(fn (Get $get): bool => ! $get('synced_from_source'))
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

    /**
     * Path of a source file in resources/docs, also from its URL in the repository.
     */
    public static function sourcePath(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return str_contains($value, '/resources/docs/')
            ? strtok(substr($value, strpos($value, '/resources/docs/') + strlen('/resources/docs/')), '?#')
            : ltrim($value, '/');
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
                IconColumn::make('synced_from_source')
                    ->label('From repository')
                    ->tooltip(fn (DocumentArticle $record): ?string => $record->source)
                    ->boolean(),
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
