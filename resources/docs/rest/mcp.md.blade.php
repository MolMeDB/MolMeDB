---
title: MCP server
position: 6
---
@php($limits = $docs->rateLimits())
The [Model Context Protocol](https://modelcontextprotocol.io) (MCP) lets AI assistants and agents use outside data through tools. The MolMeDB MCP server offers the data of the [REST API](/docs/rest) as such tools, so an assistant can search structures and their measured interactions while answering a question.

- **URL**: `{{ $docs->apiUrl('mcp') }}`
- **Transport**: Streamable HTTP
- **Authentication**: none, the server is public and read-only
- **Limits**: the same as the REST API, {{ $limits['requests']['per_minute'] }} requests per minute, stricter for substructure and similarity searches

## Connecting

In [Claude Code](https://claude.com/claude-code):

```bash
claude mcp add --transport http molmedb {!! $docs->apiUrl('mcp') !!}
```

In other clients, add a remote MCP server of the HTTP (Streamable HTTP) type with the URL above. For example, in a client configured by JSON:

```json
{
  "mcpServers": {
    "molmedb": {
      "type": "http",
      "url": "{!! $docs->apiUrl('mcp') !!}"
    }
  }
}
```

## How an assistant uses it

The server describes itself to the assistant, and its resource `molmedb-overview` explains the data, the values and their units. A typical question, such as *"How permeable is caffeine through a DOPC membrane?"*, goes like this:

1. find the structure: `search-structures` by name, SMILES, InChIKey or an identifier of another database;
2. find the membrane: `search-membranes`;
3. get the records: `search-interactions` with the structure, the membrane and, for example, `with_value=logperm`;
4. for a molecule without data, `find-similar-structures` lists measured analogues.

The tools return the same records as the REST API, including their API URLs, so answers can be checked and cited.

## Calling a tool without an assistant

The server speaks JSON-RPC over HTTP, so a tool can be tried, or used from a script, with a single `POST` request; no session is needed. The request names the tool and its arguments (listed under [Tools](#tools) below):

```bash
curl -X POST '{!! $docs->apiUrl('mcp') !!}' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": "search-structures", "arguments": {"query": "caffeine"}}}'
```

The result is in `result.structuredContent`, the same records as the REST API returns; `result.content` holds the same data as text for the assistant:

```json
{!! $docs->exampleResponse('guide.mcp-tools-call') !!}
```

The method `tools/list` (`{"jsonrpc": "2.0", "id": 1, "method": "tools/list"}`) returns all tools with the JSON Schema of their arguments.

## Tools

@foreach ($docs->mcpTools() as $tool)
### `{{ $tool['name'] }}`

{{ $tool['description'] }}

@if ($tool['parameters'] !== [])
| Parameter | Type | Description |
|---|---|---|
@foreach ($tool['parameters'] as $name => $parameter)
| `{{ $name }}`{{ $parameter['required'] ? ' (required)' : '' }} | {{ $docs->cell($parameter['type']) }} | {{ $docs->cell($parameter['description']) }} |
@endforeach
@endif

@endforeach
