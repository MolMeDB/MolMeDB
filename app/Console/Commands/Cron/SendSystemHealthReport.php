<?php

namespace App\Console\Commands\Cron;

use App\Enums\PermissionEnums;
use App\Models\ApplicationError;
use App\Models\NotificationTemplate;
use App\Services\NotificationService;
use App\Services\SystemHealth\SystemHealthReport;
use Illuminate\Console\Command;

class SendSystemHealthReport extends Command
{
    /** Days for which the counted application errors are kept. */
    private const KEEP_ERRORS_DAYS = 30;

    protected $signature = 'cron:system-health-report';

    protected $description = 'Send the system monitors a report on the previous day: application errors, failed jobs, the prediction import, the structure search index, logs and disk space.';

    public function handle(SystemHealthReport $report, NotificationService $notifications): int
    {
        $day = now()->subDay()->startOfDay();
        $results = $report->run($day);
        $status = $report->status($results);

        // Sent on a good day as well: a report that stops coming says that
        // the reporting itself is broken.
        $notifications->sendToPermission(
            PermissionEnums::SYSTEM_MONITOR->value,
            NotificationTemplate::KEY_SYSTEM_ADMIN_HEALTH_REPORT,
            [
                'date' => $day->toDateString(),
                'status' => $status,
                'summary' => $report->summary($results),
                'report' => $report->toHtml($results),
            ],
        );

        ApplicationError::query()
            ->whereDate('day', '<', now()->subDays(self::KEEP_ERRORS_DAYS)->toDateString())
            ->delete();

        foreach ($results as $result) {
            $this->line(sprintf('[%s] %s: %s', $result->status, $result->label, $result->summary));
        }

        $this->info("System health report for {$day->toDateString()} sent: {$status}.");

        return Command::SUCCESS;
    }
}
