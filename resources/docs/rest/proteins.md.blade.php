---
title: Proteins
position: 4
---
Proteins are the membrane transporters of active interactions, identified by their `id` and their [UniProt](https://www.uniprot.org) accession (`uniprot_id`). Their categories group them into transporter families.

The interactions of a protein take the filters of [`/interactions/active`](/docs/rest/interactions); to find interactions by the UniProt accession directly, use `/interactions/active?uniprot=O15245`.

## Endpoints

@include('docs::_partials.endpoints', ['endpoints' => $docs->endpoints('proteins*')])
