@php
    /**
     * @var array<int, array{title: string, caption: string, values: array<string, int>}> $charts
     */
    $stages = [
        'rdkit'                  => ['label' => 'RDKit',          'color' => '#6366f1'],
        'conformers'             => ['label' => 'Conformers',     'color' => '#f97316'],
        'optimization-orca'      => ['label' => 'Opt. Orca',      'color' => '#06b6d4'],
        'optimization-turbomole' => ['label' => 'Opt. Turbomole', 'color' => '#eab308'],
        'cosmo'                  => ['label' => 'COSMO',          'color' => '#ec4899'],
    ];

    if (! function_exists('psFmtNumber')) {
        function psFmtNumber(int $n): string {
            if ($n < 1000) return (string) $n;

            $suffix    = $n < 1_000_000 ? 'k' : 'M';
            $divisor   = $n < 1_000_000 ? 1_000 : 1_000_000;
            $formatted = rtrim(rtrim(sprintf('%.1f', $n / $divisor), '0'), '.');

            return $formatted . $suffix;
        }
    }
@endphp

<div style="display:flex; gap:3rem; flex-wrap:wrap; padding:.5rem 0;">
    @foreach ($charts as $chart)
        @php
            $values = [];
            $total  = 0;

            foreach ($stages as $key => $def) {
                $value = (int) ($chart['values'][$key] ?? 0);
                $values[$key] = $value;
                $total += $value;
            }

            $conic = '#e5e7eb 0% 100%';

            if ($total > 0) {
                $stops      = [];
                $cumulative = 0;

                foreach ($stages as $key => $def) {
                    $value = $values[$key];
                    if ($value <= 0) continue;
                    $pct  = $value / $total * 100;
                    $from = round($cumulative, 3);
                    $to   = round($cumulative + $pct, 3);
                    $stops[] = "{$def['color']} {$from}% {$to}%";
                    $cumulative += $pct;
                }

                $conic = implode(', ', $stops);
            }
        @endphp

        <div style="display:flex; flex-direction:column; gap:.75rem;">
            <div style="font-size:.8rem; font-weight:700; color:#374151; letter-spacing:.05em; text-transform:uppercase;">
                {{ $chart['title'] }}
            </div>

            <div style="display:flex; align-items:center; gap:2.5rem; flex-wrap:wrap;">
                {{-- Donut --}}
                <div style="position:relative; width:190px; height:190px; flex-shrink:0;">
                    <div style="
                        width:190px; height:190px; border-radius:50%;
                        background: conic-gradient({{ $conic }});
                    "></div>
                    <div style="
                        position:absolute; top:50%; left:50%;
                        transform:translate(-50%,-50%);
                        width:120px; height:120px; border-radius:50%;
                        background:#fff;
                        display:flex; flex-direction:column; align-items:center; justify-content:center;
                    ">
                        <span style="font-size:2rem; font-weight:700; color:#111827; line-height:1;" title="{{ $total }}">
                            {{ psFmtNumber($total) }}
                        </span>
                        <span style="font-size:.75rem; color:#9ca3af; margin-top:.25rem; text-align:center;">{{ $chart['caption'] }}</span>
                    </div>
                </div>

                {{-- Legend --}}
                <div style="display:flex; flex-direction:column; gap:.55rem; min-width:180px;">
                    @foreach ($stages as $key => $def)
                        @php $value = $values[$key]; @endphp
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; font-size:.85rem;">
                            <div style="display:flex; align-items:center; gap:.5rem;">
                                <span style="display:inline-block; width:11px; height:11px; border-radius:50%;
                                             background:{{ $value > 0 ? $def['color'] : '#e5e7eb' }}; flex-shrink:0;"></span>
                                <span style="color:#4b5563;">{{ $def['label'] }}</span>
                            </div>
                            <span style="font-weight:600; color:{{ $value > 0 ? $def['color'] : '#d1d5db' }};">
                                {{ psFmtNumber($value) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach
</div>
