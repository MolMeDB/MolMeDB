<?php

namespace App\Jobs;

use App\Libraries\Export\ExportFileHeader;
use App\Libraries\Export\ExportToFile;
use App\Models\DownloadQueue;
use App\Models\Filesystem;
use App\Models\InteractionActive;
use App\Models\InteractionPassive;
use App\Models\NotificationTemplate;
use App\Models\Publication;
use App\Models\Structure;
use App\Services\DownloaderFilterService;
use App\Services\NotificationService;
use App\Services\SystemActivityLogger;
use Generator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class ProcessDownloadQueueExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 3;

    private const PROGRESS_UPDATE_EVERY_ROWS = 250;

    public string $runToken;

    public function __construct(
        public DownloadQueue $download,
        ?string $runToken = null,
    ) {
        $this->runToken = $runToken ?? (string) Str::uuid();
    }

    public function handle(
        DownloaderFilterService $filters,
        SystemActivityLogger $activityLogger,
        NotificationService $notificationService,
    ): void {
        $this->download->refresh();

        if ($this->download->state === DownloadQueue::STATE_DONE
            || ! $this->download->beginProcessing($this->runToken)) {
            return;
        }

        if ($this->download->isExpired()) {
            $this->download->failProcessing(
                $this->runToken,
                'Download request expired before processing started.',
            );

            $this->notifyFailure($notificationService, 'Download request expired before processing started.');

            return;
        }

        try {
            $selection = $this->download->selection ?? [];
            $membraneIds = $selection['membrane_ids'] ?? [];
            $methodIds = $selection['method_ids'] ?? [];
            $proteinIds = $selection['protein_ids'] ?? [];
            $structureIdentifiers = $selection['structure_identifiers'] ?? [];

            $filesystem = Filesystem::where('type', Filesystem::TYPE_EXPORTS)->first();

            if (! $filesystem || ! $filesystem->isInitialized()) {
                throw new RuntimeException('Export filesystem is not configured.');
            }

            $passiveQuery = $filters->passiveQuery($membraneIds, $methodIds, $structureIdentifiers);
            $activeQuery = $filters->activeQuery($structureIdentifiers, $proteinIds);

            $total = (clone $passiveQuery)->count() + (clone $activeQuery)->count();
            $processed = 0;
            if (! $this->download->updateProgress($processed, $total, $this->runToken)) {
                return;
            }

            $folder = $this->download->uuid;

            $passiveExport = new ExportToFile(ExportToFile::CONTEXT_DOWNLOADER, 'passive_interactions', $folder, ExportToFile::TYPE_CSV, $filesystem);
            $passiveExport->setHeader(ExportFileHeader::make()->structure()->passiveInteraction())->writeHeader();

            foreach ($this->passiveRows($passiveQuery) as $row) {
                $passiveExport->writeRow($row);
                $processed++;

                if ($processed % self::PROGRESS_UPDATE_EVERY_ROWS === 0) {
                    if (! $this->download->updateProgress($processed, $total, $this->runToken)) {
                        return;
                    }
                }
            }

            $passiveExport->closeFile();

            $activeExport = new ExportToFile(ExportToFile::CONTEXT_DOWNLOADER, 'active_interactions', $folder, ExportToFile::TYPE_CSV, $filesystem);
            $activeExport->setHeader(ExportFileHeader::make()->structure()->activeInteraction())->writeHeader();

            foreach ($this->activeRows($activeQuery) as $row) {
                $activeExport->writeRow($row);
                $processed++;

                if ($processed % self::PROGRESS_UPDATE_EVERY_ROWS === 0) {
                    if (! $this->download->updateProgress($processed, $total, $this->runToken)) {
                        return;
                    }
                }
            }

            $activeExport->closeFile();

            if (! $this->download->updateProgress($total, $total, $this->runToken)) {
                return;
            }

            $disk = Storage::disk($filesystem->systemName);
            $zipPath = 'downloader/'.$folder.'/export.zip';
            $zip = new ZipArchive;

            if ($zip->open($disk->path($zipPath), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create export ZIP archive.');
            }

            $zip->addFile($disk->path('downloader/'.$folder.'/passive_interactions.csv'), 'passive_interactions.csv');
            $zip->addFile($disk->path('downloader/'.$folder.'/active_interactions.csv'), 'active_interactions.csv');
            $zip->close();

            $disk->delete('downloader/'.$folder.'/passive_interactions.csv');
            $disk->delete('downloader/'.$folder.'/active_interactions.csv');

            if ($this->download->completeProcessing($this->runToken, $zipPath)) {
                $activityLogger->logThrottled(
                    event: 'download_export_completed',
                    description: 'Download export worker completed an export successfully.',
                    properties: [
                        'download_id' => $this->download->getKey(),
                        'processed_rows' => $total,
                    ],
                    severity: SystemActivityLogger::SEVERITY_INFO,
                    throttleKey: 'download-export-completed',
                    seconds: 300,
                );

                $this->notifyReady($notificationService);
            }
        } catch (Throwable $exception) {
            $this->download->failProcessing($this->runToken, $exception->getMessage());

            $this->notifyFailure($notificationService, $exception->getMessage());

            throw $exception;
        }
    }

    private function notifyReady(NotificationService $notificationService): void
    {
        $user = $this->download->user;

        if (! $user) {
            return;
        }

        $notificationService->send($user, NotificationTemplate::KEY_EXPORT_READY, [
            'manage_url' => $this->manageUrl(),
        ]);
    }

    private function notifyFailure(NotificationService $notificationService, string $errorMessage): void
    {
        $user = $this->download->user;

        if (! $user) {
            return;
        }

        $notificationService->send($user, NotificationTemplate::KEY_EXPORT_FAILED, [
            'error_message' => $errorMessage,
            'manage_url' => $this->manageUrl(),
        ]);
    }

    private function manageUrl(): string
    {
        $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return "{$frontendUrl}/downloader?uuid={$this->download->uuid}";
    }

    /**
     * @param  Builder<InteractionPassive>  $query
     */
    protected function passiveRows($query): Generator
    {
        foreach ($query
            ->with(['dataset.membrane', 'dataset.method', 'dataset.publications', 'publication', 'structure'])
            ->lazyById(200, 'id') as $interaction) {
            $secondaryPublication = $interaction->dataset?->publications
                ?->first(fn (Publication $publication): bool => $publication->id !== $interaction->publication_id);

            yield (object) array_merge($this->structureFields($interaction->structure), [
                'membrane' => $interaction->dataset?->membrane?->abbreviation,
                'method' => $interaction->dataset?->method?->abbreviation,
                'temperature' => $interaction->temperature,
                'ph' => $interaction->ph,
                'charge' => $interaction->charge,
                'note' => $interaction->note,
                'x_min' => $interaction->x_min,
                'x_min_accuracy' => $interaction->x_min_accuracy,
                'gpen' => $interaction->gpen,
                'gpen_accuracy' => $interaction->gpen_accuracy,
                'gwat' => $interaction->gwat,
                'gwat_accuracy' => $interaction->gwat_accuracy,
                'logk' => $interaction->logk,
                'logk_accuracy' => $interaction->logk_accuracy,
                'logperm' => $interaction->logperm,
                'logperm_accuracy' => $interaction->logperm_accuracy,
            ],
                ExportToFile::publicationFields('primary', $interaction->publication),
                ExportToFile::publicationFields('secondary', $secondaryPublication),
            );
        }
    }

    /**
     * @param  Builder<InteractionActive>  $query
     */
    protected function activeRows($query): Generator
    {
        foreach ($query
            ->with(['dataset.publications', 'publication', 'protein', 'structure'])
            ->lazyById(200, 'id') as $interaction) {
            $secondaryPublication = $interaction->dataset?->publications
                ?->first(fn (Publication $publication): bool => $publication->id !== $interaction->publication_id);

            yield (object) array_merge($this->structureFields($interaction->structure), [
                'protein' => $interaction->protein?->uniprot_id,
                'temperature' => $interaction->temperature,
                'ph' => $interaction->ph,
                'charge' => $interaction->charge,
                'note' => $interaction->note,
                'km' => $interaction->km,
                'km_accuracy' => $interaction->km_accuracy,
                'ec50' => $interaction->ec50,
                'ec50_accuracy' => $interaction->ec50_accuracy,
                'ki' => $interaction->ki,
                'ki_accuracy' => $interaction->ki_accuracy,
                'ic50' => $interaction->ic50,
                'ic50_accuracy' => $interaction->ic50_accuracy,
            ],
                ExportToFile::publicationFields('primary', $interaction->publication),
                ExportToFile::publicationFields('secondary', $secondaryPublication),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function structureFields(?Structure $structure): array
    {
        return [
            'identifier' => $structure?->identifier,
            'name' => $structure?->name,
            'canonical_smiles' => $structure?->canonical_smiles,
            'inchikey' => $structure?->inchikey,
            'mw' => $structure?->molecular_weight,
            'logp' => $structure?->logp,
            'pubchem' => $structure?->pubchem,
            'pdb' => $structure?->pdb,
            'chembl' => $structure?->chembl,
            'chebi' => $structure?->chebi,
            'drugbank' => $structure?->drugbank,
        ];
    }
}
