<?php

/*
 * Filters shared by every interaction listing (see Search*InteractionsRequest).
 */
$rangeFilters = fn (array $columns): array => collect($columns)
    ->flatMap(fn (string $description, string $column): array => [
        "{$column}_min" => ['required' => false, 'example' => '', 'description' => "Minimum {$description}."],
        "{$column}_max" => ['required' => false, 'example' => '', 'description' => "Maximum {$description}."],
    ])
    ->all();

$sharedInteractionFilters = [
    'structure' => ['required' => false, 'example' => '', 'description' => 'Structure identifier, e.g. MM00040.'],
    'publication' => ['required' => false, 'example' => '', 'description' => 'Publication ID, as the primary reference or the reference of the dataset.'],
    'charge' => ['required' => false, 'example' => '', 'description' => 'Charge of the measured form, e.g. 0, 1, -1 ("1" also matches "+1").'],
];

$passiveInteractionFilters = [
    ...$sharedInteractionFilters,
    'membrane' => ['required' => false, 'example' => '', 'description' => 'Membrane ID.'],
    'method' => ['required' => false, 'example' => '', 'description' => 'Method ID.'],
    'with_value' => ['required' => false, 'example' => '', 'description' => 'Only records with this value: logperm, logk, gpen, gwat or x_min.'],
    ...$rangeFilters([
        'temperature' => 'temperature [°C]',
        'ph' => 'pH',
        'logperm' => 'LogPerm [log10 cm/s]',
        'logk' => 'LogK [log10 mol_m/mol_w]',
        'gpen' => 'Gpen [kcal/mol]',
        'gwat' => 'Gwat [kcal/mol]',
        'x_min' => 'Xmin [nm]',
    ]),
    'per_page' => ['required' => false, 'example' => '20', 'description' => 'Results per page (max 100).'],
];

$activeInteractionFilters = [
    ...$sharedInteractionFilters,
    'protein' => ['required' => false, 'example' => '', 'description' => 'Protein ID.'],
    'uniprot' => ['required' => false, 'example' => '', 'description' => 'UniProt ID of the protein, e.g. O15245.'],
    'type' => ['required' => false, 'example' => '', 'description' => 'Interaction type, e.g. Substrate, Inhibitor, Non-substrate, Non-inhibitor.'],
    'with_value' => ['required' => false, 'example' => '', 'description' => 'Only records with this value: km, ec50, ki or ic50.'],
    ...$rangeFilters([
        'temperature' => 'temperature [°C]',
        'ph' => 'pH',
        'km' => 'pKm [-log10 M]',
        'ec50' => 'pEC50 [-log10 M]',
        'ki' => 'pKi [-log10 M]',
        'ic50' => 'pIC50 [-log10 M]',
    ]),
    'per_page' => ['required' => false, 'example' => '20', 'description' => 'Results per page (max 100).'],
];

/**
 * Filters of a listing already narrowed to one entity (its own filter is fixed).
 *
 * @param  array<string, array<string, mixed>>  $filters
 * @return array<string, array<string, mixed>>
 */
$without = fn (array $filters, string ...$keys): array => array_diff_key($filters, array_flip($keys));

