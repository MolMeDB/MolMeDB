<?php

require_once __DIR__.'/../Api/api_test_helpers.php';

use App\Enums\NotificationType;
use App\Jobs\ProcessDownloadQueueExport;
use App\Libraries\Export\SpreadsheetSafeCsv;
use App\Models\DownloadQueue;
use App\Models\Filesystem;
use App\Models\QueuedNotification;
use App\Models\User;
use App\Services\NotificationBatcher;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * The downloader: a selection of interactions is exported by a queued job
 * into a ZIP with one CSV per interaction type and the licence, which the
 * requester downloads by the request's UUID.
 */

beforeEach(function () {
    $filesystem = Filesystem::query()->where('type', Filesystem::TYPE_EXPORTS)->firstOrFail();
    config()->set("filesystems.disks.{$filesystem->systemName}", ['driver' => 'local']);
    Storage::fake($filesystem->systemName);
    $this->exportsDisk = Storage::disk($filesystem->systemName);
});

/**
 * @param  array<string, mixed>  $selection
 * @param  array<string, mixed>  $attributes
 */
function createDownloadRequest(array $selection, array $attributes = []): DownloadQueue
{
    $selection = DownloadQueue::normalizeSelection($selection);

    return DownloadQueue::query()->create([
        'uuid' => (string) Str::uuid(),
        'state' => DownloadQueue::STATE_PENDING,
        'selection' => $selection,
        'selection_hash' => DownloadQueue::hashSelection($selection),
        'expires_at' => now()->addDays(DownloadQueue::EXPIRATION_DAYS),
        ...$attributes,
    ]);
}

/**
 * @return array<string, string> file name => contents
 */
function readExportZip(string $path): array
{
    $zip = new ZipArchive;
    $zip->open($path);
    $files = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = $zip->getNameIndex($index);
        $files[$name] = (string) $zip->getFromName($name);
    }

    $zip->close();

    return $files;
}

function runDownloadExport(DownloadQueue $download): void
{
    app()->call([new ProcessDownloadQueueExport($download), 'handle']);
}

test('a selection is exported into a ZIP with both interaction types and the licence', function () {
    $owner = User::factory()->create();
    $structure = createApiStructure();
    createApiPassiveInteraction(['structure' => $structure, 'gpen' => -12.35]);
    createApiActiveInteraction(['structure' => $structure]);
    createApiPassiveInteraction();
    $download = createDownloadRequest(['structure_identifiers' => [$structure->identifier]], ['user_id' => $owner->id]);

    runDownloadExport($download);
    $download->refresh();

    expect($download->state)->toBe(DownloadQueue::STATE_DONE)
        ->and($download->progress['processed'])->toBe(2)
        ->and($download->progress['total'])->toBe(2);

    $files = readExportZip($this->exportsDisk->path($download->file_path));

    expect(array_keys($files))->toEqualCanonicalizing(['passive_interactions.csv', 'active_interactions.csv', 'README.txt', 'LICENSE.txt'])
        ->and(substr_count(trim($files['passive_interactions.csv']), "\n"))->toBe(1)
        ->and($files['passive_interactions.csv'])->toContain($structure->identifier)
        ->and($files['passive_interactions.csv'])->toContain('-12.35')
        ->and(substr_count(trim($files['active_interactions.csv']), "\n"))->toBe(1);

    // The working CSVs are removed once they are in the ZIP.
    $this->exportsDisk->assertMissing("downloader/{$download->uuid}/passive_interactions.csv");

    expect(QueuedNotification::query()
        ->where('group_key', NotificationBatcher::groupKey(NotificationType::EXPORT_READY))
        ->where('notifiable_id', $owner->id)
        ->exists())->toBeTrue();
});

test('free-text values that a spreadsheet would run as a formula are neutralised', function () {
    $structure = createApiStructure();
    createApiPassiveInteraction(['structure' => $structure, 'note' => '=HYPERLINK("https://evil.example","Click")', 'gpen' => -1.5]);
    $download = createDownloadRequest(['structure_identifiers' => [$structure->identifier]]);

    runDownloadExport($download);

    $csv = readExportZip($this->exportsDisk->path($download->refresh()->file_path))['passive_interactions.csv'];

    expect($csv)->toContain("'=HYPERLINK")
        ->and($csv)->not->toContain(';=HYPERLINK')
        ->and($csv)->toContain(';-1.5;');
});

