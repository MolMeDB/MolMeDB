---
title: Publications
position: 5
---
Every interaction record has references:

- the **primary reference** is the publication its value comes from;
- the **secondary reference** belongs to the whole dataset the record was collected in, for example a review, another database or the publication describing a calculation.

The interactions of a publication include both: records with the publication as their primary reference and all records of datasets with it as their secondary reference. The same holds for the `publication` filter of [`/interactions`](/docs/rest/interactions) and for the daily exports.

## Example: data from one article

**1. Find the publication** by its DOI, title or authors:

```bash
curl '{!! $docs->apiUrl('publications?query=10.1016/j.bmc.2011.08.058') !!}'
```

```json
{!! $docs->exampleResponse('guide.publications-doi') !!}
```

**2. See what it contains.** The statistics count its records:

```bash
curl '{!! $docs->apiUrl('publications/1262/stats') !!}'
```

**3. Get the records**, here its active interactions (the publication has no passive ones), as JSON pages:

```bash
curl '{!! $docs->apiUrl('publications/1262/interactions/active') !!}'
```

or all at once as a ZIP archive with a CSV file:

```bash
curl -OJ '{!! $docs->apiUrl('publications/1262/interactions/active/export') !!}'
```

## Endpoints

@include('docs::_partials.endpoints', ['endpoints' => $docs->endpoints('publications*')])
