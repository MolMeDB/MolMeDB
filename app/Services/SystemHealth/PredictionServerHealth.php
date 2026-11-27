<?php

namespace App\Services\SystemHealth;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Modules\PredictionWorkers\Models\PredictionStat;
use Modules\PredictionWorkers\Services\RemotePrediction\RemotePredictionClient;
use Throwable;

/**
 * Whether the remote prediction server works, judged from the statistics the
 * prediction worker fetches from it every minute (PredictionStat): whether it
 * still answers, what its Kubernetes namespace reports (components short of
 * replicas, pods that cannot start, pods that cannot be created) and whether
 * every pipeline step that has work waiting also does it.
 *
 * Problems are what keeps predictions from being computed and is announced
 * (CheckPredictionServerHealth); warnings are worth a look in the
 * administration and the daily report only.
 */
class PredictionServerHealth
{
    public const LABEL = 'Prediction server';

    /** Statistics older than this: the server stopped answering, or nobody asks it. */
    public const STALE_AFTER_MINUTES = 10;

    /** A step with work waiting, none running and nothing finished for this long has no worker. */
    public const IDLE_STEP_MINUTES = 60;

    /** A running item whose worker has not reported for this long is stuck. */
    public const STUCK_HEARTBEAT_SECONDS = 900;

    /** Waiting reasons of pods that cannot start; anything else the server reports is a warning. */
    private const BLOCKING_POD_REASONS = [
        'ImagePullBackOff',
        'ErrImagePull',
        'InvalidImageName',
        'CrashLoopBackOff',
        'CreateContainerConfigError',
        'CreateContainerError',
        'RunContainerError',
        'Unschedulable',
    ];

    public function __construct(private readonly RemotePredictionClient $client) {}

    public function assess(?CarbonInterface $now = null): PredictionServerAssessment
    {
        $now = CarbonImmutable::instance($now ?? now());

        if (! $this->isEnabled()) {
            return PredictionServerAssessment::disabled();
        }

        $stat = PredictionStat::query()->newest()->first();

        if ($stat === null || $stat->fetched_at === null) {
            return new PredictionServerAssessment(
                HealthCheckResult::STATUS_PROBLEM,
                ['unreachable' => 'No statistics have been fetched from the prediction server yet.'],
            );
        }

        $payload = is_array($stat->payload) ? $stat->payload : [];
        $problems = [];
        $warnings = [];

        if ($stat->fetched_at->lt($now->subMinutes(self::STALE_AFTER_MINUTES))) {
            $problems['unreachable'] = 'The prediction server has not answered since '
                .$stat->fetched_at->format('Y-m-d H:i').' ('.$this->ago($stat->fetched_at, $now).'); what follows is from then.';
        }

        $components = [];
        $note = null;
        $cluster = $payload['cluster'] ?? null;

        if (! is_array($cluster)) {
            $note = 'The prediction server does not report the state of its cluster (an older version).';
        } elseif (($cluster['in_cluster'] ?? false) !== true) {
            $note = 'The prediction server does not run in Kubernetes; there is no cluster to watch.';
        } elseif (($cluster['available'] ?? false) !== true) {
            $warnings[] = 'The state of the cluster could not be read: '.Str::limit((string) ($cluster['error'] ?? 'no reason given'), 200);
        } else {
            $components = $this->components($cluster);
            $problems += $this->podProblems($cluster, $now, $warnings);

            $unlisted = (int) ($cluster['problems_total'] ?? 0) - count(Arr::wrap($cluster['problems'] ?? []));
            if ($unlisted > 0) {
                $warnings[] = "{$unlisted} more pod ".Str::plural('problem', $unlisted).' not listed by the server.';
            }

            $problems += $this->eventProblems($cluster);
            $problems += $this->componentProblems($components, $warnings);
        }

        $problems += $this->idleSteps($payload, $now);
        $problems += $this->stuckWork($payload);

        return new PredictionServerAssessment(
            match (true) {
                $problems !== [] => HealthCheckResult::STATUS_PROBLEM,
                $warnings !== [] => HealthCheckResult::STATUS_WARNING,
                default => HealthCheckResult::STATUS_OK,
            },
            $problems,
            $warnings,
            $components,
            $stat->fetched_at,
            $note,
        );
    }

    private function isEnabled(): bool
    {
        try {
            return $this->client->isEnabled();
        } catch (Throwable) {
            return (bool) config('prediction-workers.remote.enabled', false);
        }
    }

