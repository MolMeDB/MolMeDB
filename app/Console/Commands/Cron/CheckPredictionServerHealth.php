<?php

namespace App\Console\Commands\Cron;

use App\Enums\PermissionEnums;
use App\Models\Config;
use App\Models\NotificationTemplate;
use App\Services\AdminUrlGenerator;
use App\Services\NotificationService;
use App\Services\SystemHealth\PredictionServerAssessment;
use App\Services\SystemHealth\PredictionServerHealth;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Tells the system monitors when the remote prediction server stops
 * computing (PredictionServerHealth), and when it works again.
 *
 * - A problem is announced once it has lasted CONFIRM_MINUTES, so a rollout
 *   or a restarted pod passes unnoticed.
 * - It is announced again when a new kind of problem joins it, and once a
 *   day while it lasts.
 * - Its end is announced after HEALTHY_CHECKS healthy checks in a row, so a
 *   flapping problem does not announce its end and return every few minutes;
 *   a problem missing from one check keeps when it was first seen.
 *
 * The state is kept in configs: the cache is cleared on every start of the
 * application, which would announce a lasting outage again and lose its end.
 */
class CheckPredictionServerHealth extends Command
{
    /** A problem is announced only once it has lasted this long. */
    public const CONFIRM_MINUTES = 5;

    /** Slack for the moment the command starts at: the check runs every five minutes. */
    private const CONFIRM_SLACK_SECONDS = 60;

    /** While a problem lasts, it is announced again after this long. */
    public const REMIND_HOURS = 24;

    /** Healthy checks in a row before the end of a problem is announced. */
    public const HEALTHY_CHECKS = 2;

    /** A problem missing for longer than this is gone. */
    private const FORGET_AFTER_MINUTES = 15;

    protected $signature = 'predictions:check-server-health';

    protected $description = 'Tell the system monitors when the remote prediction server stops computing, and when it works again.';

    public function handle(PredictionServerHealth $health, NotificationService $notifications, AdminUrlGenerator $urls): int
    {
        $now = CarbonImmutable::now();
        $assessment = $health->assess($now);
        $state = $this->state();

        if ($assessment->status === PredictionServerAssessment::STATUS_DISABLED) {
            if ($state['first_notified_at'] !== null) {
                $this->announce($notifications, $urls, [
                    'title' => 'Prediction server no longer watched',
                    'summary' => 'Remote predictions were switched off; the problems announced at '.$this->format($state['first_notified_at']).' are no longer watched.',
                    'items' => new HtmlString(''),
                ]);
            }

            $this->forget();
            $this->line('Remote predictions are switched off.');

            return Command::SUCCESS;
        }

        if (! $assessment->hasProblems()) {
            return $this->healthy($state, $assessment, $notifications, $urls);
        }

        $timestamp = $now->getTimestamp();
        $state['healthy_checks'] = 0;

        // When each problem was first and last seen; one missing for long is gone.
        foreach (array_keys($assessment->problems) as $key) {
            $state['seen'][$key] = [$state['seen'][$key][0] ?? $timestamp, $timestamp];
        }
        $state['seen'] = array_filter(
            $state['seen'],
            fn (array $times): bool => $times[1] >= $timestamp - self::FORGET_AFTER_MINUTES * 60,
        );

        $confirmBefore = $timestamp - self::CONFIRM_MINUTES * 60 + self::CONFIRM_SLACK_SECONDS;
        $confirmed = array_keys(array_filter(
            $state['seen'],
            fn (array $times, string $key): bool => $times[0] <= $confirmBefore && isset($assessment->problems[$key]),
            ARRAY_FILTER_USE_BOTH,
        ));
        $unannounced = array_diff($confirmed, $state['notified_keys']);
        $reminderDue = $state['notified_at'] !== null
            && $state['notified_at'] <= $timestamp - self::REMIND_HOURS * 3600;

        if ($confirmed !== [] && ($unannounced !== [] || $reminderDue)) {
            $count = count($assessment->problems);
            $since = min(array_map(
                fn (string $key): int => $state['seen'][$key][0],
                array_keys($assessment->problems),
            ));

            $this->announce($notifications, $urls, [
                'title' => ($state['first_notified_at'] === null ? 'Prediction server: ' : 'Prediction server still: ')
                    .$count.' '.Str::plural('problem', $count),
                'summary' => 'Predictions are not being computed as they should since '.$this->format($since).'.',
                'items' => $this->list($assessment->problems),
            ]);

            $state['notified_keys'] = array_values(array_unique([...$state['notified_keys'], ...$confirmed]));
            $state['notified_at'] = $timestamp;
            $state['first_notified_at'] ??= $timestamp;
            $this->warn("Prediction server: {$assessment->summary()} Announced.");
        } else {
            $this->line("Prediction server: {$assessment->summary()}");
        }

        $this->save($state);

        return Command::SUCCESS;
    }

