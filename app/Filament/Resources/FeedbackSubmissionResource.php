<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FeedbackSubmissionResource\Pages;
use App\Models\FeedbackSubmission;
use BackedEnum;
use Filament\Actions;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FeedbackSubmissionResource extends Resource
{
    protected static ?string $model = FeedbackSubmission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Feedbacks';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $modelLabel = 'Feedback';

    protected static ?string $pluralModelLabel = 'Feedback';

    public static function getNavigationBadge(): ?string
    {
        $count = FeedbackSubmission::query()
            ->where('state', FeedbackSubmission::STATE_NEW)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('email')
                    ->label('Email'),
                TextEntry::make('context')
                    ->label('Context')
                    ->columnSpanFull(),
                TextEntry::make('message')
                    ->label('Message')
                    ->columnSpanFull(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Feedback')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('email')
                            ->label('Email')
                            ->copyable(),
                        TextEntry::make('user.name')
                            ->label('User')
                            ->placeholder('Guest'),
                        TextEntry::make('context')
                            ->label('Context')
                            ->copyable()
                            ->columnSpanFull(),
                        TextEntry::make('message')
                            ->label('Message')
                            ->prose()
                            ->columnSpanFull(),
                    ]),
                Section::make('Request metadata')
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('ip_address')
                            ->label('IP address')
                            ->placeholder('-'),
                        TextEntry::make('created_at')
                            ->label('Submitted')
                            ->dateTime(),
                        TextEntry::make('user_agent')
                            ->label('User agent')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('state')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => FeedbackSubmission::states()[$state] ?? $state)
                    ->color(fn (string $state): string => $state === FeedbackSubmission::STATE_NEW ? 'warning' : 'success')
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('user.name')
                    ->label('User')
                    ->placeholder('Guest')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('context')
                    ->limit(45)
                    ->tooltip(fn (FeedbackSubmission $record): string => $record->context)
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('message')
                    ->limit(70)
                    ->tooltip(fn (FeedbackSubmission $record): string => $record->message)
                    ->searchable(),
                TextColumn::make('ip_address')
                    ->label('IP')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->options(FeedbackSubmission::states()),
                TernaryFilter::make('user_id')
                    ->label('Authenticated user')
                    ->placeholder('All feedback')
                    ->trueLabel('Authenticated only')
                    ->falseLabel('Guests only')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('user_id'),
                        false: fn (Builder $query): Builder => $query->whereNull('user_id'),
                    ),
            ])
            ->recordActions([
                Actions\Action::make('markAsRead')
                    ->label('Read')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->tooltip('Mark as read')
                    ->authorize('update')
                    ->hidden(fn (FeedbackSubmission $record): bool => $record->state === FeedbackSubmission::STATE_READ)
                    ->action(function (FeedbackSubmission $record): void {
                        $record->markAsRead();
                    }),
                Actions\ViewAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['user', 'emailVerification']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFeedbackSubmissions::route('/'),
            'view' => Pages\ViewFeedbackSubmission::route('/{record}'),
        ];
    }
}