    /**
     * @param  array<string, mixed>  $cluster
     * @return list<array{name: string, kind: string, desired: int, ready: int, rollout_stalled: bool}>
     */
    private function components(array $cluster): array
    {
        return collect(Arr::wrap($cluster['components'] ?? []))
            ->filter(fn (mixed $component): bool => is_array($component) && filled($component['name'] ?? null))
            ->map(fn (array $component): array => [
                'name' => (string) $component['name'],
                'kind' => (string) ($component['kind'] ?? ''),
                'desired' => (int) ($component['desired'] ?? 0),
                'ready' => (int) ($component['ready'] ?? 0),
                'rollout_stalled' => ($component['rollout_stalled'] ?? false) === true,
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * Pods that cannot start, one line per component and reason.
     *
     * @param  array<string, mixed>  $cluster
     * @param  list<string>  $warnings
     * @return array<string, string>
     */
    private function podProblems(array $cluster, CarbonImmutable $now, array &$warnings): array
    {
        $problems = [];

        collect(Arr::wrap($cluster['problems'] ?? []))
            ->filter(fn (mixed $problem): bool => is_array($problem))
            ->groupBy(fn (array $problem): string => ($problem['component'] ?? $problem['pod'] ?? '?').'|'.($problem['reason'] ?? '?'))
            ->each(function ($group) use (&$problems, &$warnings, $now): void {
                $first = $group->first();
                $component = (string) ($first['component'] ?? $first['pod'] ?? 'unknown');
                $reason = (string) ($first['reason'] ?? 'unknown');
                $since = $this->time($group->pluck('since')->filter()->sort()->first());
                $line = sprintf(
                    '%s: %d %s %s%s%s',
                    $component,
                    $group->count(),
                    Str::plural('pod', $group->count()),
                    $reason,
                    $since ? ' since '.$since->format('Y-m-d H:i').' ('.$this->ago($since, $now).')' : '',
                    filled($first['message'] ?? null) ? ' — '.Str::limit((string) $first['message'], 200) : '',
                );

                if (in_array($reason, self::BLOCKING_POD_REASONS, true)) {
                    $problems["pods:{$component}:{$reason}"] = $line;
                } else {
                    $warnings[] = $line;
                }
            });

        return $problems;
    }

    /**
     * Pods that are not created at all (a quota) or not placed on a node.
     *
     * @param  array<string, mixed>  $cluster
     * @return array<string, string>
     */
    private function eventProblems(array $cluster): array
    {
        $problems = [];

        foreach (Arr::wrap($cluster['events'] ?? []) as $event) {
            if (! is_array($event)) {
                continue;
            }

            $reason = (string) ($event['reason'] ?? 'Warning');
            // replicaset/cosmo-worker-dcb4c95d6 -> cosmo-worker: the same
            // component keeps its key across rollouts.
            $object = (string) ($event['object'] ?? '');
            $owner = preg_replace('#^replicaset/(.+)-[a-z0-9]{6,10}$#', '$1', $object) ?? $object;

            $problems["event:{$owner}:{$reason}"] ??= sprintf(
                '%s: %s (%d×) — %s',
                $owner,
                $reason,
                (int) ($event['count'] ?? 1),
                Str::limit((string) ($event['message'] ?? ''), 200),
            );
        }

        return $problems;
    }

    /**
     * A component with no ready replica does nothing; one short of some
     * replicas only computes slower (and does so for a while on every
     * rollout of its many pods).
     *
     * @param  list<array{name: string, kind: string, desired: int, ready: int, rollout_stalled: bool}>  $components
     * @param  list<string>  $warnings
     * @return array<string, string>
     */
    private function componentProblems(array $components, array &$warnings): array
    {
        $problems = [];

        foreach ($components as $component) {
            if ($component['desired'] > 0 && $component['ready'] === 0) {
                $problems["component:{$component['name']}"] = "{$component['name']}: none of {$component['desired']} ".Str::plural('replica', $component['desired']).' ready.';
            } elseif ($component['ready'] < $component['desired']) {
                $warnings[] = "{$component['name']}: {$component['ready']} of {$component['desired']} replicas ready.";
            }

            if ($component['rollout_stalled']) {
                $warnings[] = "{$component['name']}: the rollout of a new version is past its deadline; the old pods still run.";
            }
        }

        return $problems;
    }

    /**
     * Steps with work waiting that nothing takes: no worker of the step runs.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function idleSteps(array $payload, CarbonImmutable $now): array
    {
        $problems = [];

        foreach (Arr::wrap($payload['activity'] ?? []) as $step => $activity) {
            if (! is_array($activity)) {
                continue;
            }

            $queued = (int) ($activity['queued'] ?? 0);
            $lastCompletedAt = $this->time($activity['last_completed_at'] ?? null);

            if (
                $queued === 0
                || (int) ($activity['running'] ?? 0) > 0
                || ($lastCompletedAt !== null && $lastCompletedAt->gte($now->subMinutes(self::IDLE_STEP_MINUTES)))
            ) {
                continue;
            }

            $problems["step:{$step}"] = sprintf(
                'Step %s: %d waiting, none running, %s.',
                $step,
                $queued,
                $lastCompletedAt
                    ? 'last finished '.$lastCompletedAt->format('Y-m-d H:i').' ('.$this->ago($lastCompletedAt, $now).')'
                    : 'none finished yet',
            );
        }

        return $problems;
    }

    /**
     * Running items whose worker stopped reporting (its heartbeat).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function stuckWork(array $payload): array
    {
        $stuck = [];

        foreach (['molecule_steps', 'conformer_steps', 'cosmo'] as $group) {
            foreach (Arr::wrap(Arr::get($payload, "running.{$group}", [])) as $item) {
                if (! is_array($item) || (int) ($item['heartbeat_age_seconds'] ?? 0) <= self::STUCK_HEARTBEAT_SECONDS) {
                    continue;
                }

                $step = (string) ($item['step'] ?? $group);
                $stuck[$step] = ($stuck[$step] ?? 0) + 1;
            }
        }

        $problems = [];

        foreach ($stuck as $step => $count) {
            $problems["stuck:{$step}"] = sprintf(
                'Step %s: %d running %s without a sign of life from the worker for over %d minutes.',
                $step,
                $count,
                Str::plural('item', $count),
                intdiv(self::STUCK_HEARTBEAT_SECONDS, 60),
            );
        }

        return $problems;
    }

    private function ago(CarbonInterface $time, CarbonInterface $now): string
    {
        return $time->diffForHumans(['other' => $now, 'syntax' => CarbonInterface::DIFF_RELATIVE_TO_NOW]);
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
