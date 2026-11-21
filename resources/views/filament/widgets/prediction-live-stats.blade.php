<x-filament-widgets::widget>
    <x-filament::section heading="Live prediction stats">
        <x-slot name="afterHeader">
            <div style="display:flex; align-items:center; gap:.75rem;">
                @if ($lastUpdatedAt)
                    <span style="font-size:.75rem; color:#9ca3af; white-space:nowrap;" title="{{ $lastUpdatedAt->format('d.m.Y H:i:s') }}">
                        Updated {{ $lastUpdatedAt->diffForHumans() }}
                    </span>
                @else
                    <span style="font-size:.75rem; color:#9ca3af; font-style:italic; white-space:nowrap;">Not fetched yet</span>
                @endif

                @if ($detailUrl)
                    <x-filament::link
                        color="gray"
                        :href="$detailUrl"
                        :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowTopRightOnSquare"
                    >
                        View details
                    </x-filament::link>
                @endif
            </div>
        </x-slot>

        @include('filament.prediction-stats.live-stats-chart', ['charts' => $charts])
    </x-filament::section>
</x-filament-widgets::widget>
