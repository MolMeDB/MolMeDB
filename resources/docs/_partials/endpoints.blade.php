{{-- Endpoints of config/api_explorer.php: @include('docs::_partials.endpoints', ['endpoints' => $docs->endpoints('structures*')]) --}}
@foreach ($endpoints as $endpoint)
### `GET {{ $endpoint['path'] }}`

{{ $endpoint['description'] }}

@if ($endpoint['query'] !== [])
| Parameter | Description |
|---|---|
@foreach ($endpoint['query'] as $name => $parameter)
| `{{ $name }}`{{ $parameter['required'] ? ' (required)' : '' }} | {{ $docs->cell($parameter['description']) }} |
@endforeach

@endif
@if ($endpoint['is_download'])
Example (downloads a file): [`{!! $endpoint['example'] !!}`]({{ $endpoint['url'] }})
@else
Example: [`{!! $endpoint['example'] !!}`]({{ $endpoint['url'] }})
@endif

@endforeach
