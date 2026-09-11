<?php

namespace App\Console\Commands\Cron;

use App\Console\Commands\CheckStructureInternalIdentifiers;
use App\Console\Commands\CleanupExpiredDownloadFiles;
use App\Console\Commands\Database\BackupDb;
use App\Console\Commands\Database\BackupDbIdsm;
use App\Console\Commands\Database\BackupDbPredictions;
use App\Console\Commands\GenerateSitemap;
use App\Console\Commands\UpdateExportFiles;
use App\Console\Commands\UpdateStatistics;
use App\Enums\PermissionEnums;
use App\Models\Config;
use App\Models\NotificationTemplate;
use App\Services\NotificationService;
use App\Services\SystemActivityLogger;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

class RunDailyCommands extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cron:daily';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Runs all daily cron jobs.';

    /**
     * Execute the console command.
     */
    private const BACKUP_COMMANDS = [
        BackupDb::class,
        BackupDbPredictions::class,
        BackupDbIdsm::class,
    ];

    public function handle(SystemActivityLogger $activityLogger, NotificationService $notificationService): int
    {
        $commands = [
            [
                'name' => CleanupExpiredDownloadFiles::class,
                'label' => 'Cleanup expired downloader files',
                'parameters' => [],
            ],
            [
                'name' => UpdateStatistics::class,
                'label' => 'Update statistics',
                'parameters' => [],
            ],
            [
                'name' => UpdateExportFiles::class,
                'label' => 'Update export files',
                'parameters' => [],
            ],
            [
                'name' => GenerateSitemap::class,
                'label' => 'Generate sitemap',
                'parameters' => [],
            ],
            [
                'name' => CheckStructureInternalIdentifiers::class,
                'label' => 'Check structure internal identifiers',
                'parameters' => [
                    // '--startId' => Config::get('cron:daily:check_structure_identifier:start_id', 1),
                    '--startId' => 497600,
                ],
            ],
            [
                'name' => BackupDb::class,
                'label' => 'Backup database (full)',
                'parameters' => [],
            ],
            [
                'name' => BackupDbPredictions::class,
                'label' => 'Backup predictions database',
                'parameters' => [],
            ],
            [
                'name' => BackupDbIdsm::class,
                'label' => 'Backup database (IDSM, weekly)',
                'parameters' => [],
            ],
        ];

        $startedAt = now();
        $logDirectory = $this->prepareLogDirectory($startedAt);
        $results = [];

        $this->comment("\n-----------------------");
        $this->comment('Running daily jobs at '.$startedAt->toDateTimeString());
        $this->comment(".......\n");

        foreach ($commands as $command) {
            $this->info($command['label']);

            $output = new BufferedOutput;
            $jobStartedAt = now();
            $logPath = $logDirectory.'/'.$this->logFilename($command['label']);

            try {
                $exitCode = Artisan::call($command['name'], $command['parameters'], $output);
                $commandOutput = trim($output->fetch());
                $results[] = [
                    'label' => $command['label'],
                    'command' => $command['name'],
                    'successful' => $exitCode === Command::SUCCESS,
                    'exit_code' => $exitCode,
                    'output' => $commandOutput,
                    'error' => $exitCode === Command::SUCCESS ? null : 'Command exited with code '.$exitCode.'.',
                    'exception' => null,
                    'started_at' => $jobStartedAt,
                    'finished_at' => now(),
                    'log_path' => $logPath,
                ];
            } catch (Throwable $throwable) {
                $commandOutput = trim($output->fetch());
                $results[] = [
                    'label' => $command['label'],
                    'command' => $command['name'],
                    'successful' => false,
                    'exit_code' => Command::FAILURE,
                    'output' => $commandOutput,
                    'error' => $throwable::class.': '.$throwable->getMessage(),
                    'exception' => $throwable,
                    'started_at' => $jobStartedAt,
                    'finished_at' => now(),
                    'log_path' => $logPath,
                ];
            }

            $result = end($results);
            $this->writeJobLog($result);
            $this->{$result['successful'] ? 'info' : 'error'}(
                ($result['successful'] ? '[OK] ' : '[FAILED] ').$command['label']
            );
        }

        $this->comment("\n.......");
        $this->comment($this->summaryText($results, $startedAt));
        $this->comment("\nDone\n-----------------------\n\n");

        $finishedAt = now();

        File::put(
            $logDirectory.'/summary.log',
            $this->summaryLog($results, $startedAt, $finishedAt)
        );

        $successful = collect($results)->every(fn (array $result): bool => $result['successful']);
        $failedCommands = collect($results)
            ->reject(fn (array $result): bool => $result['successful'])
            ->pluck('label')
            ->values()
            ->all();

        $activityLogger->log(
            event: $successful ? 'daily_maintenance_completed' : 'daily_maintenance_failed',
            description: $successful
                ? 'Daily maintenance completed successfully.'
                : 'Daily maintenance finished with one or more failed commands.',
            properties: [
                'commands' => count($results),
                'failed_commands' => $failedCommands,
                'duration_seconds' => $startedAt->diffInSeconds($finishedAt),
                'summary_log' => $logDirectory.'/summary.log',
            ],
            severity: $successful
                ? SystemActivityLogger::SEVERITY_INFO
                : SystemActivityLogger::SEVERITY_ERROR,
        );

        $this->sendSummaryEmail($results, $startedAt, $finishedAt);
        $this->notifyFailedBackups($results, $notificationService);

        return $successful ? Command::SUCCESS : Command::FAILURE;
    }

    private function notifyFailedBackups(array $results, NotificationService $notificationService): void
    {
        foreach ($results as $result) {
            if ($result['successful'] || ! in_array($result['command'], self::BACKUP_COMMANDS, true)) {
                continue;
            }

            $notificationService->sendToPermission(
                PermissionEnums::SYSTEM_MONITOR->value,
                NotificationTemplate::KEY_SYSTEM_ADMIN_BACKUP_FAILED,
                [
                    'label' => $result['label'],
                    'error' => $result['error'] ?? 'Unknown error.',
                ],
            );
        }
    }

    private function prepareLogDirectory(CarbonInterface $startedAt): string
    {
        $baseDirectory = storage_path('logs/cron/daily');
        $logDirectory = $baseDirectory.'/'.$startedAt->toDateString();

        File::ensureDirectoryExists($logDirectory);
        $this->deleteOldLogDirectories($baseDirectory, $startedAt);

        return $logDirectory;
    }

    private function deleteOldLogDirectories(string $baseDirectory, CarbonInterface $now): void
    {
        $oldestAllowedDate = $now->copy()->subDays(10)->startOfDay();

        foreach (File::directories($baseDirectory) as $directory) {
            try {
                $directoryDate = Carbon::createFromFormat('Y-m-d', basename($directory))->startOfDay();
            } catch (Throwable) {
                continue;
            }

            if ($directoryDate->lt($oldestAllowedDate)) {
                File::deleteDirectory($directory);
            }
        }
    }

    private function logFilename(string $label): string
    {
        return Str::slug($label).'.log';
    }

    private function writeJobLog(array $result): void
    {
        File::put($result['log_path'], $this->jobLog($result));
    }

    private function sendSummaryEmail(array $results, CarbonInterface $startedAt, CarbonInterface $finishedAt): void
    {
        $recipients = array_filter(array_map(
            fn (string $recipient): string => trim($recipient),
            explode(';', Config::get('email:cron:failure', '') ?? '')
        ));

        if (empty($recipients)) {
            return;
        }

        $successful = collect($results)->every(fn (array $result): bool => $result['successful']);

        Mail::html(
            $this->summaryHtml($results, $startedAt, $finishedAt),
            function ($message) use ($recipients, $successful, $startedAt): void {
                $message
                    ->to($recipients)
                    ->subject(sprintf(
                        '[MolMeDB] Daily cron %s - %s',
                        $successful ? 'completed' : 'failed',
                        $startedAt->toDateString(),
                    ));
            }
        );
    }

    private function summaryText(array $results, CarbonInterface $startedAt): string
    {
        $lines = [
            'Daily cron summary',
            'Started at: '.$startedAt->toDateTimeString(),
            '',
        ];

        foreach ($results as $result) {
            $lines[] = sprintf(
                '%s %s (%ss)',
                $result['successful'] ? '[OK]' : '[FAILED]',
                $result['label'],
                $result['started_at']->diffInSeconds($result['finished_at']),
            );
        }

        return implode("\n", $lines);
    }

    private function summaryHtml(array $results, CarbonInterface $startedAt, CarbonInterface $finishedAt): string
    {
        $successful = collect($results)->every(fn (array $result): bool => $result['successful']);
        $statusColor = $successful ? '#15803d' : '#b91c1c';

        $jobsHtml = collect($results)
            ->map(function (array $result): string {
                $status = $result['successful'] ? '✓' : '✕';
                $statusColor = $result['successful'] ? '#15803d' : '#b91c1c';
                $duration = $result['started_at']->diffInSeconds($result['finished_at']);
                $detail = '';

                if (! $result['successful']) {
                    $details = array_filter([
                        $result['error'],
                        'Full log: '.$result['log_path'],
                    ]);

                    $detail = '<pre style="margin:12px 0 0;padding:12px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;white-space:pre-wrap;color:#374151;">'
                        .e(implode("\n\n", $details))
                        .'</pre>';
                }

                return sprintf(
                    '<li style="padding:14px 0;border-bottom:1px solid #e5e7eb;">
                        <div style="display:flex;gap:10px;align-items:center;">
                            <strong style="color:%s;font-size:18px;">%s</strong>
                            <div>
                                <div style="font-weight:700;color:#111827;">%s</div>
                                <div style="color:#6b7280;font-size:13px;">%s seconds</div>
                            </div>
                        </div>
                        %s
                    </li>',
                    $statusColor,
                    $status,
                    e($result['label']),
                    $duration,
                    $detail,
                );
            })
            ->implode('');

        return sprintf(
            '<div style="font-family:Arial,sans-serif;line-height:1.5;color:#111827;">
                <h1 style="margin:0 0 8px;font-size:22px;color:%s;">Daily cron %s</h1>
                <p style="margin:0 0 18px;color:#4b5563;">
                    Started: %s<br>
                    Finished: %s<br>
                    Duration: %s seconds
                </p>
                <ul style="list-style:none;padding:0;margin:0;">%s</ul>
            </div>',
            $statusColor,
            $successful ? 'completed successfully' : 'finished with errors',
            e($startedAt->toDateTimeString()),
            e($finishedAt->toDateTimeString()),
            $startedAt->diffInSeconds($finishedAt),
            $jobsHtml,
        );
    }

    private function summaryLog(array $results, CarbonInterface $startedAt, CarbonInterface $finishedAt): string
    {
        return $this->summaryText($results, $startedAt)
            ."\nFinished at: ".$finishedAt->toDateTimeString()
            ."\nDuration: ".$startedAt->diffInSeconds($finishedAt)."s\n";
    }

    private function jobLog(array $result): string
    {
        $lines = [
            'Job: '.$result['label'],
            'Command: '.$result['command'],
            'Status: '.($result['successful'] ? 'OK' : 'FAILED'),
            'Exit code: '.$result['exit_code'],
            'Started at: '.$result['started_at']->toDateTimeString(),
            'Finished at: '.$result['finished_at']->toDateTimeString(),
            'Duration: '.$result['started_at']->diffInSeconds($result['finished_at']).'s',
            '',
            'Output:',
            $result['output'] !== '' ? $result['output'] : '(no output)',
        ];

        if ($result['exception'] instanceof Throwable) {
            $lines = array_merge($lines, [
                '',
                'Exception:',
                $result['exception']::class,
                $result['exception']->getMessage(),
                '',
                'Trace:',
                $result['exception']->getTraceAsString(),
            ]);
        } elseif ($result['error']) {
            $lines = array_merge($lines, [
                '',
                'Error:',
                $result['error'],
            ]);
        }

        return implode("\n", $lines)."\n";
    }
}
