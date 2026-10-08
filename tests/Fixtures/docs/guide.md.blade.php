---
title: Guide
---
Intro with {{ $docs->apiUrl('about') }}.

## Example

```bash
curl {{ $docs->apiUrl('about') }}
```

> [!WARNING]
> Mind the limits.

@include('docs::_partials.note', ['text' => 'From a partial'])
