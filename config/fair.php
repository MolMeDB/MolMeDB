<?php

return [

    /*
    |--------------------------------------------------------------------------
    | FAIR metadata
    |--------------------------------------------------------------------------
    |
    | Single source of truth for facts about MolMeDB as a dataset (name,
    | licensing, citation, contact, identifier scheme). Consumed by the
    | public "about" endpoint, JSON-LD mappers and the OpenAPI info block,
    | so these strings only need to live in one place.
    |
    */

    'dataset_name' => 'MolMeDB: Molecules on Membranes Database',

    'dataset_description' => 'A database of molecules, membranes and their '
        .'interactions (permeability, partitioning, binding), curated for '
        .'membrane-transport research.',

    'version' => env('FAIR_DATASET_VERSION', '1.0.0'),

    'contact_email' => 'admin@molmedb.cz',

    'frontend_url' => env('FRONTEND_URL', config('app.frontend_url', 'http://localhost:3000')),

    'repository_url' => 'https://github.com/MolMeDB/MolMeDB',

    'data_license' => [
        'name' => 'CC BY 4.0',
        'url' => 'https://creativecommons.org/licenses/by/4.0/',
        'document' => 'https://github.com/MolMeDB/MolMeDB/blob/main/LICENSE-DATA.md',
    ],

    'code_license' => [
        'name' => 'MIT',
        'url' => 'https://opensource.org/licenses/MIT',
        'document' => 'https://github.com/MolMeDB/MolMeDB/blob/main/LICENSE',
    ],

    'citation' => [
        'doi' => '10.1093/database/baz078',
        'url' => 'https://doi.org/10.1093/database/baz078',
        'text' => 'Juračka J., Šrejber M., Melíková M., Bazgier V., Berka K.: '
            .'MolMeDB: Molecules on Membranes Database. Database, Volume 2019, '
            .'2019, baz078, https://doi.org/10.1093/database/baz078',
    ],

    /*
    |--------------------------------------------------------------------------
    | Persistent identifier scheme
    |--------------------------------------------------------------------------
    |
    | The stable, public accession number minted for every Structure
    | (see App\Libraries\Identifiers). Sub-forms (ionization states) append
    | a dotted numeric suffix, e.g. MM00040.1.
    |
    */

    'identifier_pattern' => '^MM\d{5}(\.\d+)?$',

    'identifier_example' => 'MM00040',

    // Registered on https://registry.identifiers.org — confirm exact
    // namespace slug there before relying on this in generated URIs.
    'identifiers_org_namespace' => env('FAIR_IDENTIFIERS_ORG_NAMESPACE', 'molmedb'),

];
