<?php

namespace App\Services\Structures;

/**
 * What a scan of CanonicalSmilesConversion found: how many records are in
 * the canonical form already, how many it would convert, and the records it
 * leaves as they are (RDKit cannot read them, or their form collides).
 */
final readonly class CanonicalSmilesConversionPlan
{
    /**
     * @param  array<int, array{id: int, stored: string}>  $unreadable  the first of them
     * @param  array<int, array{form: string, records: array<int, array{id: int, identifier: string|null, stored: string, deleted: bool}>}>  $collisions
     */
    public function __construct(
        public string $target,
        public int $scanned,
        public int $unchanged,
        public int $convertible,
        public array $unreadable,
        public int $unreadableCount,
        public array $collisions,
    ) {}

    public function collidingRecords(): int
    {
        return array_sum(array_map(fn (array $group): int => count($group['records']), $this->collisions));
    }
}
