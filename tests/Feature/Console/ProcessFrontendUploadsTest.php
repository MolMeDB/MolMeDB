<?php

require_once __DIR__.'/../UploadQueue/upload_pipeline_helpers.php';

use App\Console\Commands\ProcessFrontendUploads;
use App\Jobs\ProcessUploadQueueRecord;
use App\Models\UploadQueue;
use App\Services\SystemActivityLogger;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    // An own root for the uploaded CSV files, not the fake public disk shared by other test processes.
    $this->publicRoot = sys_get_temp_dir().'/frontend-uploads-test-'.getmypid().'-'.Str::random(6);
    config()->set('filesystems.disks.public', ['driver' => 'local', 'root' => $this->publicRoot, 'visibility' => 'public']);
    Storage::forgetDisk('public');
});

afterEach(function () {
    File::deleteDirectory($this->publicRoot);
});

test('an upload that cannot be queued is marked as failed without exposing the queue error', function () {
    $failing = createConfiguredUpload("SMILES,Gpen\nCCO,-1\n", ['smiles', 'g_pen']);
    $queued = createConfiguredUpload("SMILES,Gpen\nCCO,-1\n", ['smiles', 'g_pen']);
    $dispatched = [];

    $this->mock(Dispatcher::class, function ($dispatcher) use ($failing, &$dispatched): void {
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function (object $job) use ($failing, &$dispatched) {
            if ($job instanceof ProcessUploadQueueRecord && $job->uniqueId() === (string) $failing->id) {
                throw new RuntimeException('Connection refused [tcp://redis.internal:6379]');
            }

            $dispatched[] = $job;

            return null;
        });
    });

    $this->artisan(ProcessFrontendUploads::class)
        ->expectsOutputToContain('Queued: 1; errors: 1')
        ->assertSuccessful();

    $failing->refresh();
    $lastLog = $failing->logs->last();

    expect($failing->state)->toBe(UploadQueue::STATE_ERROR)
        ->and($lastLog->message)->toBe('The upload could not be queued for processing. The administrators have been notified.')
        ->and(json_encode($failing->logs))->not->toContain('redis.internal')
        ->and($queued->refresh()->state)->toBe(UploadQueue::STATE_PENDING)
        ->and(collect($dispatched)->first(fn (object $job): bool => $job instanceof ProcessUploadQueueRecord)?->uniqueId())->toBe((string) $queued->id);

    $activity = Activity::query()->where('event', 'frontend_upload_dispatch_completed_with_errors')->sole();

    expect($activity->properties['severity'])->toBe(SystemActivityLogger::SEVERITY_ERROR)
        ->and($activity->properties['queued'])->toBe(1)
        ->and($activity->properties['errors'])->toBe(1);
});

test('a run without pending uploads logs no activity', function () {
    createConfiguredUpload("SMILES,Gpen\nCCO,-1\n", ['smiles', 'g_pen'], attributes: ['state' => UploadQueue::STATE_DONE]);

    $this->artisan(ProcessFrontendUploads::class)
        ->expectsOutputToContain('Queued: 0; errors: 0')
        ->assertSuccessful();

    expect(Activity::query()->where('event', 'like', 'frontend_upload%')->exists())->toBeFalse();
});
