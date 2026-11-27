<?php

namespace App\Services\SystemHealth;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * What PredictionServerHealth found about the remote prediction server.
 *
 * Problems are keyed by what is wrong (a component, a step, a reason), not by
 * counts or times, so the same outage keeps the same keys from one check to
 * the next and only a new kind of trouble changes them.
 */
final class PredictionServerAssessment
{
    public const STATUS_DISABLED = 'disabled';

    /**
     * @param  array<string, string>  $problems  key => description
     * @param  list<string>  $warnings
     * @param  list<array{name: string, kind: string, desired: int, ready: int, rollout_stalled: bool}>  $components
     */
    public function __construct(
        public readonly string $status,
        public readonly array $problems = [],
        public readonly array $warnings = [],
        public readonly array $components = [],
        public readonly ?CarbonInterface $fetchedAt = null,
        public readonly ?string $note = null,
    ) {}

    public static function disabled(): self
    {
        return new self(self::STATUS_DISABLED, note: 'Remote predictions are switched off.');
    }

    public function hasProblems(): bool
    {
        return $this->problems !== [];
    }

    public function summary(): string
    {
        if ($this->status === self::STATUS_DISABLED) {
            return (string) $this->note;
        }

        $counts = array_filter([
            $this->problems !== [] ? count($this->problems).' '.Str::plural('problem', count($this->problems)) : null,
            $this->warnings !== [] ? count($this->warnings).' '.Str::plural('warning', count($this->warnings)) : null,
        ]);

        if ($counts !== []) {
            return Str::ucfirst(implode(', ', $counts)).'.';
        }

        $ready = count(array_filter($this->components, fn (array $component): bool => $component['ready'] >= $component['desired']));

        return $this->components === []
            ? 'The server answers and its queues move.'
            : "All {$ready} components run and the queues move.";
    }

    public function toHealthCheckResult(): HealthCheckResult
    {
        $details = [...array_values($this->problems), ...$this->warnings];

        if ($this->note !== null && $this->status !== self::STATUS_DISABLED) {
            $details[] = $this->note;
        }

        return new HealthCheckResult(
            PredictionServerHealth::LABEL,
            $this->status === self::STATUS_DISABLED ? HealthCheckResult::STATUS_OK : $this->status,
            $this->summary(),
            $details,
        );
    }
}
