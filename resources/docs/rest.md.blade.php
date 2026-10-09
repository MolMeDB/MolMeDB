---
title: API & MCP
---
@php($limits = $docs->rateLimits())
@php($pages = $docs->pageLimits())
MolMeDB data can be read by programs in three ways:

- **REST API**, at [`{{ $docs->apiUrl() }}`]({{ $docs->apiUrl() }}): structures, membranes, methods, proteins, publications and all interaction records, with filters. It is described on this page and the pages below.
- **MCP server**, at `{{ $docs->apiUrl('mcp') }}`: the same data as tools for AI assistants and agents. See [MCP server](/docs/rest/mcp).
- **RDF and SPARQL**: the whole database as a knowledge graph, for semantic queries and federation with other databases. See [MolMeDB RDF](/docs/rdf).

Both interfaces are free to use, read-only and need no registration or key. The data are published under the [{{ $docs->fair('data_license.name') }}]({{ $docs->fair('data_license.url') }}) license. If you use them, please cite: {{ $docs->fair('citation.text') }}.

## Quick start

Every endpoint is a plain HTTPS `GET` request: the address of the endpoint followed by its parameters after `?`. No key and no special header are needed. For example, this request looks up caffeine. **Try it!** opens it in a new tab, **Copy curl** copies it as a command for the terminal:

```bash
curl '{!! $docs->apiUrl('structures?query=caffeine') !!}'
```

The response is JSON. The structures found are in `data`; `links` and `meta` describe the pages of the result:

```json
{!! $docs->exampleResponse('structures') !!}
```

The response here is shortened, as in all examples of this documentation: lists show only their first items.

The `identifier` of the structure (`MM00040`) is what the other endpoints take:

```bash
# Detail of the structure: SMILES, InChIKey, identifiers in other databases, forms
curl '{!! $docs->apiUrl('structures/MM00040') !!}'

# Its passive interactions on the DOPC membrane (id 3), only those with a measured permeability
curl '{!! $docs->apiUrl('interactions/passive?structure=MM00040&membrane=3&with_value=logperm') !!}'
```

In a web browser, the URL of an endpoint opens the API explorer: a form for its parameters and the live response. This is what **Try it!** shows.

### The same in Python

