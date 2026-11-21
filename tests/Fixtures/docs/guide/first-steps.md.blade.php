---
title: First steps
position: 3
---
| Value | Unit |
|---|---|
@foreach ($docs->units()['interactions_passive'] as $name => $unit)
| {{ $name }} | {{ $unit['unit'] }} |
@endforeach
