<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public API explorer
    |--------------------------------------------------------------------------
    |
    | Metadata used to render the interactive "try it" page for
    | api/public/v1/* GET routes when a browser (not an API client) requests
    | one of them. Keyed by the route URI with the "api/public/v1/" prefix
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
            'description' => 'Downloads a .zip export of every passive interaction measured for this membrane (refreshed daily).',
            'query' => [],
            'example' => '/membranes/15/interactions',
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
            'description' => 'Downloads a .zip export of every passive interaction measured with this method (refreshed daily).',
            'query' => [],
            'example' => '/methods/1/interactions',
            'is_download' => true,
        ],

        'structures' => [
            'description' => 'Search structures by name, exact structure (SMILES) or substructure. Substructure search has its own, stricter rate limit.',
            'query' => [
                'query' => ['required' => false, 'example' => 'caffeine', 'description' => 'Free-text search over name and cross-referenced identifiers.'],
                'smiles' => ['required' => false, 'example' => '', 'description' => 'Exact structure match (chemical structure, not text).'],
                'substructure' => ['required' => false, 'example' => '', 'description' => 'Find structures containing this SMILES as a substructure.'],
                'per_page' => ['required' => false, 'example' => '20', 'description' => 'Results per page (max 100).'],
            ],
            'example' => '/structures?query=caffeine',
        ],
        'structures/{identifier}' => [
            'description' => 'A single structure, with cross-referenced identifiers.',
            'query' => [],
            'example' => '/structures/MM00040',
        ],
        'structures/{identifier}/stats' => [
            'description' => 'Aggregate counts for a structure (passive/active interactions).',
            'query' => [],
            'example' => '/structures/MM00040/stats',
        ],
        'structures/{identifier}/interactions/passive' => [
            'description' => 'Paginated passive interactions measured for this structure.',
            'query' => [
                'per_page' => ['required' => false, 'example' => '20', 'description' => 'Results per page (max 100).'],
            ],
            'example' => '/structures/MM00040/interactions/passive',
        ],
        'structures/{identifier}/interactions/active' => [
            'description' => 'Paginated active interactions measured for this structure.',
            'query' => [
                'per_page' => ['required' => false, 'example' => '20', 'description' => 'Results per page (max 100).'],
            ],
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
            'description' => 'Downloads a .zip export of every passive interaction reported in this publication (refreshed daily).',
            'query' => [],
            'example' => '/publications/1262/interactions/passive',
            'is_download' => true,
        ],
        'publications/{publication}/interactions/active' => [
            'description' => 'Downloads a .zip export of every active interaction reported in this publication (refreshed daily).',
            'query' => [],
            'example' => '/publications/1262/interactions/active',
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
            'description' => 'Paginated active interactions measured for this protein.',
            'query' => [
                'per_page' => ['required' => false, 'example' => '20', 'description' => 'Results per page (max 100).'],
            ],
            'example' => '/proteins/1/interactions',
        ],

    ],

];