return [

    /*
    |--------------------------------------------------------------------------
    | Public API explorer
    |--------------------------------------------------------------------------
    |
    | Metadata used to render the interactive "try it" page for
    | api/v1/* GET routes when a browser (not an API client) requests
    | one of them. Keyed by the route URI with the "api/v1/" prefix
    | stripped, e.g. "membranes/{membrane}/stats".
    |
    | Path parameters (the {membrane} part) don't need per-route metadata —
    | their label/example is looked up from `path_params` below by name.
    |
    */

    'max_response_lines' => 300,

    'path_params' => [
        'membrane' => ['label' => 'Membrane ID', 'example' => '15'],
        'method' => ['label' => 'Method ID', 'example' => '1'],
        'identifier' => ['label' => 'Structure identifier', 'example' => 'MM00040'],
        'publication' => ['label' => 'Publication ID', 'example' => '1262'],
        'protein' => ['label' => 'Protein ID', 'example' => '1'],
        'interaction' => ['label' => 'Interaction ID', 'example' => '1157'],
    ],

    'routes' => [

        'about' => [
            'description' => 'Machine-readable description of MolMeDB as a dataset: license, citation, contact and identifier scheme.',
            'query' => [],
            'example' => '/about',
        ],

        'membranes' => [
            'description' => 'List membranes, optionally filtered by name/category.',
            'query' => [
                'query' => ['required' => false, 'example' => 'POPC', 'description' => 'Free-text search over name/abbreviation.'],
                'category_id' => ['required' => false, 'example' => '', 'description' => 'Restrict to a single category id.'],
                'per_page' => ['required' => false, 'example' => '20', 'description' => 'Results per page (max 100).'],
            ],
            'example' => '/membranes?query=POPC',
        ],
        'membranes/categories' => [
            'description' => 'Full membrane category tree, with the membranes assigned to each category.',
            'query' => [],
            'example' => '/membranes/categories',
        ],
        'membranes/{membrane}' => [
            'description' => 'A single membrane, with the categories it is assigned to.',
            'query' => [],
            'example' => '/membranes/15',
        ],
        'membranes/{membrane}/stats' => [
            'description' => 'Aggregate counts for a membrane (passive interactions, distinct structures).',
            'query' => [],
            'example' => '/membranes/15/stats',
        ],
        'membranes/{membrane}/interactions' => [
            'description' => 'Paginated passive interactions measured on this membrane, with the same filters as /interactions/passive.',
            'query' => $without($passiveInteractionFilters, 'membrane'),
            'example' => '/membranes/15/interactions?with_value=logperm',
        ],
        'membranes/{membrane}/interactions/export' => [
            'description' => 'Downloads a .zip export of every passive interaction measured for this membrane (refreshed daily).',
            'query' => [],
            'example' => '/membranes/15/interactions/export',
            'is_download' => true,
        ],

        'methods' => [
            'description' => 'List methods, optionally filtered by name/category.',
            'query' => [
                'query' => ['required' => false, 'example' => 'COSMO', 'description' => 'Free-text search over name/abbreviation.'],
                'category_id' => ['required' => false, 'example' => '', 'description' => 'Restrict to a single category id.'],
                'per_page' => ['required' => false, 'example' => '20', 'description' => 'Results per page (max 100).'],
            ],
            'example' => '/methods?query=COSMO',
        ],
        'methods/categories' => [
            'description' => 'Full method category tree, with the methods assigned to each category.',
            'query' => [],
            'example' => '/methods/categories',
        ],
        'methods/{method}' => [
            'description' => 'A single method, with the categories it is assigned to.',
            'query' => [],
            'example' => '/methods/1',
        ],
        'methods/{method}/stats' => [
            'description' => 'Aggregate counts for a method (passive/active interactions, distinct structures).',
            'query' => [],
            'example' => '/methods/1/stats',
        ],
        'methods/{method}/interactions' => [
            'description' => 'Paginated passive interactions measured with this method, with the same filters as /interactions/passive.',
            'query' => $without($passiveInteractionFilters, 'method'),
            'example' => '/methods/1/interactions?membrane=3',
        ],
        'methods/{method}/interactions/export' => [
            'description' => 'Downloads a .zip export of every passive interaction measured with this method (refreshed daily).',
            'query' => [],
            'example' => '/methods/1/interactions/export',
            'is_download' => true,
        ],

        'interactions/passive' => [
            'description' => 'Paginated passive interactions (structure on a membrane, by a method) across the whole database, ordered by id. Units of the values are listed in /about.',
            'query' => $passiveInteractionFilters,
            'example' => '/interactions/passive?structure=MM00040&membrane=3&with_value=logk',
        ],
        'interactions/passive/{interaction}' => [
            'description' => 'A single passive interaction.',
            'query' => [],
            'example' => '/interactions/passive/1157',
        ],
        'interactions/active' => [
            'description' => 'Paginated active interactions (structure with a transporter protein) across the whole database, ordered by id. Units of the values are listed in /about.',
            'query' => $activeInteractionFilters,
            'example' => '/interactions/active?uniprot=O15245&type=Substrate',
        ],
        'interactions/active/{interaction}' => [
            'description' => 'A single active interaction.',
            'query' => [],
            'example' => '/interactions/active/31496',
        ],

        'structures' => [
            'description' => 'Search structures by name, exact structure (SMILES), substructure, InChIKey or an external identifier, or fetch up to 100 of them by identifier. Substructure search has its own, stricter rate limit.',
            'query' => [
                'query' => ['required' => false, 'example' => 'caffeine', 'description' => 'Free-text search over name and cross-referenced identifiers.'],
                'smiles' => ['required' => false, 'example' => '', 'description' => 'Exact structure match (chemical structure, not text).'],
                'substructure' => ['required' => false, 'example' => '', 'description' => 'Find structures containing this SMILES as a substructure.'],
                'inchikey' => ['required' => false, 'example' => '', 'description' => 'Exact InChIKey, e.g. RYYVLZVUVIJVGH-UHFFFAOYSA-N.'],
                'pubchem' => ['required' => false, 'example' => '', 'description' => 'PubChem CID, e.g. 2519.'],
                'chembl' => ['required' => false, 'example' => '', 'description' => 'ChEMBL id, e.g. CHEMBL113.'],
                'chebi' => ['required' => false, 'example' => '', 'description' => 'ChEBI id, with or without the prefix, e.g. CHEBI:27732.'],
                'drugbank' => ['required' => false, 'example' => '', 'description' => 'DrugBank id, e.g. DB00201.'],
                'pdb' => ['required' => false, 'example' => '', 'description' => 'PDB ligand (chemical component) code, e.g. CFF.'],
                'identifiers' => ['required' => false, 'example' => '', 'description' => 'Comma-separated MolMeDB identifiers (at most 100), e.g. MM00040,MM00045.'],
                'per_page' => ['required' => false, 'example' => '20', 'description' => 'Results per page (max 100).'],
            ],
            'example' => '/structures?query=caffeine',
        ],
        'structures/{identifier}' => [
            'description' => 'A single structure, with cross-referenced identifiers.',
            'query' => [],
            'example' => '/structures/MM00040',
        ],
        'structures/{identifier}/molfile' => [
            'description' => '3D structure as an MDL molfile (chemical/x-mdl-molfile), generated by RDKit on the first request.',
            'query' => [],
            'example' => '/structures/MM00040/molfile',
            'is_download' => true,
        ],
        'structures/{identifier}/stats' => [
            'description' => 'Aggregate counts for a structure (passive/active interactions).',
            'query' => [],
            'example' => '/structures/MM00040/stats',
        ],
        'structures/{identifier}/interactions/passive' => [
            'description' => 'Paginated passive interactions measured for this structure, with the same filters as /interactions/passive.',
            'query' => $without($passiveInteractionFilters, 'structure'),
            'example' => '/structures/MM00040/interactions/passive',
        ],
        'structures/{identifier}/interactions/active' => [
            'description' => 'Paginated active interactions measured for this structure, with the same filters as /interactions/active.',
            'query' => $without($activeInteractionFilters, 'structure'),
            'example' => '/structures/MM00040/interactions/active',
        ],

        'publications' => [
            'description' => 'Search publications by citation/title/DOI, or look one up by id (prefix with "id:").',
            'query' => [
                'query' => ['required' => false, 'example' => 'caffeine', 'description' => 'Free-text search, or "id:1262" for an exact id lookup.'],
                'per_page' => ['required' => false, 'example' => '20', 'description' => 'Results per page (max 100).'],
            ],
            'example' => '/publications?query=caffeine',
        ],
        'publications/{publication}' => [
            'description' => 'A single publication, with journal details and authors.',
            'query' => [],
            'example' => '/publications/1262',
        ],
        'publications/{publication}/stats' => [
            'description' => 'Aggregate counts for a publication (interactions, membranes, methods, datasets).',
            'query' => [],
            'example' => '/publications/1262/stats',
        ],
        'publications/{publication}/interactions/passive' => [
            'description' => 'Paginated passive interactions with this publication as their primary reference or as the reference of their dataset, with the same filters as /interactions/passive.',
            'query' => $without($passiveInteractionFilters, 'publication'),
            'example' => '/publications/22/interactions/passive',
        ],
        'publications/{publication}/interactions/active' => [
            'description' => 'Paginated active interactions with this publication as their primary reference or as the reference of their dataset, with the same filters as /interactions/active.',
            'query' => $without($activeInteractionFilters, 'publication'),
            'example' => '/publications/1031/interactions/active',
        ],
        'publications/{publication}/interactions/passive/export' => [
            'description' => 'Downloads a .zip export of every passive interaction reported in this publication (refreshed daily).',
            'query' => [],
            'example' => '/publications/1262/interactions/passive/export',
            'is_download' => true,
        ],
        'publications/{publication}/interactions/active/export' => [
            'description' => 'Downloads a .zip export of every active interaction reported in this publication (refreshed daily).',
            'query' => [],
            'example' => '/publications/1262/interactions/active/export',
            'is_download' => true,
        ],

        'proteins' => [
            'description' => 'List proteins, optionally filtered by name/category.',
            'query' => [
                'query' => ['required' => false, 'example' => 'SLC22A2', 'description' => 'Free-text search over UniProt id/alternate names.'],
                'category_id' => ['required' => false, 'example' => '', 'description' => 'Restrict to a single category id.'],
                'per_page' => ['required' => false, 'example' => '20', 'description' => 'Results per page (max 100).'],
            ],
            'example' => '/proteins?query=SLC22A2',
        ],
        'proteins/categories' => [
            'description' => 'Full protein category tree, with the proteins assigned to each category.',
            'query' => [],
            'example' => '/proteins/categories',
        ],
        'proteins/{protein}' => [
            'description' => 'A single protein, with alternate-name identifiers.',
            'query' => [],
            'example' => '/proteins/1',
        ],
        'proteins/{protein}/stats' => [
            'description' => 'Aggregate counts for a protein (active interactions, distinct structures).',
            'query' => [],
            'example' => '/proteins/1/stats',
        ],
        'proteins/{protein}/interactions' => [
            'description' => 'Paginated active interactions measured for this protein, with the same filters as /interactions/active.',
            'query' => $without($activeInteractionFilters, 'protein', 'uniprot'),
            'example' => '/proteins/2/interactions?type=Substrate',
        ],

    ],

];
