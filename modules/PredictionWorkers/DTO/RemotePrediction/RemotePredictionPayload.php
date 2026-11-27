<?php

namespace Modules\PredictionWorkers\DTO\RemotePrediction;

use Carbon\CarbonImmutable;
use Modules\PredictionWorkers\Enums\RemotePredictionStatus;
use Modules\PredictionWorkers\Enums\RemotePredictionStep;
use Throwable;

final class RemotePredictionPayload
{
    public static function parse(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            return collect($value)
                ->mapWithKeys(fn (mixed $item, int|string $itemKey): array => [
                    $itemKey => self::parse($item, is_string($itemKey) ? $itemKey : null),
                ])
                ->all();
        }

        if (! is_string($value)) {
            return $value;
        }

        if ($key !== null && (str_ends_with($key, '_at') || $key === 'created_at')) {
            if ($value === '') {
                return null;
            }

            // One unreadable time must not lose the whole answer.
            try {
                return CarbonImmutable::parse($value);
            } catch (Throwable) {
                return $value;
            }
        }

        if ($key === 'status') {
            return RemotePredictionStatus::tryFrom($value) ?? $value;
        }

        if ($key === 'step' || $key === 'current_step') {
            return RemotePredictionStep::tryFrom($value) ?? $value;
        }

        return $value;
    }

    public static function serialize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof CarbonImmutable => $value->toISOString(),
            $value instanceof RemotePredictionStatus, $value instanceof RemotePredictionStep => $value->value,
            is_array($value) => collect($value)
                ->map(fn (mixed $item): mixed => self::serialize($item))
                ->all(),
            default => $value,
        };
    }
}
