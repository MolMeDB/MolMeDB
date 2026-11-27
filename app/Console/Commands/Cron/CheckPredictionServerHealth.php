<?php

namespace App\Console\Commands\Cron;

use App\Enums\PermissionEnums;
use App\Models\NotificationTemplate;
use App\Services\AdminUrlGenerator;
use App\Services\NotificationService;
use App\Services\SystemHealth\PredictionServerAssessment;
use App\Services\SystemHealth\PredictionServerHealth;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Tells the system monitors when the remote prediction server stops
 * computing (PredictionServerHealth), and when it works again.
 *
 * A problem is announced once it has lasted CONFIRM_MINUTES, so a rollout or
 * a restarted pod passes unnoticed; again when a new kind of problem joins it
 * or once a day while it lasts; and its end is announced too.
 */
class CheckPredictionServerHealth extends Command
{
    /** A problem is announced only once it has lasted this long. */
    public const CONFIRM_MINUTES = 5;

    /** While a problem lasts, it is announced again after this long. */
    public const REMIND_HOURS = 24;

    public const STATE_CACHE_KEY = 'prediction-server-health:alert';

    protected $signature = 'predictions:check-server-health';

    protected $description = 'Tell the system monitors when the remote prediction server stops computing, and when it works again.';

    public function handle(PredictionServerHealth $health, NotificationService $notifications, AdminUrlGenerator $urls): int
    {
        $now = CarbonImmutable::now();
        $assessment = $health->assess($now);
        /** @var array{seen: array<string, string>, notified_keys: list<string>, notified_at: ?string, first_notified_at: ?string}|null $state */
        $state = Cache::get(self::STATE_CACHE_KEY);

        if (! $assessment->hasProblems()) {
            if ($state !== null && $state['first_notified_at'] !== null && $assessment->status !== PredictionServerAssessment::STATUS_DISABLED) {
                $this->announce($notifications, $urls, [
                    'title' => 'Prediction server works again',
                    'summary' => 'The problems announced at '.CarbonImmutable::parse($state['first_notified_at'])->format('Y-m-d H:i').' are gone.',
                    'items' => new HtmlString(''),
                ]);
                $this->info('The prediction server works again; announced.');
            }

            Cache::forget(self::STATE_CACHE_KEY);
            $this->line("Prediction server: {$assessment->summary()}");

            return Command::SUCCESS;
        }

        $state ??= ['seen' => [], 'notified_keys' => [], 'notified_at' => null, 'first_notified_at' => null];

        // When each current problem was first seen; gone ones are forgotten.
        $seen = [];
        foreach (array_keys($assessment->problems) as $key) {
            $seen[$key] = $state['seen'][$key] ?? $now->toIso8601String();
        }
        $state['seen'] = $seen;

        $confirmed = array_keys(array_filter(
            $seen,
            fn (string $since): bool => CarbonImmutable::parse($since)->lte($now->subMinutes(self::CONFIRM_MINUTES)),
        ));
        $unannounced = array_diff($confirmed, $state['notified_keys']);
        $reminderDue = $state['notified_at'] !== null
            && CarbonImmutable::parse($state['notified_at'])->lte($now->subHours(self::REMIND_HOURS));

        if ($confirmed !== [] && ($unannounced !== [] || $reminderDue)) {
            $count = count($assessment->problems);
            $earliest = CarbonImmutable::parse(min($seen));

            $this->announce($notifications, $urls, [
                'title' => ($state['first_notified_at'] === null ? 'Prediction server: ' : 'Prediction server still: ')
                    .$count.' '.Str::plural('problem', $count),
                'summary' => 'Predictions are not being computed as they should since '.$earliest->format('Y-m-d H:i').'.',
                'items' => $this->list($assessment->problems),
            ]);

            $state['notified_keys'] = array_values(array_unique([...$state['notified_keys'], ...$confirmed]));
            $state['notified_at'] = $now->toIso8601String();
            $state['first_notified_at'] ??= $now->toIso8601String();
            $this->warn("Prediction server: {$assessment->summary()} Announced.");
        } else {
            $this->line("Prediction server: {$assessment->summary()}");
        }

        Cache::forever(self::STATE_CACHE_KEY, $state);

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function announce(NotificationService $notifications, AdminUrlGenerator $urls, array $data): void
    {
        $notifications->sendToPermission(
            PermissionEnums::SYSTEM_MONITOR->value,
            NotificationTemplate::KEY_PREDICTION_ADMIN_SERVER_HEALTH,
            [...$data, 'admin_url' => $urls->dashboardUrl()],
        );
    }

    /**
     * @param  array<string, string>  $problems
     */
    private function list(array $problems): HtmlString
    {
        return new HtmlString(
            '<ul>'.implode('', array_map(fn (string $problem): string => '<li>'.e($problem).'</li>', $problems)).'</ul>',
        );
    }
}
