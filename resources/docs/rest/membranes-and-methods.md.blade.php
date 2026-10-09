---
title: Membranes and methods
position: 3
---
Passive interactions are measured on a **membrane** (a lipid bilayer, a cell layer or a tissue model, such as DOPC or Caco-2) by a **method** (an experimental assay, such as PAMPA, or a calculation, such as COSMOmic). Both are organized in categories, which the `/categories` endpoints return as trees with the membranes or methods of every category.

The interactions of a membrane or method are available in two forms:

- `/interactions`: a paginated JSON list with the filters of [`/interactions/passive`](/docs/rest/interactions);
- `/interactions/export`: all of them at once as a ZIP archive with a CSV file and the license, refreshed daily.

## Example: all permeabilities measured on POPC

**1. Find the `id` of the membrane** by its name or abbreviation:

```bash
curl '{!! $docs->apiUrl('membranes?query=POPC') !!}'
```

```json
{!! $docs->exampleResponse('membranes') !!}
```

**2. List its interactions** with the `id` from the first step (`15`), only those with a measured permeability. The records and their filters are the same as those of [`/interactions/passive`](/docs/rest/interactions):

```bash
curl '{!! $docs->apiUrl('membranes/15/interactions?with_value=logperm') !!}'
```

**Or download all of them at once.** The export is a ZIP archive with a CSV file of all passive interactions of the membrane, named for example `molmedb_POPC_2026-10-09.zip`:

```bash
curl -OJ '{!! $docs->apiUrl('membranes/15/interactions/export') !!}'
```

Methods work the same way: `/methods?query=PAMPA`, then `/methods/{method}/interactions` or `/methods/{method}/interactions/export`.

## Membranes

@include('docs::_partials.endpoints', ['endpoints' => $docs->endpoints('membranes*')])
## Methods

@include('docs::_partials.endpoints', ['endpoints' => $docs->endpoints('methods*')])