test('an expired request is not exported and its owner is told', function () {
    $owner = User::factory()->create();
    $download = createDownloadRequest(['membrane_ids' => [1]], ['user_id' => $owner->id, 'expires_at' => now()->subMinute()]);

    runDownloadExport($download);

    expect($download->refresh()->state)->toBe(DownloadQueue::STATE_ERROR)
        ->and($download->error_message)->toContain('expired')
        ->and(QueuedNotification::query()
            ->where('group_key', NotificationBatcher::groupKey(NotificationType::EXPORT_FAILED))
            ->where('notifiable_id', $owner->id)
            ->exists())->toBeTrue();
});

test('a run superseded by a restart leaves the request to the newer run', function () {
    $structure = createApiStructure();
    createApiPassiveInteraction(['structure' => $structure]);
    $download = createDownloadRequest(['structure_identifiers' => [$structure->identifier]], [
        'state' => DownloadQueue::STATE_RUNNING,
        'progress' => ['run_token' => 'newer-run', 'updated_at' => now()->toISOString()],
    ]);

    app()->call([new ProcessDownloadQueueExport($download, 'older-run'), 'handle']);

    expect($download->refresh()->state)->toBe(DownloadQueue::STATE_RUNNING)
        ->and($download->file_path)->toBeNull()
        ->and($download->progress['run_token'])->toBe('newer-run');
});

test('a missing export filesystem fails the request', function () {
    $disks = config('filesystems.disks');
    unset($disks[Filesystem::query()->where('type', Filesystem::TYPE_EXPORTS)->firstOrFail()->systemName]);
    config()->set('filesystems.disks', $disks);
    $download = createDownloadRequest(['membrane_ids' => [1]]);

    expect(fn () => runDownloadExport($download))->toThrow(RuntimeException::class, 'Export filesystem is not configured.');
    expect($download->refresh()->state)->toBe(DownloadQueue::STATE_ERROR);
});

test('the same selection reuses a pending or finished request instead of exporting again', function () {
    Queue::fake();
    $structure = createApiStructure();
    createApiPassiveInteraction(['structure' => $structure]);

    $first = $this->postJson('/api/downloader', ['structure_identifiers' => [$structure->identifier]])->assertCreated();
    $second = $this->postJson('/api/downloader', ['structure_identifiers' => [$structure->identifier]])->assertOk();

    expect($second->json('data.uuid'))->toBe($first->json('data.uuid'))
        ->and($second->json('data.reused'))->toBeTrue();
    Queue::assertPushed(ProcessDownloadQueueExport::class, 1);
});

test('a stalled export is restarted when its status is checked', function () {
    Queue::fake();
    $download = createDownloadRequest(['membrane_ids' => [1]], [
        'state' => DownloadQueue::STATE_RUNNING,
        'progress' => ['run_token' => 'stuck-run', 'processed' => 10, 'total' => 50, 'updated_at' => now()->subMinutes(DownloadQueue::STALLED_AFTER_MINUTES + 1)->toISOString()],
    ]);

    $this->getJson("/api/downloader/{$download->uuid}")
        ->assertOk()
        ->assertJsonPath('data.restarted', true)
        ->assertJsonPath('data.state', 'pending');

    Queue::assertPushed(ProcessDownloadQueueExport::class, fn (ProcessDownloadQueueExport $job): bool => $job->runToken !== 'stuck-run');
});

test('the export file is served only while the request is done, current and not cleaned up', function (array $attributes, int $status) {
    $download = createDownloadRequest(['membrane_ids' => [1]], [
        'state' => DownloadQueue::STATE_DONE,
        'file_path' => 'downloader/ready/export.zip',
        ...$attributes,
    ]);
    $this->exportsDisk->put('downloader/ready/export.zip', 'zip-bytes');

    $this->get("/api/downloader/{$download->uuid}/file")->assertStatus($status);
})->with([
    'done' => [[], 200],
    'still running' => [['state' => DownloadQueue::STATE_RUNNING], 404],
    'expired' => [['expires_at' => now()->subMinute()], 404],
    'files cleaned up' => [['files_deleted_at' => now()], 404],
    'file missing' => [['file_path' => 'downloader/missing/export.zip'], 404],
]);

test('spreadsheet-safe CSV cells keep numbers and neutralise formulas', function (mixed $value, mixed $expected) {
    expect(SpreadsheetSafeCsv::cell($value))->toBe($expected);
})->with([
    'formula' => ['=1+1', "'=1+1"],
    'plus' => ['+CMD', "'+CMD"],
    'at' => ['@SUM(A1)', "'@SUM(A1)"],
    'dash text' => ['-not a number', "'-not a number"],
    'negative number' => ['-12.35', '-12.35'],
    'float' => [-1.5, -1.5],
    'plain text' => ['Caffeine', 'Caffeine'],
    'empty' => ['', ''],
    'null' => [null, null],
]);
