<?php

namespace App\Filament\Clusters\Settings\Resources\Filesystems\Pages;

use App\Enums\IconEnums;
use App\Filament\Clusters\Settings\Resources\Filesystems\FilesystemResource;
use App\Models\Filesystem;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditFilesystem extends EditRecord
{
    protected static string $resource = FilesystemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test-connection')
                ->label('Test connection')
                ->color('success')
                ->icon(IconEnums::CHECK->value)
                ->action(function (Filesystem $record) {
                    try {
                        $record->testConnection();

                        Notification::make()
                            ->success()
                            ->title('Connection test successful')
                            ->body('Successfuly connected to the filesystem and tested basic I/O operations.')
                            ->send();
                    } catch (Exception $e) {
                        Notification::make()
                            ->danger()
                            ->title('Connection test failed')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),
            Action::make('history')
                ->url(fn ($record) => FilesystemResource::getUrl('activities', ['record' => $record]))
                ->color('warning')
                ->icon(IconEnums::ACTIVITY->value),
            DeleteAction::make()
                ->hidden(fn (Filesystem $record): bool => $record->isBuiltIn()),
        ];
    }

    /**
     * The built-in public and private disks are read-only. Disabling their
     * form does not stop the fields from being saved, so saving is refused.
     */
    protected function beforeValidate(): void
    {
        if ($this->getRecord()->isBuiltIn()) {
            Notification::make()
                ->danger()
                ->title('The built-in disks cannot be reconfigured')
                ->send();

            $this->halt();
        }
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['root_path'] = '/'.trim($data['root_path'], '/');

        return $data;
    }
}