With the [requests](https://requests.readthedocs.io) library, pass the parameters as a dictionary and read the JSON:

```python
import requests

API = "{!! $docs->apiUrl() !!}"

response = requests.get(f"{API}/structures", params={"query": "caffeine"})
response.raise_for_status()

for structure in response.json()["data"]:
    print(structure["identifier"], structure["name"], structure["logp"])
# MM00040 Caffeine -1.03
```

### Reading all pages of a result

A list returns one page of records (at most {{ $pages['interactions'] }} for interactions, set by `per_page`). To read all of them, follow `links.next` until it is `null`:

```python
url = f"{API}/interactions/passive"
params = {"membrane": 3, "with_value": "logperm", "per_page": {{ $pages['interactions'] }}}
records = []

while url:
    response = requests.get(url, params=params)
    response.raise_for_status()
    page = response.json()
    records.extend(page["data"])
    url = page["links"]["next"]  # None on the last page
    params = None                # the next URL already contains the parameters

print(len(records), "records")
```

For whole membranes, methods or publications, the daily [exports](/docs/rest/membranes-and-methods) are faster than paging.

## Reference

- [Structures](/docs/rest/structures): search (name, SMILES, substructure, InChIKey, external identifiers), detail, forms, 3D structure, similar structures.
- [Interactions](/docs/rest/interactions): passive and active interaction records across the database, with filters and units.
- [Membranes and methods](/docs/rest/membranes-and-methods), [Proteins](/docs/rest/proteins), [Publications](/docs/rest/publications).
- [MCP server](/docs/rest/mcp): tools for AI assistants.

The complete machine-readable description is the [OpenAPI specification]({{ $docs->apiUrl('openapi.json') }}), also rendered as [interactive documentation]({{ $docs->apiUrl('docs') }}).

## Requests and responses

- **Base URL and version.** All endpoints are under `{{ $docs->apiUrl() }}`. The version is part of the path; a change that breaks clients will come as a new version.
- **Methods.** All endpoints are read with `GET`. Cross-origin requests from any website are allowed (CORS).
- **Format.** Responses are JSON with the result in `data`. Lists are paginated: `links` holds the URLs of the first, previous and next page, and `meta` the current page and the number of results. Request a page with `page` and its size with `per_page`. Similarity and substructure searches have no total count and no last page.
- **JSON-LD.** With the header `Accept: application/ld+json`, the details of a structure, membrane, method, protein, publication and of the dataset (`/about`) are returned as [schema.org](https://schema.org) / [Bioschemas](https://bioschemas.org) JSON-LD.
- **Browsers.** A request that accepts `text/html` (a web browser) gets the API explorer page instead of the JSON.

## Identifiers

Structures are identified by their MolMeDB identifier, for example `MM00040` (a [registered identifier scheme](https://registry.identifiers.org/registry/{{ $docs->fair('identifiers_org_namespace') }})). Forms of a molecule, such as its ionization states or stereoisomers, have the identifier of the molecule followed by a number: `MM00045.1`, `MM00045.2`. Membranes, methods, proteins, publications and interactions are identified by their numeric `id`.

Identifiers are persistent. When two records of the same molecule are merged, the endpoints of the removed identifier answer `301 Moved Permanently` with the URL of the surviving structure in `Location`. A removed structure answers `410 Gone` with what is still known about it.

## Status codes

| Code | Meaning |
|---|---|
| `200` | The request succeeded. |
| `301` | The structure was merged into another one; follow `Location`. |
| `404` | The record or the endpoint does not exist. |
| `410` | The structure was removed. |
| `422` | A parameter is not valid; `errors` lists the problems by parameter. |
| `429` | Too many requests; wait the number of seconds in `Retry-After`. |

## Rate limits

Every client IP address can make {{ $limits['requests']['per_minute'] }} requests per minute and {{ number_format($limits['requests']['per_day'], 0, '.', ' ') }} requests per day. The searches that compare chemical structures are more demanding and have stricter limits on top of it:

| Search | Per minute | Per hour |
|---|---|---|
| Substructure search (`/structures?substructure=`) | {{ $limits['substructure']['per_minute'] }} | {{ $limits['substructure']['per_hour'] }} |
| Similar structures (`/structures/{identifier}/similar`) | {{ $limits['similarity']['per_minute'] }} | {{ $limits['similarity']['per_hour'] }} |

The headers `X-RateLimit-Limit` and `X-RateLimit-Remaining` of every response show how many requests are left. To download large parts of the database, use the daily exports (for example [`/membranes/{membrane}/interactions/export`](/docs/rest/membranes-and-methods)) or the [RDF dump](/docs/rdf/rdf-data-access) rather than paging through the API.

## Units

Measured values are returned as numbers in these units. `GET /about` returns the same table.

Passive interactions (a molecule and a membrane):

@include('docs::_partials.units', ['type' => 'interactions_passive'])

Active interactions (a molecule and a transporter protein). Each value is the negative decimal logarithm of the constant in mol/L, so a higher value means a stronger effect:

@include('docs::_partials.units', ['type' => 'interactions_active'])

Every value has an `_accuracy` field with the reported error, when known.

## About the dataset

@include('docs::_partials.endpoints', ['endpoints' => $docs->endpoints('about')])
`/about` describes MolMeDB as a dataset: license, citation, contact, the identifier scheme, links to the RDF dump, the SPARQL endpoint, the OpenAPI specification and the MCP server, and the units above.

If you miss an endpoint or a filter, write to [{{ $docs->fair('contact_email') }}](mailto:{{ $docs->fair('contact_email') }}).
