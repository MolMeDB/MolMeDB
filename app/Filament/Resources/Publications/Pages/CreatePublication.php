<?php

namespace App\Filament\Resources\Publications\Pages;

use App\Filament\Resources\Publications\PublicationResource;
use App\Models\Author;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Modules\References\CrossRef\CrossRef;
use Modules\References\EuropePMC\Enums\Sources;
use Modules\References\EuropePMC\EuropePMC;

class CreatePublication extends CreateRecord
{
    protected static string $resource = PublicationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (isset($data['identifier']) && isset($data['identifier_source'])) {
            $service = new EuropePMC;

            // Find data on remote server and save all details
            $record = $service->detail($data['identifier'], Sources::tryFrom($data['identifier_source']));

            if ($record) {
                $data['title'] = $record->title;
                $data['journal'] = $record->journal?->title;
                $data['volume'] = $record->journal?->volume;
                $data['issue'] = $record->journal?->issue;
                $data['page'] = $record->pageInfo;
                $data['year'] = $record->journal?->yearOfPublication;
                $data['published_at'] = $record->publicationDate();
                $data['validated_at'] = now();
            } else {
                Notification::make()
                    ->title('Record not found on Europe PMC server.')
                    ->danger()
                    ->body('Please check the identifier and try again.')
                    ->send();
            }
        } elseif (isset($data['doi'])) {
            $service = new CrossRef;
            $record = $service->work($data['doi']);

            if ($record) {
                $data['title'] = $record->title;
                $data['journal'] = $record->journal?->title;
                $data['volume'] = $record->journal?->volume;
                $data['issue'] = $record->journal?->issue;
                $data['page'] = $record->pageInfo;
                $data['year'] = $record->journal?->yearOfPublication;
                $data['published_at'] = $record->publicationDate();
                $data['validated_at'] = now();
            } else {
                Notification::make()
                    ->title('Record not found on CrossRef server.')
                    ->danger()
                    ->body('Please check the DOI and try again.')
                    ->send();
            }
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $europePMC = new EuropePMC;
        $crossRef = new CrossRef;

        // Find data on remote server and save all details
        $record = null;

        if ($this->record->identifier && $this->record->identifier_source) {
            $record = $europePMC->detail($this->record->identifier, Sources::tryFrom($this->record->identifier_source));
        } elseif ($this->record->doi) {
            $record = $crossRef->work($this->record->doi);
        }

        if ($record) {
            foreach ($record->authors as $author) {
                // Add author if not exists
                $authorModel = Author::firstOrCreate([
                    'first_name' => $author->firstName,
                    'last_name' => $author->lastName,
                    'full_name' => $author->fullName,
                    'affiliation' => $author->affiliations && count($author->affiliations) ? $author->affiliations[0] : null,
                ]);

                $this->record->authors()->syncWithoutDetaching($authorModel->id);
            }
        }
    }
}
