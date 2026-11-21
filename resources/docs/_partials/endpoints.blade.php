{{-- Endpoints of config/api_explorer.php: @include('docs::_partials.endpoints', ['endpoints' => $docs->endpoints('structures*')]) --}}
@foreach ($endpoints as $endpoint)
### `GET {{ $endpoint['path'] }}`

{{ $endpoint['description'] }}

@if ($endpoint['path_parameters'] !== [])
| Path parameter | Description | Example |
|---|---|---|
@foreach ($endpoint['path_parameters'] as $name => $parameter)
| `{{ '{'.$name.'}' }}` | {{ $docs->cell($parameter['label']) }} | `{{ $parameter['example'] }}` |
@endforeach

@endif
@if ($endpoint['query'] !== [])
| Query parameter | Description |
|---|---|
@foreach ($endpoint['query'] as $name => $parameter)
| `{{ $name }}`{{ $parameter['required'] ? ' (required)' : '' }} | {{ $docs->cell($parameter['description']) }} |
@endforeach

@endif
@if ($endpoint['is_download'])
**Example request** (downloads a file):

```bash
curl -OJ '{!! $endpoint['url'] !!}'
```
@else
**Example request:**

```bash
curl '{!! $endpoint['url'] !!}'
```
@endif

@if ($endpoint['response'] !== null)
<details class="docs-response">
<summary>Example response (shortened)</summary>

```{{ str_starts_with($endpoint['response'], '{') ? 'json' : 'text' }}
{!! $endpoint['response'] !!}
```

</details>

@endif
@endforeach
