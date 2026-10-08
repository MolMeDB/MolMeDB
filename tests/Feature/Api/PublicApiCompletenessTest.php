<?php

require_once __DIR__.'/api_contract_seed.php';

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

/*
 * Every column of a table the public API exposes is either returned (and
 * where) or deliberately left out (and why). A new column fails here until
 * it is decided whether it belongs to the API.
 */

/**
 * Per table: the endpoint showing one of its records, the returned columns
 * with their path in the "data" of the response, and the omitted columns
 * with the reason.
 *
 * @return array<string, array{endpoint: Closure(array): string, exposed: array<string, string>, omitted: array<string, string>}>
 */
function publicApiColumns(): array
{
    $bookkeeping = 'Bookkeeping of the record, not part of the measured data.';
    $deleted = 'Deleted records are not public at all.';

    return [
        'interactions_passive' => [
            'endpoint' => fn ($world) => "/api/v1/interactions/passive/{$world['passive']->id}",
            'exposed' => [
                'id' => 'id', 'structure_id' => 'structure.identifier', 'publication_id' => 'primary_reference.id',
                'temperature' => 'temperature', 'ph' => 'ph', 'charge' => 'charge', 'note' => 'note',
                'x_min' => 'x_min', 'x_min_accuracy' => 'x_min_accuracy', 'gpen' => 'gpen', 'gpen_accuracy' => 'gpen_accuracy',
                'gwat' => 'gwat', 'gwat_accuracy' => 'gwat_accuracy', 'logk' => 'logk', 'logk_accuracy' => 'logk_accuracy',
                'logperm' => 'logperm', 'logperm_accuracy' => 'logperm_accuracy',
            ],
            'omitted' => [
                'dataset_id' => 'Datasets are internal; their membrane, method and publication are returned instead.',
                'created_at' => $bookkeeping, 'updated_at' => $bookkeeping, 'deleted_at' => $deleted,
            ],
        ],
        'interactions_active' => [
            'endpoint' => fn ($world) => "/api/v1/interactions/active/{$world['active']->id}",
            'exposed' => [
                'id' => 'id', 'structure_id' => 'structure.identifier', 'protein_id' => 'protein.id',
                'publication_id' => 'primary_reference.id', 'category_id' => 'type',
                'temperature' => 'temperature', 'ph' => 'ph', 'charge' => 'charge', 'note' => 'note',
                'km' => 'km', 'km_accuracy' => 'km_accuracy', 'ec50' => 'ec50', 'ec50_accuracy' => 'ec50_accuracy',
                'ki' => 'ki', 'ki_accuracy' => 'ki_accuracy', 'ic50' => 'ic50', 'ic50_accuracy' => 'ic50_accuracy',
            ],
            'omitted' => [
                'dataset_id' => 'Datasets are internal; their publication is returned instead.',
                'created_at' => $bookkeeping, 'updated_at' => $bookkeeping, 'deleted_at' => $deleted,
            ],
        ],
        'structures' => [
            'endpoint' => fn () => '/api/v1/structures/MM00040.1',
            'exposed' => [
                'identifier' => 'identifier', 'parent_id' => 'parent.identifier', 'canonical_smiles' => 'canonical_smiles',
                'charge' => 'charge', 'inchi' => 'inchi', 'inchikey' => 'inchikey', 'molecular_weight' => 'molecular_weight',
                'logp' => 'logp', 'molfile_3d' => 'molfile_url', 'created_at' => 'created_at', 'updated_at' => 'updated_at',
            ],
            'omitted' => [
                'id' => 'Internal key; structures are identified by their public identifier.',
                'ph_start' => 'Not filled for any structure.',
                'ph_end' => 'Not filled for any structure.',
                'user_id' => 'Who added the structure is personal data.',
                'deleted_at' => $deleted,
            ],
        ],
        'identifiers' => [
            'endpoint' => fn () => '/api/v1/structures/MM00040',
            // The state decides which identifiers are listed and whether they are "verified".
            'exposed' => ['value' => 'identifiers.0.value', 'type' => 'identifiers.0.type', 'state' => 'identifiers'],
            'omitted' => [
                'id' => 'Internal key.',
                'structure_id' => 'Returned within its structure.',
                'source_id' => 'Provenance of the identifier (a user or another identifier) is internal.',
                'source_type' => 'Provenance of the identifier (a user or another identifier) is internal.',
                'logs' => 'Internal validation log.',
                'created_at' => $bookkeeping, 'updated_at' => $bookkeeping, 'deleted_at' => $deleted,
            ],
        ],
        'datasets' => [
            'endpoint' => fn ($world) => "/api/v1/interactions/passive/{$world['passive']->id}",
            'exposed' => ['membrane_id' => 'membrane.id', 'method_id' => 'method.id'],
            'omitted' => [
                'id' => 'Datasets are not public.',
                'type' => 'Datasets are not public.',
                'name' => 'Internal name of the dataset.',
                'comment' => 'Internal comment of the dataset.',
                'dataset_group_id' => 'Dataset groups are internal.',
                'created_by' => 'Who uploaded the data is personal data.',
                'created_at' => $bookkeeping, 'updated_at' => $bookkeeping, 'deleted_at' => $deleted,
            ],
        ],
        'publications' => [
            'endpoint' => fn ($world) => "/api/v1/publications/{$world['publication']->id}",
            'exposed' => [
                'id' => 'id', 'citation' => 'citation', 'doi' => 'doi', 'identifier' => 'pmid', 'identifier_source' => 'pmid',
                'title' => 'title', 'journal' => 'journal', 'volume' => 'volume', 'issue' => 'issue', 'page' => 'page',
                'year' => 'year', 'created_at' => 'created_at', 'updated_at' => 'updated_at',
            ],
            'omitted' => [
                'type' => 'Internal kind of the publication record.',
                'published_at' => 'Filled for almost no publication; the year is returned.',
                'validated_at' => 'Internal state of the reference check.',
                'deleted_at' => $deleted,
            ],
        ],
        'authors' => [
            'endpoint' => fn ($world) => "/api/v1/publications/{$world['publication']->id}",
            'exposed' => ['first_name' => 'authors.0.first_name', 'last_name' => 'authors.0.last_name', 'full_name' => 'authors.0.full_name'],
            'omitted' => [
                'id' => 'Internal key.',
                'email' => 'E-mail addresses are personal data.',
                'affiliation' => 'Filled for almost no author.',
            ],
        ],
        'proteins' => [
            'endpoint' => fn ($world) => "/api/v1/proteins/{$world['protein']->id}",
            'exposed' => ['id' => 'id', 'uniprot_id' => 'uniprot_id', 'created_at' => 'created_at', 'updated_at' => 'updated_at'],
            'omitted' => ['deleted_at' => $deleted],
        ],
        'membranes' => [
            'endpoint' => fn ($world) => "/api/v1/membranes/{$world['membrane']->id}",
            'exposed' => [
                'id' => 'id', 'name' => 'name', 'abbreviation' => 'abbreviation', 'description' => 'description',
                'created_at' => 'created_at', 'updated_at' => 'updated_at',
            ],
            'omitted' => ['type' => 'Internal origin of the record.', 'deleted_at' => $deleted],
        ],
        'methods' => [
            'endpoint' => fn ($world) => "/api/v1/methods/{$world['method']->id}",
            'exposed' => [
                'id' => 'id', 'name' => 'name', 'abbreviation' => 'abbreviation', 'description' => 'description',
                'created_at' => 'created_at', 'updated_at' => 'updated_at',
            ],
            'omitted' => [
                'type' => 'Internal origin of the record.',
                'parameters' => 'Internal settings of computational methods, not filled for any method.',
                'deleted_at' => $deleted,
            ],
        ],
    ];
}

test('every column of an exposed table is returned or deliberately left out', function (string $table) {
    $columns = publicApiColumns()[$table];
    $documented = [...array_keys($columns['exposed']), ...array_keys($columns['omitted'])];

    expect(array_values(array_diff(Schema::getColumnListing($table), $documented)))
        ->toBe([], "Columns of {$table} neither exposed nor omitted in publicApiColumns()")
        ->and(array_values(array_diff($documented, Schema::getColumnListing($table))))
        ->toBe([], "Columns in publicApiColumns() that {$table} does not have");
})->with(array_keys(publicApiColumns()));

test('the exposed columns are in the response', function (string $table) {
    $world = seedApiContractWorld();
    $columns = publicApiColumns()[$table];

    $data = $this->getJson($columns['endpoint']($world))->assertOk()->json('data');

    foreach ($columns['exposed'] as $column => $path) {
        expect(Arr::has($data, $path))->toBeTrue("{$table}.{$column} is not returned as {$path}");
    }
})->with(array_keys(publicApiColumns()));
