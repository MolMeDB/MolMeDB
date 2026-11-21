<?php

use App\Console\Commands\CleanupExpiredDownloadFiles;
use App\Models\DownloadQueue;
use App\Models\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $attributes
 */
function expiredDownloadForCleanup(array $attributes = []): DownloadQueue
{
    $selection = ['structure_identifiers' => ['MM'.Str::random(5)]];

    return DownloadQueue::query()->create([
        'uuid' => (string) Str::uuid(),
        'state' => DownloadQueue::STATE_DONE,
        'selection' => DownloadQueue::normalizeSelection($selection),
        'selection_hash' => DownloadQueue::hashSelection($selection),
        'expires_at' => now()->subDay(),
        ...$attributes,
    ]);
}

test('an export directory that cannot be deleted is reported and retried on the next run', function () {
    $filesystem = Filesystem::query()->where('type', Filesystem::TYPE_EXPORTS)->firstOrFail();
    config()->set("filesystems.disks.{$filesystem->systemName}", ['driver' => 'local']);

    $refused = expiredDownloadForCleanup();
    $unreachable = expiredDownloadForCleanup();
    $deletable = expiredDownloadForCleanup();
    $alreadyDeleted = expiredDownloadForCleanup(['files_deleted_at' => now()->subWeek()]);

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('deleteDirectory')->with($refused->storageDirectory())->once()->andReturnFalse();
    $disk->shouldReceive('deleteDirectory')->with($unreachable->storageDirectory())->once()->andThrow(new RuntimeException('Connection lost.'));
    $disk->shouldReceive('deleteDirectory')->with($deletable->storageDirectory())->once()->andReturnTrue();
    Storage::set($filesystem->systemName, $disk);

    $this->artisan(CleanupExpiredDownloadFiles::class)
        ->expectsOutputToContain("Failed to delete downloader files for {$refused->uuid}: Filesystem refused to delete the export directory.")
        ->expectsOutputToContain("Failed to delete downloader files for {$unreachable->uuid}: Connection lost.")
        ->expectsOutputToContain('Expired downloader directories deleted: 1; failures: 2.')
        ->assertFailed();

    expect($refused->refresh()->files_deleted_at)->toBeNull()
        ->and($unreachable->refresh()->files_deleted_at)->toBeNull()
        ->and($deletable->refresh()->files_deleted_at)->not->toBeNull()
        ->and($alreadyDeleted->refresh()->files_deleted_at->isSameDay(now()->subWeek()))->toBeTrue();
});
