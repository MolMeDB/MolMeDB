@php
    $record  = $getRecord();
    $payload = $record->payload;

    $periods = ['day' => 'Today', 'week' => 'This week', 'month' => 'This month', 'year' => 'This year', 'total' => 'Total'];

    // The predictor counts every period from the start of the calendar unit (weeks start on Monday)
    // at the time the snapshot was fetched.
    $fetchedAt    = ($record->fetched_at ?? now())->copy()->timezone(config('app.timezone'));
    $periodStarts = [
        'day'   => $fetchedAt->copy()->startOfDay(),
        'week'  => $fetchedAt->copy()->startOfWeek(\Carbon\CarbonInterface::MONDAY),
        'month' => $fetchedAt->copy()->startOfMonth(),
        'year'  => $fetchedAt->copy()->startOfYear(),
    ];
    $steps   = [
        'rdkit'                  => 'RDKit',
        'conformers'             => 'Conformers',
        'optimization-orca'      => 'Opt. Orca',
        'optimization-turbomole' => 'Opt. Turbomole',
        'cosmo'                  => 'COSMO',
    ];

    if (! function_exists('psFmtDuration')) {
        function psFmtDuration(?float $sec): string {
            if ($sec === null || $sec < 0) return '—';
            if ($sec < 60) return round($sec) . 's';
            if ($sec < 3600) return round($sec / 60, 1) . 'm';
            return round($sec / 3600, 1) . 'h';
        }
    }

    if (! function_exists('psFmtNumber')) {
        function psFmtNumber(int $n): string {
            if ($n < 1000) return (string) $n;

            $suffix    = $n < 1_000_000 ? 'k' : 'M';
            $divisor   = $n < 1_000_000 ? 1_000 : 1_000_000;
            $formatted = rtrim(rtrim(sprintf('%.1f', $n / $divisor), '0'), '.');

            return $formatted . $suffix;
        }
    }

    if (! function_exists('psConic')) {
        function psConic(int $completed, int $failed, int $inProgress, int $total): string {
            if ($total === 0) return '#e5e7eb 0% 100%';
            $stops = [];
            $cum   = 0;
            $segments = [
                ['value' => $completed,  'color' => '#22c55e'],
                ['value' => $inProgress, 'color' => '#f97316'],
                ['value' => $failed,     'color' => '#ef4444'],
            ];
            foreach ($segments as $seg) {
                if ($seg['value'] <= 0) continue;
                $pct   = $seg['value'] / $total * 100;
                $from  = round($cum, 3);
                $to    = round($cum + $pct, 3);
                $stops[] = "{$seg['color']} {$from}% {$to}%";
                $cum  += $pct;
            }
            if ($cum < 100) {
                $stops[] = '#e5e7eb ' . round($cum, 3) . '% 100%';
            }
            return implode(', ', $stops);
        }
    }
@endphp

<div style="display:flex; flex-direction:column; gap:2rem;">
    @foreach ($periods as $pKey => $pLabel)
        @php $pdata = $payload['periods'][$pKey]['steps'] ?? []; @endphp

        <div>
            <div style="font-size:.8rem; font-weight:700; color:#374151; letter-spacing:.05em;
                        text-transform:uppercase; margin-bottom:.75rem; padding-bottom:.4rem;
                        border-bottom:1px solid #f3f4f6;">
                {{ $pLabel }}
                @isset ($periodStarts[$pKey])
                    <span style="font-weight:400; color:#9ca3af; text-transform:none; letter-spacing:0; margin-left:.35rem;">
                        {{ $pKey === 'day' ? $periodStarts[$pKey]->format('j M Y') : 'since '.$periodStarts[$pKey]->format('D j M Y') }}
                    </span>
                @endisset
            </div>

            <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:1rem;">
                @foreach ($steps as $sKey => $sLabel)
                    @php
                        $s          = $pdata[$sKey] ?? [];
                        $started    = (int) ($s['started']  ?? 0);
                        $completed  = (int) ($s['completed'] ?? 0);
                        $failed     = (int) ($s['failed']    ?? 0);
                        $inProgress = (int) ($s['running'] ?? max(0, $started - $completed - $failed));
                        $total      = (int) ($s['total'] ?? ($completed + $failed + $inProgress));
                        $avg        = isset($s['avg_duration_seconds']) ? (float) $s['avg_duration_seconds'] : null;
                        $conic      = psConic($completed, $failed, $inProgress, $total);
                        $hasData    = $total > 0;
                    @endphp

                    <div style="display:flex; flex-direction:column; align-items:center; gap:.6rem;
                                padding:.9rem .5rem; border-radius:.6rem; border:1px solid #f3f4f6;
                                background:#fff; box-shadow:0 1px 2px rgba(0,0,0,.04);">

                        <div style="font-size:.7rem; font-weight:600; color:#6b7280;
                                    letter-spacing:.02em; text-align:center;">
                            {{ strtoupper($sLabel) }}
                        </div>

                        {{-- Donut --}}
                        <div style="position:relative; width:112px; height:112px; flex-shrink:0;">
                            <div style="
                                width:112px; height:112px; border-radius:50%;
                                background: conic-gradient({{ $conic }});
                            "></div>
                            {{-- Hole --}}
                            <div style="
                                position:absolute; top:50%; left:50%;
                                transform:translate(-50%,-50%);
                                width:72px; height:72px; border-radius:50%;
                                background:#fff;
                                display:flex; flex-direction:column;
                                align-items:center; justify-content:center;
                                gap:1px;
                            ">
                                @if ($hasData)
                                    <span style="font-size:1.05rem; font-weight:700; color:#111827; line-height:1; white-space:nowrap;"
                                          title="{{ $completed }}/{{ $total }}">
                                        {{ psFmtNumber($completed) }}/{{ psFmtNumber($total) }}
                                    </span>
                                    <span style="font-size:.65rem; color:#9ca3af; line-height:1;">
                                        {{ psFmtDuration($avg) }}
                                    </span>
                                @else
                                    <span style="font-size:.7rem; color:#d1d5db;">—</span>
                                @endif
                            </div>
                        </div>

                        {{-- Legend --}}
                        @if ($hasData)
                            <div style="width:100%; display:flex; flex-direction:column; gap:.2rem;">
                                @if ($completed > 0)
                                    <div style="display:flex; justify-content:space-between; font-size:.65rem; color:#6b7280;">
                                        <span>Done</span>
                                        <span style="font-weight:600; color:#22c55e;" title="{{ $completed }}">{{ psFmtNumber($completed) }}</span>
                                    </div>
                                @endif
                                @if ($inProgress > 0)
                                    <div style="display:flex; justify-content:space-between; font-size:.65rem; color:#6b7280;">
                                        <span>Running now</span>
                                        <span style="font-weight:600; color:#f97316;" title="{{ $inProgress }}">{{ psFmtNumber($inProgress) }}</span>
                                    </div>
                                @endif
                                @if ($failed > 0)
                                    <div style="display:flex; justify-content:space-between; font-size:.65rem; color:#6b7280;">
                                        <span>Failed</span>
                                        <span style="font-weight:600; color:#ef4444;" title="{{ $failed }}">{{ psFmtNumber($failed) }}</span>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
