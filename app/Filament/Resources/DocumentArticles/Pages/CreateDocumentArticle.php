<?php

namespace App\Filament\Resources\DocumentArticles\Pages;

use App\Filament\Resources\DocumentArticles\DocumentArticleResource;
use App\Models\DocumentArticle;
use App\Services\Documentation\DocumentationPublisher;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateDocumentArticle extends CreateRecord
{
    protected static string $resource = DocumentArticleResource::class;

    /**
     * An article created from a source file gets its content right away.
     */
    protected function afterCreate(): void
    {
        if ($this->record->isManaged()) {
            app(DocumentationPublisher::class)->publishArticle($this->record);
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        if (! $parentId) {
            return $data;
        }

        $parent = DocumentArticle::query()->find($parentId);
        if ($parent?->parent_id !== null) {
            throw ValidationException::withMessages([
                'parent_id' => 'Only two levels of articles are supported.',
            ]);
        }

        return $data;
    }
}
