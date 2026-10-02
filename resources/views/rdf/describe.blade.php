@php
    $prefixes = [
        'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
        'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
        'xsd' => 'http://www.w3.org/2001/XMLSchema#',
        'skos' => 'http://www.w3.org/2004/02/skos/core#',
        'owl' => 'http://www.w3.org/2002/07/owl#',
        'sio' => 'http://semanticscience.org/resource/',
        'bao' => 'http://www.bioassayontology.org/bao#',
        'obo' => 'http://purl.obolibrary.org/obo/',
        'prov' => 'http://www.w3.org/ns/prov#',
        'dcterms' => 'http://purl.org/dc/terms/',
        'bibo' => 'http://purl.org/ontology/bibo/',
        'edam' => 'http://edamontology.org/',
        'efo' => 'http://www.ebi.ac.uk/efo/',
        'repr' => 'https://w3id.org/reproduceme#',
        'mmdbvoc' => 'https://rdf.molmedb.upol.cz/vocabulary#',
        'mmdbmol' => 'https://identifiers.org/molmedb/',
        'mmdbsub' => 'https://rdf.molmedb.upol.cz/substance/',
        'mmdbint' => 'https://rdf.molmedb.upol.cz/interaction/',
        'mmdbtra' => 'https://rdf.molmedb.upol.cz/transporter/',
        'mmdbref' => 'https://rdf.molmedb.upol.cz/reference/',
    ];

    $compact = function (?string $iri) use ($prefixes): ?string {
        if ($iri === null) {
            return null;
        }

        foreach ($prefixes as $prefix => $namespace) {
            if (str_starts_with($iri, $namespace)) {
                return $prefix.':'.substr($iri, strlen($namespace));
            }
        }

        return $iri;
    };
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $compact($iri) }} | MolMeDB RDF</title>
    @foreach ($formats as $name => [$mimeType])
        <link rel="alternate" type="{{ $mimeType }}" href="{{ $iri }}?format={{ $name }}">
    @endforeach
    <style>
        :root { color-scheme: light dark; --fg: #111827; --muted: #6b7280; --line: #e5e7eb; --bg: #fff; --link: #2563eb; }
        @media (prefers-color-scheme: dark) { :root { --fg: #f3f4f6; --muted: #9ca3af; --line: #374151; --bg: #111827; --link: #60a5fa; } }
        body { margin: 0; padding: 2rem 1rem; font-family: system-ui, sans-serif; color: var(--fg); background: var(--bg); }
        main { max-width: 960px; margin: 0 auto; }
        h1 { font-size: 1.25rem; word-break: break-all; margin: 0 0 .25rem; }
        p { color: var(--muted); margin: 0 0 1.5rem; font-size: .9rem; }
        table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        th, td { text-align: left; vertical-align: top; padding: .5rem; border-bottom: 1px solid var(--line); word-break: break-word; }
        th { color: var(--muted); font-weight: 600; }
        a { color: var(--link); }
        .datatype, .label { color: var(--muted); font-size: .8rem; }
        footer { margin-top: 2rem; font-size: .85rem; color: var(--muted); }
    </style>
</head>
<body>
<main>
    <h1>{{ $iri }}</h1>
    <p>
        Resource of the <a href="{{ config('fair.frontend_url') }}">MolMeDB</a> RDF dataset.
        Download as
        @foreach ($formats as $name => [$mimeType])
            <a href="{{ $iri }}?format={{ $name }}">{{ $mimeType }}</a>@if (! $loop->last), @endif
        @endforeach
        or query it at the <a href="{{ config('fair.rdf.sparql_endpoint') }}">SPARQL endpoint</a>.
    </p>

    <table>
        <thead>
            <tr><th>Predicate</th><th>Object</th></tr>
        </thead>
        <tbody>
            @foreach ($statements as $statement)
                <tr>
                    <td><a href="{{ $statement['predicate'] }}">{{ $compact($statement['predicate']) }}</a></td>
                    <td>
                        @if ($statement['object_type'] === 'uri')
                            <a href="{{ $statement['object'] }}">{{ $compact($statement['object']) }}</a>
                            @if ($statement['label'])
                                <span class="label">({{ $statement['label'] }})</span>
                            @endif
                        @else
                            {{ $statement['object'] }}
                            @if ($statement['datatype'])
                                <span class="datatype">^^{{ $compact($statement['datatype']) }}</span>
                            @endif
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <footer>
        Data: {{ config('fair.data_license.name') }} ·
        Schema: <a href="{{ config('fair.related_publications.0.url') }}">MolMeDB RDF article</a> ·
        Vocabulary: <a href="{{ config('fair.rdf.vocabulary_url') }}">mmdbvoc</a>
    </footer>
</main>
</body>
</html>
