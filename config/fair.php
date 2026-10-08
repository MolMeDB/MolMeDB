<?php

return [

    /*
    |--------------------------------------------------------------------------
    | FAIR metadata
    |--------------------------------------------------------------------------
    |
    | Single source of truth for facts about MolMeDB as a dataset (name,
    | licensing, citation, authors, funding, contact, identifier scheme).
    | Consumed by the public "about" endpoint, JSON-LD mappers, exports and
    | the OpenAPI info block, so these strings only need to live in one place.
    |
    */

    'dataset_name' => 'MolMeDB: Molecules on Membranes Database',

    'dataset_description' => 'A database of molecules, membranes and their '
        .'interactions (permeability, partitioning, binding), curated for '
        .'membrane-transport research.',

    'keywords' => [
        'membrane',
        'biological membranes',
        'membrane permeability',
        'membrane partitioning',
        'small molecules',
        'transporter proteins',
        'drug discovery',
        'cheminformatics',
    ],

    'version' => env('FAIR_DATASET_VERSION', '1.0.0'),

    'contact_email' => 'molmedb@upol.cz',

    /*
    | Canonical public addresses. Persistent identifiers and metadata always use
    | these, whichever host a request came through (or none, in console commands).
    */

    'frontend_url' => rtrim(env('FAIR_SITE_URL', 'https://molmedb.upol.cz'), '/'),

    'public_api_url' => rtrim(env('FAIR_PUBLIC_API_URL', rtrim(env('FAIR_SITE_URL', 'https://molmedb.upol.cz'), '/').'/api/v1'), '/'),

    'sitemap_path' => env('FAIR_SITEMAP_PATH', storage_path('app/sitemaps')),

    'repository_url' => 'https://github.com/MolMeDB/MolMeDB',

    'publisher' => [
        'name' => 'Palacký University Olomouc, Faculty of Science, Department of Physical Chemistry',
        'url' => 'https://www.upol.cz/en/',
        'address' => 'tř. 17. listopadu 1192/12, 771 46 Olomouc, Czech Republic',
    ],

    'data_license' => [
        'name' => 'CC0 1.0',
        'spdx' => 'CC0-1.0',
        'url' => 'https://creativecommons.org/publicdomain/zero/1.0/',
        'document' => 'https://github.com/MolMeDB/MolMeDB/blob/main/LICENSE-DATA.md',
    ],

    'code_license' => [
        'name' => 'MIT',
        'spdx' => 'MIT',
        'url' => 'https://opensource.org/licenses/MIT',
        'document' => 'https://github.com/MolMeDB/MolMeDB/blob/main/LICENSE',
    ],

    /*
    | Authors of the dataset. `published_as` is the family name used in the
    | preferred citation, when it differs from the current one.
    */

    'authors' => [
        ['given_name' => 'Jakub', 'family_name' => 'Juračka', 'orcid' => '0000-0003-2518-075X'],
        ['given_name' => 'Martin', 'family_name' => 'Šrejber', 'orcid' => '0000-0001-9556-2978'],
        ['given_name' => 'Michaela', 'family_name' => 'Jaroměřská', 'published_as' => 'Melíková', 'orcid' => '0000-0001-8658-6169'],
        ['given_name' => 'Václav', 'family_name' => 'Bazgier', 'orcid' => '0000-0003-3393-3010'],
        ['given_name' => 'Dominik', 'family_name' => 'Martinát', 'orcid' => '0000-0001-6611-7883'],
        ['given_name' => 'Jakub', 'family_name' => 'Galgonek', 'orcid' => '0000-0002-7038-544X'],
        ['given_name' => 'Karel', 'family_name' => 'Berka', 'orcid' => '0000-0001-9472-2589'],
    ],

    'citation' => [
        'doi' => '10.1093/database/baz078',
        'url' => 'https://doi.org/10.1093/database/baz078',
        'text' => 'Juračka J., Šrejber M., Melíková M., Bazgier V., Berka K.: '
            .'MolMeDB: Molecules on Membranes Database. Database, Volume 2019, '
            .'2019, baz078, https://doi.org/10.1093/database/baz078',
    ],

    /*
    | Further peer-reviewed articles describing parts of MolMeDB.
    */

    'related_publications' => [
        [
            'title' => 'MolMeDB RDF: transforming a relational database about biological membranes to the RDF format to increase interoperability',
            'doi' => '10.1186/s13321-026-01208-3',
            'url' => 'https://doi.org/10.1186/s13321-026-01208-3',
            'journal' => 'Journal of Cheminformatics',
            'year' => 2026,
            'authors' => ['Martinát D.', 'Juračka J.', 'Galgonek J.', 'Berka K.'],
        ],
    ],

    /*
    | Funding, newest first. `identifier` is the grant number, when it has one.
    */

    'funding' => [
        ['name' => 'MolMeDB interoperability with UniProt and SwissLipids', 'funder' => 'Ministry of Education, Youth and Sports of the Czech Republic (Czech-Swiss Research Infrastructure Initiative, ELIXIR-IMPACT)', 'identifier' => '8K0208', 'period' => '2026–2028'],
        ['name' => 'Database curation', 'funder' => 'Palacký University Olomouc (Internal Grant Agency)', 'identifier' => 'IGA_PrF_2026_002', 'period' => '2026'],
        ['name' => 'Database curation', 'funder' => 'Palacký University Olomouc (Internal Grant Agency)', 'identifier' => 'IGA_PrF_2025_003', 'period' => '2025'],
        ['name' => 'MembOn – Membrane Ontology for Integration of Data-related Web Services', 'funder' => 'ELIXIR (Staff Exchange Programme)', 'period' => '2024'],
        ['name' => 'Database curation', 'funder' => 'Palacký University Olomouc (Internal Grant Agency)', 'identifier' => 'IGA_PrF_2024_017', 'period' => '2024'],
        ['name' => 'MolMeDB support by the ELIXIR CZ infrastructure', 'funder' => 'Ministry of Education, Youth and Sports of the Czech Republic', 'identifier' => 'LM2023055', 'period' => '2023–2026'],
        ['name' => 'MolMeDB interoperability update – RDF model draft', 'funder' => 'ELIXIR CZ (internal project)', 'period' => '2023'],
        ['name' => 'Database curation', 'funder' => 'Palacký University Olomouc (Internal Grant Agency)', 'identifier' => 'IGA_PrF_2023_018', 'period' => '2023'],
        ['name' => 'FunGIM – Effects of Functional Groups on Interactions with Membranes', 'funder' => 'Palacký University Olomouc (Doctoral Student Grant Competition, OP RDE project CZ.02.2.69/0.0/0.0/19_073/0016713)', 'identifier' => 'DSGC-2021-0060', 'period' => '2022'],
        ['name' => 'MolMeDB support by the ELIXIR CZ infrastructure', 'funder' => 'Ministry of Education, Youth and Sports of the Czech Republic', 'identifier' => 'LM2018131', 'period' => '2020–2022'],
        ['name' => 'Database curation', 'funder' => 'Palacký University Olomouc (Internal Grant Agency)', 'identifier' => 'IGA_PrF_2019_031', 'period' => '2019'],
        ['name' => 'MolMeDB establishment', 'funder' => 'Czech Science Foundation (GAČR)', 'identifier' => '17-21122S', 'period' => '2017–2020'],
    ],

    /*
    | RDF representation of the dataset (see the MolMeDB RDF article).
    */

    'rdf' => [
        'base_url' => 'https://rdf.molmedb.upol.cz',
        'vocabulary_url' => 'https://rdf.molmedb.upol.cz/vocabulary',
        'sparql_endpoint' => env('FAIR_SPARQL_ENDPOINT', 'https://idsm.elixir-czech.cz/sparql/endpoint/molmedb'),
        'dump_doi' => '10.5281/zenodo.18632779',
        'dump_url' => 'https://doi.org/10.5281/zenodo.18632779',
    ],

    /*
    |--------------------------------------------------------------------------
    | Persistent identifier scheme
    |--------------------------------------------------------------------------
    |
    | The stable, public accession number minted for every Structure
    | (see App\Libraries\Identifiers). Sub-forms (ionization states) append
    | a dotted numeric suffix, e.g. MM00040.1. Registered on identifiers.org
    | as the "molmedb" namespace, resolving to {frontend_url}/mol/{id}.
    |
    */

    'identifier_pattern' => '^MM\d{5,}(\.\d+)?$',

    'identifier_example' => 'MM00040',

    'identifiers_org_namespace' => 'molmedb',

];
