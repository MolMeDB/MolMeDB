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

    /** Warning events older than this are history, not the present state. */
    public const EVENT_MINUTES = 15;

    /** Events that say pods are not created or placed; other events are warnings. */
    private const BLOCKING_EVENT_REASONS = ['FailedCreate', 'FailedScheduling'];

    /** Reasons that are one problem to a reader: a pod alternates between them. */
    private const SAME_PROBLEM = ['ErrImagePull' => 'ImagePullBackOff'];

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
            $warnings[] = 'The state of the cluster could not be read: '.Str::limit($this->text($cluster['error'] ?? null, 'no reason given'), 200);
        } else {
            $components = $this->components($cluster);
            $problems += $this->podProblems($cluster, $now, $warnings);

            $unlisted = $this->number($cluster['problems_total'] ?? 0) - count(Arr::wrap($cluster['problems'] ?? []));
            if ($unlisted > 0) {
                $warnings[] = "{$unlisted} more pod ".Str::plural('problem', $unlisted).' not listed by the server.';
            }

            $problems += $this->eventProblems($cluster, $now, $warnings);
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
            ->filter(fn (mixed $component): bool => is_array($component) && $this->text($component['name'] ?? null) !== '')
            ->map(fn (array $component): array => [
                'name' => $this->text($component['name']),
                'kind' => $this->text($component['kind'] ?? null),
                'desired' => $this->number($component['desired'] ?? 0),
                'ready' => $this->number($component['ready'] ?? 0),
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
            ->groupBy(fn (array $problem): string => $this->podComponent($problem).'|'.$this->reason($problem['reason'] ?? null))
            ->each(function ($group) use (&$problems, &$warnings, $now): void {
                $first = $group->first();
                $component = $this->podComponent($first);
                $reason = $this->reason($first['reason'] ?? null);
                $since = $group
                    ->map(fn (array $problem): ?CarbonImmutable => $this->time($problem['since'] ?? null))
                    ->filter()
                    ->sort()
                    ->first();
                $line = sprintf(
                    '%s: %d %s %s%s%s',
                    $component,
                    $group->count(),
                    Str::plural('pod', $group->count()),
                    $reason,
                    $since ? ' since '.$since->format('Y-m-d H:i').' ('.$this->ago($since, $now).')' : '',
                    $this->text($first['message'] ?? null) !== '' ? ' — '.Str::limit($this->text($first['message']), 200) : '',
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
     * Pods that are not created at all (a quota) or not placed on a node,
     * as long as the events say so lately.
     *
     * @param  array<string, mixed>  $cluster
     * @param  list<string>  $warnings
     * @return array<string, string>
     */
    private function eventProblems(array $cluster, CarbonImmutable $now, array &$warnings): array
    {
        $problems = [];
        $unaffected = $this->eventsPastForComponents($cluster);

        foreach (Arr::wrap($cluster['events'] ?? []) as $event) {
            if (! is_array($event)) {
                continue;
            }

            $lastSeen = $this->time($event['last_seen'] ?? null);
            if ($lastSeen === null || $lastSeen->lt($now->subMinutes(self::EVENT_MINUTES))) {
                continue;
            }

            $reason = $this->text($event['reason'] ?? null, 'Warning');
            $owner = $this->owner($this->text($event['object'] ?? null));
            $line = sprintf(
                '%s: %s (%d×) — %s',
                $owner,
                $reason,
                $this->number($event['count'] ?? 1),
                Str::limit($this->text($event['message'] ?? null), 200),
            );

            if (in_array($reason, self::BLOCKING_EVENT_REASONS, true) && ! in_array($reason, $unaffected[$owner] ?? [], true)) {
                $problems["event:{$owner}:{$reason}"] ??= $line;
            } elseif (! in_array($line, $warnings, true)) {
                $warnings[] = $line;
            }
        }

        return $problems;
    }

    /**
     * The blocking events each component is past: what they said no longer
     * keeps its pods from running.
     *
     * - FailedCreate (pods not created, a quota) is past once all of the
     *   component's pods exist (`replicas`, or ready replicas from a server
     *   that does not report `replicas`); a pod that exists but does not
     *   start is a pod problem.
     * - FailedScheduling (pods not placed) is past once all replicas are ready.
     * - Both are past while a Deployment's rollout that has not stalled
     *   replaces its pods.
     *
     * A rollout in a namespace at its quota creates each new pod only once
     * the old one is gone, so every pod it replaces leaves a FailedCreate
     * behind for a moment; the events then stay recent for EVENT_MINUTES
     * after the component has recovered. A rollout is told by its old pods:
     * the Deployment has more pods than updated ones (`replicas` > `updated`).
     * A pod the quota keeps from being created counts in neither, so a lost
     * or never created pod outside a rollout stays a problem. A server that
     * does not report `replicas` is never taken to be rolling out, a rollout
     * that cannot go on counts again once its progress deadline passes, and
     * StatefulSets, which have no progress deadline, never roll out here.
     * A component that does not say how many replicas it has is past nothing.
     *
     * @param  array<string, mixed>  $cluster
     * @return array<string, list<string>>
     */
    private function eventsPastForComponents(array $cluster): array
    {
        $past = [];

        foreach (Arr::wrap($cluster['components'] ?? []) as $component) {
            if (
                ! is_array($component)
                || $this->text($component['name'] ?? null) === ''
                || ! array_key_exists('desired', $component)
                || ! array_key_exists('ready', $component)
            ) {
                continue;
            }

            $desired = $this->number($component['desired']);
            $ready = $this->number($component['ready']);
            $hasPodCount = array_key_exists('replicas', $component);
            $pods = $hasPodCount ? $this->number($component['replicas']) : $ready;
            $rollingOut = $this->text($component['kind'] ?? null) === 'Deployment'
                && $hasPodCount
                && array_key_exists('updated', $component)
                && $pods > $this->number($component['updated'])
                && ($component['rollout_stalled'] ?? false) !== true;

            $past[$this->text($component['name'])] = array_values(array_filter([
                $rollingOut || $pods >= $desired ? 'FailedCreate' : null,
                $rollingOut || $ready >= $desired ? 'FailedScheduling' : null,
            ]));
        }

        return $past;
    }

    /**
     * The component an object of a rollout belongs to, so that its key stays
     * the same across new pods and rollouts:
     * replicaset/cosmo-worker-dcb4c95d6 and pod/cosmo-worker-dcb4c95d6-2c5pw
     * are both cosmo-worker, statefulset/db and pod/db-0 are db.
     */
    private function owner(string $object): string
    {
        return preg_replace(
            [
                '#^replicaset/(.+)-[a-z0-9]{6,10}$#',
                '#^pod/(.+)-[a-z0-9]{6,10}-[a-z0-9]{5}$#',
                '#^pod/(.+)-[0-9]+$#',
                '#^(?:deployment|statefulset)/(.+)$#',
            ],
            ['$1', '$1', '$1', '$1'],
            $object,
        ) ?? $object;
    }

    /**
     * @param  array<string, mixed>  $problem
     */
    private function podComponent(array $problem): string
    {
        $component = $this->text($problem['component'] ?? null);
        if ($component !== '') {
            return $component;
        }

        $pod = $this->text($problem['pod'] ?? null, 'unknown');
        $owner = $this->owner("pod/{$pod}");

        return str_starts_with($owner, 'pod/') ? $pod : $owner;
    }

    private function reason(mixed $reason): string
    {
        $reason = $this->text($reason, 'unknown');

        return self::SAME_PROBLEM[$reason] ?? $reason;
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

            $queued = $this->number($activity['queued'] ?? 0);
            $lastCompletedAt = $this->time($activity['last_completed_at'] ?? null);

            if (
                $queued === 0
                || $this->number($activity['running'] ?? 0) > 0
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
     * Running items whose worker stopped reporting (its heartbeat). The
     * server counts them per step (activity.*.stale_running); an older one
     * only lists the running items with the age of their heartbeat.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function stuckWork(array $payload): array
    {
        $stuck = [];
        $activity = Arr::wrap($payload['activity'] ?? []);
        $counted = $activity !== [] && collect($activity)->contains(fn (mixed $step): bool => is_array($step) && array_key_exists('stale_running', $step));

        if ($counted) {
            foreach ($activity as $step => $values) {
                if (is_array($values) && $this->number($values['stale_running'] ?? 0) > 0) {
                    $stuck[(string) $step] = $this->number($values['stale_running']);
                }
            }
        } else {
            foreach (['molecule_steps', 'conformer_steps', 'cosmo'] as $group) {
                foreach (Arr::wrap(Arr::get($payload, "running.{$group}", [])) as $item) {
                    if (! is_array($item) || $this->number($item['heartbeat_age_seconds'] ?? 0) <= self::STUCK_HEARTBEAT_SECONDS) {
                        continue;
                    }

                    $step = $this->text($item['step'] ?? null, $group);
                    $stuck[$step] = ($stuck[$step] ?? 0) + 1;
                }
            }
        }

        $problems = [];

        foreach ($stuck as $step => $count) {
            $problems["stuck:{$step}"] = sprintf(
                'Step %s: %d running %s without a sign of life from the worker.',
                $step,
                $count,
                Str::plural('item', $count),
            );
        }

        return $problems;
    }

    /**
     * A string from the remote payload; anything else (a list, an object) is
     * the default, so a malformed answer cannot break the check.
     */
    private function text(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    private function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
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
