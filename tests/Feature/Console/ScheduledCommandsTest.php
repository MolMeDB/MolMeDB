<?php

require_once __DIR__.'/../Predictions/prediction_helpers.php';
require_once __DIR__.'/../UploadQueue/upload_pipeline_helpers.php';

use App\Console\Commands\CleanupExpiredDownloadFiles;
use App\Console\Commands\Cron\SendPredictionAdminStatsNotification;
use App\Console\Commands\Cron\SendPredictionProgressNotifications;
use App\Console\Commands\ProcessFrontendUploads;
use App\Console\Commands\UpdateExportFiles;
use App\Enums\NotificationType;
use App\Enums\PermissionEnums;
use App\Jobs\ProcessUploadQueueRecord;
use App\Models\DownloadQueue;
use App\Models\File;
use App\Models\Filesystem;
use App\Models\Permission;
use App\Models\QueuedNotification;
use App\Models\UploadQueue;
use App\Models\User;
use App\Services\NotificationBatcher;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\PredictionWorkers\Models\Prediction;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Permission::query()->firstOrCreate(
        ['name' => PermissionEnums::PREDICTION_DATASET_MANAGE_ALL->value, 'guard_name' => 'web'],
        ['description' => PermissionEnums::PREDICTION_DATASET_MANAGE_ALL->description()],
    );
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function queuedNotificationsFor(User $user, NotificationType $type): int
{
    return QueuedNotification::query()
        ->where('group_key', NotificationBatcher::groupKey($type))
        ->where('notifiable_id', $user->id)
        ->count();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createDownload(array $attributes): DownloadQueue
{
    $selection = ['structure_identifiers' => ['MM'.Str::random(5)]];

    return DownloadQueue::query()->create([
        'uuid' => (string) Str::uuid(),
        'state' => DownloadQueue::STATE_DONE,
        'selection' => DownloadQueue::normalizeSelection($selection),
        'selection_hash' => DownloadQueue::hashSelection($selection),
        ...$attributes,
    ]);
}

test('expired downloader exports are deleted and the others kept', function () {
    $filesystem = Filesystem::query()->where('type', Filesystem::TYPE_EXPORTS)->firstOrFail();
    config()->set("filesystems.disks.{$filesystem->systemName}", ['driver' => 'local']);
    Storage::fake($filesystem->systemName);
    $disk = Storage::disk($filesystem->systemName);

    $expired = createDownload(['expires_at' => now()->subDay()]);
    $current = createDownload(['expires_at' => now()->addDay()]);
    $disk->put($expired->storageDirectory().'/export.zip', 'old');
    $disk->put($current->storageDirectory().'/export.zip', 'new');

    $this->artisan(CleanupExpiredDownloadFiles::class)->assertSuccessful();

    $disk->assertMissing($expired->storageDirectory().'/export.zip');
    $disk->assertExists($current->storageDirectory().'/export.zip');
    expect($expired->refresh()->files_deleted_at)->not->toBeNull()
        ->and($current->refresh()->files_deleted_at)->toBeNull();
});

test('the export cleanup fails when the export filesystem is not set up', function () {
    $this->artisan(CleanupExpiredDownloadFiles::class)->assertFailed();
});

test('owners of running prediction datasets get the daily progress notification', function () {
    $running = User::factory()->create();
    $finished = User::factory()->create();
    createPredictionDataset($running);
    createPredictionDataset($finished, ['state' => Prediction::STATE_FINISHED, 'step' => Prediction::finalStep()]);
    createPredictionDataset(null);

    $this->artisan(SendPredictionProgressNotifications::class)->assertSuccessful();

    expect(queuedNotificationsFor($running, NotificationType::PREDICTION_JOB_DAILY_PROGRESS))->toBe(1)
        ->and(queuedNotificationsFor($finished, NotificationType::PREDICTION_JOB_DAILY_PROGRESS))->toBe(0);
});

test('the prediction statistics report goes to prediction managers', function () {
    $manager = createPredictionManager();
    createPredictionDataset(User::factory()->create());

    $this->artisan(SendPredictionAdminStatsNotification::class, ['period' => 'day'])->assertSuccessful();

    $queued = QueuedNotification::query()
        ->where('group_key', NotificationBatcher::groupKey(NotificationType::PREDICTION_ADMIN_STATS_REPORT))
        ->where('notifiable_id', $manager->id)
        ->sole();

    expect($queued->data['period_from'])->toBe(now()->subDay()->toDateString())
        ->and($queued->data['total_all'])->toBe(1);
});

test('the prediction statistics report refuses an unknown period', function () {
    $this->artisan(SendPredictionAdminStatsNotification::class, ['period' => 'fortnight'])->assertFailed();
});

test('pending frontend uploads are dispatched up to the limit and never queued twice', function () {
    fakePublicStorageForTests();
    Queue::fake();
    createConfiguredUpload("SMILES,Gpen\nCCO,-1\n", ['smiles', 'g_pen']);
    $second = createConfiguredUpload("SMILES,Gpen\nCCO,-1\n", ['smiles', 'g_pen']);
    createConfiguredUpload("SMILES,Gpen\nCCO,-1\n", ['smiles', 'g_pen'], attributes: ['state' => UploadQueue::STATE_DONE]);
    Queue::fake();

    $this->artisan(ProcessFrontendUploads::class, ['--limit' => 1])->assertSuccessful();
    Queue::assertPushed(ProcessUploadQueueRecord::class, 1);

    // The job is unique per record, so the first upload is not queued again
    // while its job waits; the command runs every minute.
    $this->artisan(ProcessFrontendUploads::class)->assertSuccessful();
    Queue::assertPushed(ProcessUploadQueueRecord::class, 2);
    Queue::assertPushed(ProcessUploadQueueRecord::class, fn (ProcessUploadQueueRecord $job): bool => $job->uniqueId() === (string) $second->id);
});

test('the export update fails when the export filesystem is not set up', function () {
    $this->artisan(UpdateExportFiles::class, ['--force' => true])->assertFailed();
});

test('the export update writes a downloadable export for every membrane with interactions', function () {
    $filesystem = Filesystem::query()->where('type', Filesystem::TYPE_EXPORTS)->firstOrFail();
    config()->set("filesystems.disks.{$filesystem->systemName}", ['driver' => 'local']);
    Storage::fake($filesystem->systemName);

    $interaction = createApiPassiveInteraction();
    $membrane = $interaction->dataset->membrane;

    $this->artisan(UpdateExportFiles::class, ['--force' => true])->assertSuccessful();

    $export = $membrane->files()->where('type', File::TYPE_EXPORT_INTERACTIONS_MEMBRANE)->sole();

    Storage::disk($export->storage)->assertExists($export->path);
});
