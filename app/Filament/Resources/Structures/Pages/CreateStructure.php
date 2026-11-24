<?php

namespace App\Filament\Resources\Structures\Pages;

use App\Filament\Resources\Structures\StructureResource;
use App\Libraries\Identifiers;
use App\Models\Structure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\Facades\Auth;
use Modules\Rdkit\Rdkit;
use Throwable;

use function Filament\Support\is_app_url;

class CreateStructure extends CreateRecord
{
    protected static string $resource = StructureResource::class;

    public $isValidated = false;

    /**
     * Add user id to data
     */
    protected function mutateFormDataBeforeCreate(array $formData): array
    {
        $data = $formData;
        $data['user_id'] = Auth::user()->id;

        // The entered SMILES is not a column; the canonical one is stored.
        unset($data['smiles']);

        return $data;
    }

    /**
     * After create, save identifiers
     */
    protected function afterCreate(array $data): void
    {
        $substance = $this->getRecord();
        // Add MMDB identifier
        $substance->update([
            'identifier' => Identifiers::generate($substance),
        ]);

        // Save other provided identifiers
        // $substance->identifiers()->create([
        //     'value' => $data['smiles'],
        //     'type'  => Identifier::TYPE_SMILES,
        //     'user_id' => Auth::user()->id,
        //     'is_active' => true,
        //     'state' => Identifier::STATE_VALIDATED
        // ]);

        // $substance->identifiers()->create([
        //     'value' => $data['inchikey'],
        //     'type'  => Identifier::TYPE_INCHIKEY,
        //     'user_id' => Auth::user()->id,
        //     'is_active' => true,
        //     'state' => Identifier::STATE_VALIDATED,
        //     'server' => Identifier::SERVER_RDKIT
        // ]);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('submit')
                ->databaseTransaction()
                ->formId('form')
                ->label(fn () => $this->isValidated ? 'Save' : 'Validate')
                ->color(fn () => $this->isValidated ? 'success' : 'warning')
                ->action(function () {
                    if ($this->isValidated) {
                        $this->create();

                        return;
                    }

                    $this->validateSmiles();
                }),
        ];
    }

    public function validateSmiles(): void
    {
        $states = $this->form->getState();
        $computed = $this->computedProperties($states['canonical_smiles'] ?? null);

        if ($computed === null) {
            return;
        }

        $this->form->fill([...$states, ...$computed]);

        $this->isValidated = true;
    }

    /**
     * What RDKit says about a SMILES, or null (with a notification) when it
     * cannot be stored: RDKit is out of reach or refuses it, or the structure
     * is already in the database.
     *
     * @return array{canonical_smiles: string, molecular_weight: float, logp: float, inchikey: string|null}|null
     */
    private function computedProperties(mixed $smiles): ?array
    {
        if (! is_string($smiles) || trim($smiles) === '') {
            return null;
        }

        // Connect RdKit
        $rdkit = new Rdkit;
        if (! $rdkit::is_connected()) {
            Notification::make()
                ->danger()
                ->title('Rdkit disconnected')
                ->body('Cannot establish connection to RdKit server. Please, try again.')
                ->send();

            return null;
        }

        // Validate SMILES
        $canonized = $rdkit->canonize_smiles($smiles);
        if (! $canonized) {
            Notification::make()
                ->danger()
                ->title('Invalid SMILES')
                ->body('SMILES cannot be canonized. Please, check your input and try again.')
                ->send();

            return null;
        }

        // Get general info
        $info = $rdkit->get_general_info($canonized);
        if (! $info) {
            Notification::make()
                ->danger()
                ->title('Unable to obtain molecule info.')
                ->body('Invalid response from the remote server. Please, try again.')
                ->send();

            return null;
        }

        $existing = Structure::withTrashed()->where('canonical_smiles', $canonized)->first();
        if ($existing) {
            Notification::make()
                ->danger()
                ->title('The structure already exists.')
                ->body('It is stored as '.($existing->identifier ?? 'structure #'.$existing->id).($existing->trashed() ? ' and is in the trash: restore it instead.' : '.'))
                ->send();

            return null;
        }

        return [
            'canonical_smiles' => $canonized,
            // Adding zero turns a negative zero into zero, which survives the
            // round trip through the Livewire payload.
            'molecular_weight' => round($info->mw, 2) + 0.0,
            'logp' => round($info->logp, 2) + 0.0,
            'inchikey' => $info->inchikey,
        ];
    }

    /**
     * Custom create function
     */
    public function create(bool $another = false): void
    {
        $this->authorizeAccess();

        try {
            $this->beginDatabaseTransaction();

            $this->callHook('beforeValidate');

            $data = $this->form->getState();

            // The form comes from the browser, together with the mark that
            // its SMILES was validated: what is stored is computed again.
            $computed = $this->computedProperties($data['canonical_smiles'] ?? null);

            if ($computed === null) {
                $this->isValidated = false;

                throw (new Halt)->rollBackDatabaseTransaction();
            }

            $data = [...$data, ...$computed];

            $this->callHook('afterValidate');

            $substance_data = $this->mutateFormDataBeforeCreate($data);

            $this->callHook('beforeCreate');

            $this->record = $this->handleRecordCreation($substance_data);

            $this->form->model($this->getRecord())->saveRelationships();

            $this->afterCreate($data);
            // $this->callHook('afterCreate');

            $this->commitDatabaseTransaction();
        } catch (Halt $exception) {
            $exception->shouldRollbackDatabaseTransaction() ?
                $this->rollBackDatabaseTransaction() :
                $this->commitDatabaseTransaction();

            return;
        } catch (Throwable $exception) {
            $this->rollBackDatabaseTransaction();

            throw $exception;
        }

        $this->rememberData();

        $this->getCreatedNotification()?->send();

        if ($another) {
            // Ensure that the form record is anonymized so that relationships aren't loaded.
            $this->form->model($this->getRecord()::class);
            $this->record = null;

            $this->fillForm();

            return;
        }

        $redirectUrl = $this->getRedirectUrl();

        $this->redirect($redirectUrl, navigate: FilamentView::hasSpaMode() && is_app_url($redirectUrl));
    }
}
