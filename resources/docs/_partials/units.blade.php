{{-- Units of the measured values: @include('docs::_partials.units', ['type' => 'interactions_passive']) --}}
| Field | Unit | Meaning |
|---|---|---|
@foreach ($docs->units()[$type] as $field => $unit)
| `{{ $field }}` | {{ $unit['unit'] }} | {{ $docs->cell($unit['description']) }} |
@endforeach
