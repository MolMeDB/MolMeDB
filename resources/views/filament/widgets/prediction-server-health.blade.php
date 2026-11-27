@php
    use App\Services\SystemHealth\HealthCheckResult;

    $badge = match ($assessment->status) {
        HealthCheckResult::STATUS_PROBLEM => ['danger', 'Problem'],
        HealthCheckResult::STATUS_WARNING => ['warning', 'Warning'],
        HealthCheckResult::STATUS_OK => ['success', 'OK'],
        default => ['gray', 'Off'],
    };
@endphp

<x-filament-widgets::widget>
    <div wire:poll.60s>
        <x-filament::section heading="Prediction server">
            <x-slot name="afterHeader">
                <div style="display:flex; align-items:center; gap:.75rem;">
                    @if ($assessment->fetchedAt)
                        <span style="font-size:.75rem; color:#9ca3af; white-space:nowrap;" title="{{ $assessment->fetchedAt->format('d.m.Y H:i:s') }}">
                            Answered {{ $assessment->fetchedAt->diffForHumans() }}
                        </span>
                    @endif
                    <x-filament::badge :color="$badge[0]">{{ $badge[1] }}</x-filament::badge>
                </div>
            </x-slot>

            <p style="margin:0 0 .5rem;">{{ $assessment->summary() }}</p>

            @if ($assessment->problems !== [])
                <ul style="margin:0 0 .75rem; padding-left:1.25rem; list-style:disc; color:#dc2626;">
                    @foreach ($assessment->problems as $problem)
                        <li>{{ $problem }}</li>
                    @endforeach
                </ul>
            @endif

            @if ($assessment->warnings !== [])
                <ul style="margin:0 0 .75rem; padding-left:1.25rem; list-style:disc; color:#d97706;">
                    @foreach ($assessment->warnings as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            @endif

            @if ($assessment->note && ! $disabled)
                <p style="margin:0 0 .75rem; font-size:.875rem; color:#6b7280;">{{ $assessment->note }}</p>
            @endif

            @if ($assessment->components !== [])
                <div style="display:flex; flex-wrap:wrap; gap:.5rem;">
                    @foreach ($assessment->components as $serverComponent)
                        <x-filament::badge
                            :color="$serverComponent['ready'] === 0 && $serverComponent['desired'] > 0 ? 'danger' : ($serverComponent['ready'] < $serverComponent['desired'] || $serverComponent['rollout_stalled'] ? 'warning' : 'gray')"
                        >
                            {{ $serverComponent['name'] }} {{ $serverComponent['ready'] }}/{{ $serverComponent['desired'] }}
                        </x-filament::badge>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
