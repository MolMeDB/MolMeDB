<?php

use App\Console\Commands\Cron\CheckPredictionServerHealth;
use App\Console\Commands\Cron\RunDailyCommands;
use App\Console\Commands\Cron\RunPredictionsWorker;
use App\Console\Commands\Cron\SendPredictionAdminStatsNotification;
use App\Console\Commands\Cron\SendPredictionProgressNotifications;
use App\Console\Commands\Cron\SendSystemHealthReport;
use App\Console\Commands\ExportPredictionCosmo;
use App\Console\Commands\FlushQueuedNotifications;
use App\Console\Commands\ProcessFrontendUploads;
use App\Console\Commands\SendUploadQueueAdminDigest;
use App\Console\Commands\SendUploadQueueNotifications;
use App\Jobs\ImportFinishedPredictionResults;
use App\Services\SystemActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\PredictionWorkers\Models\PredictionDataset;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('cron:init', function () {
    $this->comment("[Running all daily jobs]\n");
    Artisan::call(RunDailyCommands::class, [], $this->getOutput());
})->purpose('Runs non-destructive jobs to (re)initialize the system.');

Artisan::command('predictions:refresh-dataset-stats {--chunk=200}', function () {
    $chunkSize = max(1, (int) $this->option('chunk'));
    $refreshed = 0;

    PredictionDataset::query()
        ->select('id')
        ->orderBy('id')
        ->chunkById($chunkSize, function ($datasets) use (&$refreshed) {
            foreach ($datasets as $dataset) {
                $dataset->forgetProgressStatsCache();
                $dataset->cachedProgressStats();
                $refreshed++;
            }
        });

    $this->info("Refreshed {$refreshed} prediction dataset stats.");

    app(SystemActivityLogger::class)->log(
        event: 'prediction_dataset_stats_refreshed',
        description: "Prediction dataset statistics cache refreshed for {$refreshed} dataset(s).",
        properties: ['datasets' => $refreshed],
    );

    return Command::SUCCESS;
})->purpose('Refresh cached prediction dataset progress statistics.');

// Lock expiry (minutes) on every entry: the default of 1440 would let one
// killed run block the task for a whole day.
Schedule::command('predictions:refresh-dataset-stats')
    ->everyFiveMinutes()
    ->withoutOverlapping(30);

Schedule::command(RunPredictionsWorker::class)
    ->everyMinute()
    ->withoutOverlapping(10);

// Reads the statistics the worker above fetches; announces a prediction
// server that stopped computing (and its recovery) to the system monitors.
Schedule::command(CheckPredictionServerHealth::class)
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// ShouldBeUnique on the job itself (not ->withoutOverlapping()) is what
// actually guarantees only one instance is ever queued-or-running, since
// that holds regardless of how many queue:work processes are running.
Schedule::job(new ImportFinishedPredictionResults)
    ->everyMinute();

Schedule::command(ProcessFrontendUploads::class)
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::command(SendUploadQueueNotifications::class)
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::command(SendUploadQueueAdminDigest::class)
    ->everyTenMinutes()
    ->withoutOverlapping(30);

Schedule::command(FlushQueuedNotifications::class)
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::command(RunDailyCommands::class)
    ->dailyAt('01:00')
    ->withoutOverlapping(720);

Schedule::command(SendPredictionProgressNotifications::class)
    ->dailyAt('08:00')
    ->withoutOverlapping(60);

// Sunday night, after the nightly database backups. The command reports both
// success and failure itself (system.admin.cosmo_export_* notifications).
Schedule::command(ExportPredictionCosmo::class)
    ->weeklyOn(0, '03:00')
    ->withoutOverlapping(360);

// expiresAt=60 (minutes): this report runs in seconds, so if a stuck lock
// ever survives a crashed/killed run, it self-heals within an hour instead
// of silently blocking the next scheduled run for a full day (the default
// withoutOverlapping() expiry is 1440 minutes).
//
// `period` is a positional argument on the command, so it must be passed as
// a plain array value (not ['period' => ...]) - Schedule::exec() compiles
// associative keys as `key=value`, which the command's positional argument
// parser then reads as the literal string "period=day" instead of "day".
Schedule::command(SendPredictionAdminStatsNotification::class, ['day'])
    ->dailyAt('07:00')
    ->withoutOverlapping(60);

Schedule::command(SendPredictionAdminStatsNotification::class, ['week'])
    ->weeklyOn(1, '07:05')
    ->withoutOverlapping(60);

Schedule::command(SendPredictionAdminStatsNotification::class, ['month'])
    ->monthlyOn(1, '07:10')
    ->withoutOverlapping(60);

Schedule::command(SendPredictionAdminStatsNotification::class, ['year'])
    ->yearlyOn(1, 1, '07:15')
    ->withoutOverlapping(60);

// After the nightly commands and backups, so that their failures are in it.
// Looks at the previous day; sent on a good day too.
Schedule::command(SendSystemHealthReport::class)
    ->dailyAt('07:30')
    ->withoutOverlapping(60);
