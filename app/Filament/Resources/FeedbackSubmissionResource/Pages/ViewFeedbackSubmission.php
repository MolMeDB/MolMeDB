<?php

namespace App\Filament\Resources\FeedbackSubmissionResource\Pages;

use App\Filament\Resources\FeedbackSubmissionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewFeedbackSubmission extends ViewRecord
{
    protected static string $resource = FeedbackSubmissionResource::class;

    /**
     * Opening feedback marks it as read only for users who may change it,
     * like the list's mark-as-read action.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        if (FeedbackSubmissionResource::canEdit($this->getRecord())) {
            $this->getRecord()->markAsRead();
        }
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
