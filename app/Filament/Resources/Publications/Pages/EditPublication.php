<?php

namespace App\Filament\Resources\Publications\Pages;

use App\Enums\IconEnums;
use App\Filament\Resources\Publications\PublicationResource;
use App\Models\Author;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\References\CrossRef\CrossRef;
use Modules\References\EuropePMC\Enums\Sources;
use Modules\References\EuropePMC\EuropePMC;

class EditPublication extends EditRecord
{
    protected static string $resource = PublicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->icon(IconEnums::DELETE->value)
                ->modalHeading('Delete publication?')
                ->modalDescription('This action will delete all associated datasets and interactions.')
                ->modalSubmitActionLabel('Understand. Delete'),
            ForceDeleteAction::make()
                ->icon(IconEnums::DELETE->value)
                ->modalHeading('Force delete publication?')
                ->modalDescription('This action will permanently delete all associated datasets and interactions. This action is irreversible.')
                ->modalSubmitActionLabel('Understand. Delete'),
            RestoreAction::make()
                ->icon(IconEnums::RESTORE->value)
                ->modalHeading('Restore publication?')
                ->modalDescription('Do you want to restore the publication record? This step will 
                    restore all related interactions and datasets.'),
        ];
    }

    public function getTitle(): string
    {
        return ($this->record->trashed() ? '(DELETED) ' : '').
            'Edit publication [ID:'.$this->record->id.']'.
            (! $this->record->validated_at ? ' - not validated' : '');
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['title'] = null;
        $data['journal'] = null;
        $data['volume'] = null;
        $data['issue'] = null;
        $data['page'] = null;
        $data['year'] = null;
        $data['published_at'] = null;

        // unlink authors
        $this->record->authors()->detach();

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

                // Add authors
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
            } else {
                $data['identifier'] = null;
                $data['identifier_source'] = null;
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
}
