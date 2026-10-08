---
title: RDF data access
---
@php($base = $docs->fair('rdf.base_url'))
The MolMeDB RDF dataset can be used in three ways.

## Download

The newest version of the whole dataset in RDF/XML, Turtle and N-Triples is archived on Zenodo: [{{ $docs->fair('rdf.dump_url') }}]({{ $docs->fair('rdf.dump_url') }}). Import it into a triplestore to query it locally or to merge it with other datasets.

## SPARQL endpoint

The newest version can be queried online at [{{ $docs->fair('rdf.sparql_endpoint') }}]({{ $docs->fair('rdf.sparql_endpoint') }}), provided by the [IDSM](https://idsm.elixir-czech.cz) database. It also supports substructure and similarity searches and federated queries; see the [examples](/docs/rdf/sparql-endpoint).

## Dereferencing IRIs

Every IRI of the MolMeDB RDF namespace, `{{ $base }}/…`, can be opened. For example [`{{ $base }}/interaction/int1157`]({{ $base }}/interaction/int1157) describes one passive interaction, and [`{{ $docs->fair('rdf.vocabulary_url') }}`]({{ $docs->fair('rdf.vocabulary_url') }}) the MolMeDB vocabulary.

- A web browser is redirected to the page about the resource, or gets a simple list of its statements.
- An RDF client gets the statements about the resource in the format of its `Accept` header:

| Format | Media type | Or add to the IRI |
|---|---|---|
@foreach ($docs->rdfFormats() as $name => [$mediaType, $extension])
| {{ $name }} | `{{ $mediaType }}` | `?format={{ $name }}` |
@endforeach

```bash
curl -H 'Accept: text/turtle' {!! $base !!}/interaction/int1157
```

The statements come from the SPARQL endpoint, so they show the newest published version of the dataset. The same records are also available from the [REST API](/docs/rest), where every interaction links its IRI in the field `rdf`.
