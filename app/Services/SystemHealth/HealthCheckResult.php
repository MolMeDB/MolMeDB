<?php

namespace App\Services\SystemHealth;

/**
 * What one check of the system health report found.
 */
class HealthCheckResult
{
    public const STATUS_OK = 'ok';

    public const STATUS_WARNING = 'warning';

    public const STATUS_PROBLEM = 'problem';

    /**
     * @param  list<string>  $details
     */
    public function __construct(
        public readonly string $label,
        public readonly string $status,
        public readonly string $summary,
        public readonly array $details = [],
    ) {}

    /**
     * @param  list<string>  $details
     */
    public static function ok(string $label, string $summary, array $details = []): self
    {
        return new self($label, self::STATUS_OK, $summary, $details);
    }

    /**
     * @param  list<string>  $details
     */
    public static function warning(string $label, string $summary, array $details = []): self
    {
        return new self($label, self::STATUS_WARNING, $summary, $details);
    }

    /**
     * @param  list<string>  $details
     */
    public static function problem(string $label, string $summary, array $details = []): self
    {
        return new self($label, self::STATUS_PROBLEM, $summary, $details);
    }
}
