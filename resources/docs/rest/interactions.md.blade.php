---
title: Interactions
position: 2
---
@php($pages = $docs->pageLimits())
An interaction record is one measured or calculated value set of one structure:

- **Passive interactions** describe a structure and a lipid membrane, measured or calculated by a method: its permeability (LogPerm), partitioning (LogK), the position of its energy minimum (Xmin) and the energy barriers (Gpen, Gwat).
- **Active interactions** describe a structure and a membrane transporter protein: pKm, pEC50, pKi and pIC50 values, and the `type` of the interaction (for example *Substrate*, *Non-substrate*, *Inhibitor*, *Non-inhibitor*). A structure is a substrate when its pKm or pEC50 is at least 5, and an inhibitor when its pKi or pIC50 is at least 5.

The meaning of the values is described in [What is stored in MolMeDB?](/docs/about/about-data), their units on the [overview page](/docs/rest).

## Example: permeability of caffeine through DOPC

Combine the filters in one request: the structure (`MM00040`, found by [`/structures`](/docs/rest/structures)), the membrane (DOPC has the `id` 3, found by [`/membranes?query=DOPC`](/docs/rest/membranes-and-methods)) and only records with a measured permeability (`with_value=logperm`):

```bash
curl '{!! $docs->apiUrl('interactions/passive?structure=MM00040&membrane=3&with_value=logperm') !!}'
```

Every item of `data` is one record: the value (`logperm`, in log<sub>10</sub>(cm/s)) and its error (`logperm_accuracy`), the conditions, the method and the publication the value comes from. Values not measured in the record are `null`. `meta.total` is the number of records found.

```json
{!! $docs->exampleResponse('guide.interactions-passive-logperm') !!}
```

Active interactions are filtered the same way, for example all substrates of the transporter with the UniProt accession O15245:

```bash
curl '{!! $docs->apiUrl('interactions/active?uniprot=O15245&type=Substrate') !!}'
```

In Python, read the records into a table, for example with [pandas](https://pandas.pydata.org):

```python
import pandas as pd
import requests

API = "{!! $docs->apiUrl() !!}"

response = requests.get(f"{API}/interactions/passive", params={
    "structure": "MM00040",
    "membrane": 3,
    "with_value": "logperm",
})
records = response.json()["data"]

table = pd.json_normalize(records)  # nested objects become columns such as "method.abbreviation"
print(table[["method.abbreviation", "temperature", "logperm", "primary_reference.citation"]])
```

A list has at most {{ $pages['interactions'] }} records per page; how to read all pages is shown on the [overview page](/docs/rest).

## Listing and filtering

`GET /interactions/passive` and `GET /interactions/active` list the records of the whole database, ordered by `id`, at most {{ $pages['interactions'] }} per page. Filters narrow the list; all given filters apply together. The same filters work on the interaction listings of a structure, membrane, method, protein and publication.

| Filter | Passive | Active | Meaning |
|---|---|---|---|
| `structure` | yes | yes | MolMeDB identifier of the structure, for example `MM00040`. |
| `membrane`, `method` | yes | | `id` of the membrane or method. |
| `protein`, `uniprot` | | yes | `id` of the protein, or its UniProt id (`O15245`). |
| `type` | | yes | Type of the interaction, for example `Substrate` (case-insensitive). |
| `publication` | yes | yes | `id` of a publication: the primary reference of the record or the reference of its whole dataset. |
| `charge` | yes | yes | Charge of the measured form as recorded, for example `0`, `1`, `-1`; `1` also matches `+1`. |
| `temperature_min`, `temperature_max` | yes | yes | Temperature range in °C. |
| `ph_min`, `ph_max` | yes | yes | pH range. |
| `with_value` | yes | yes | Only records with this value measured: `logperm`, `logk`, `gpen`, `gwat` or `x_min` for passive, `km`, `ec50`, `ki` or `ic50` for active ones. |
| `<value>_min`, `<value>_max` | yes | yes | Range of a value, for example `logperm_min=-6&logperm_max=-4`. The limits are included. |

Find the `id` of a membrane, method, protein or publication with their search endpoints, for example `/membranes?query=DOPC`.

## What a record contains

- `id`, its API `url` and `rdf`, the IRI of the same record in the [MolMeDB RDF](/docs/rdf) graph;
- `structure`: identifier, name and URL of the structure;
- `membrane` and `method` (passive), or `protein` and `type` (active);
- the conditions: `temperature`, `ph`, `charge` and a `note`;
- the values with their `_accuracy`, `null` when not measured;
- `primary_reference`, the publication the value comes from, and `secondary_reference`, the publication of the whole dataset (for example a review or a database the value was collected from).

Uploaded datasets become public after the MolMeDB team approves them, record by record as they are imported. Records of deleted datasets and of structures still being curated are not public.

## Endpoints

@include('docs::_partials.endpoints', ['endpoints' => $docs->endpoints('interactions*')])
To download all interactions of a membrane, method or publication at once, use their daily [exports](/docs/rest/membranes-and-methods).
