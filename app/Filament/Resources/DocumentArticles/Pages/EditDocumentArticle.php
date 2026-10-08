<?php

namespace App\Filament\Resources\DocumentArticles\Pages;

use App\Filament\Resources\DocumentArticles\DocumentArticleResource;
use App\Models\DocumentArticle;
use App\Services\Documentation\DocumentationPublisher;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditDocumentArticle extends EditRecord
{
    protected static string $resource = DocumentArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publishFromRepository')
                ->label('Publish from the repository')
                ->icon('heroicon-m-arrow-path')
                ->visible(fn (): bool => $this->record->isManaged())
                ->action(function (DocumentationPublisher $publisher): void {
                    $publisher->publishArticle($this->record);
                    $this->refreshFormData(['title', 'content', 'is_published', 'position']);

                    Notification::make()->title('Published from '.$this->record->source)->success()->send();
                }),
            DeleteAction::make(),
        ];
    }

    /**
     * An article switched to its source file gets its content right away.
     */
    protected function afterSave(): void
    {
        if ($this->record->isManaged()) {
            app(DocumentationPublisher::class)->publishArticle($this->record);
            $this->refreshFormData(['title', 'content', 'is_published', 'position']);
        }
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        if (! $parentId) {
            return $data;
        }

        $parent = DocumentArticle::query()->find($parentId);
        if (! $parent || $parent->id === $this->record->id || $parent->parent_id !== null) {
            throw ValidationException::withMessages([
                'parent_id' => 'Only two levels of articles are supported.',
            ]);
        }

        return $data;
    }
}
