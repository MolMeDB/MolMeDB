---
title: Proteins
position: 4
---
Proteins are the membrane transporters of active interactions, identified by their `id` and their [UniProt](https://www.uniprot.org) accession (`uniprot_id`). Their categories group them into transporter families.

The interactions of a protein take the filters of [`/interactions/active`](/docs/rest/interactions); to find interactions by the UniProt accession directly, use `/interactions/active?uniprot=O15245`.

## Example: substrates of the transporter OCT2 (SLC22A2)

**1. Find the protein** by its gene name or UniProt accession:

```bash
curl '{!! $docs->apiUrl('proteins?query=SLC22A2') !!}'
```

```json
{!! $docs->exampleResponse('proteins') !!}
```

**2. List its substrates** by the UniProt accession from the first step (`O15244`). The same list is returned by `/proteins/1/interactions?type=Substrate`, with the `id` of the protein:

```bash
curl '{!! $docs->apiUrl('interactions/active?uniprot=O15244&type=Substrate') !!}'
```

Every record has the `type` of the interaction and the measured values (`km`, `ec50`, `ki`, `ic50`) as negative decimal logarithms of the constants in mol/L; values not measured are `null`:

```json
{!! $docs->exampleResponse('guide.interactions-active-substrates') !!}
```

## Endpoints

@include('docs::_partials.endpoints', ['endpoints' => $docs->endpoints('proteins*')])
