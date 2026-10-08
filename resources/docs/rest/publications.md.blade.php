---
title: Publications
position: 5
---
Every interaction record has references:

- the **primary reference** is the publication its value comes from;
- the **secondary reference** belongs to the whole dataset the record was collected in, for example a review, another database or the publication describing a calculation.

The interactions of a publication include both: records with the publication as their primary reference and all records of datasets with it as their secondary reference. The same holds for the `publication` filter of [`/interactions`](/docs/rest/interactions) and for the daily exports.

## Endpoints

@include('docs::_partials.endpoints', ['endpoints' => $docs->endpoints('publications*')])