    /**
     * @param  array{seen: array<string, array{0: int, 1: int}>, notified_keys: list<string>, notified_at: ?int, first_notified_at: ?int, healthy_checks: int}  $state
     */
    private function healthy(array $state, PredictionServerAssessment $assessment, NotificationService $notifications, AdminUrlGenerator $urls): int
    {
        $this->line("Prediction server: {$assessment->summary()}");

        if ($state['first_notified_at'] === null) {
            // Not announced yet: keep when the problems were first seen for a
            // while, so one that comes and goes is still confirmed.
            $state['seen'] = array_filter(
                $state['seen'],
                fn (array $times): bool => $times[1] >= now()->getTimestamp() - self::FORGET_AFTER_MINUTES * 60,
            );
            $state['seen'] === [] ? $this->forget() : $this->save($state);

            return Command::SUCCESS;
        }

        $state['healthy_checks']++;

        if ($state['healthy_checks'] < self::HEALTHY_CHECKS) {
            $this->save($state);

            return Command::SUCCESS;
        }

        $this->announce($notifications, $urls, [
            'title' => 'Prediction server works again',
            'summary' => 'The problems announced at '.$this->format($state['first_notified_at']).' are gone.',
            'items' => new HtmlString(''),
        ]);
        $this->forget();
        $this->info('The prediction server works again; announced.');

        return Command::SUCCESS;
    }

    /**
     * @return array{seen: array<string, array{0: int, 1: int}>, notified_keys: list<string>, notified_at: ?int, first_notified_at: ?int, healthy_checks: int}
     */
    private function state(): array
    {
        $stored = json_decode((string) Config::get(Config::KEY_PREDICTION_SERVER_HEALTH_ALERT, ''), true);
        $stored = is_array($stored) ? $stored : [];

        return [
            'seen' => array_filter(
                is_array($stored['seen'] ?? null) ? $stored['seen'] : [],
                fn (mixed $times): bool => is_array($times) && is_int($times[0] ?? null) && is_int($times[1] ?? null),
            ),
            'notified_keys' => array_values(array_filter(
                is_array($stored['notified_keys'] ?? null) ? $stored['notified_keys'] : [],
                'is_string',
            )),
            'notified_at' => is_int($stored['notified_at'] ?? null) ? $stored['notified_at'] : null,
            'first_notified_at' => is_int($stored['first_notified_at'] ?? null) ? $stored['first_notified_at'] : null,
            'healthy_checks' => is_int($stored['healthy_checks'] ?? null) ? $stored['healthy_checks'] : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function save(array $state): void
    {
        Config::set(Config::KEY_PREDICTION_SERVER_HEALTH_ALERT, json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function forget(): void
    {
        Config::query()->whereKey(Config::KEY_PREDICTION_SERVER_HEALTH_ALERT)->delete();
    }

    private function format(int $timestamp): string
    {
        return CarbonImmutable::createFromTimestamp($timestamp, config('app.timezone'))->format('Y-m-d H:i');
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
