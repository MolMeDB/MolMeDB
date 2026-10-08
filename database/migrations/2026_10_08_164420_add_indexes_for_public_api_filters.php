<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unlike MySQL/InnoDB, PostgreSQL does not index the referencing column of a
 * foreign key, so every lookup of the interactions of one structure, protein
 * or dataset was a sequential scan over the whole table. Adds the indexes the
 * public API filters (and the existing frontend lookups) rely on.
 */
return new class extends Migration
{
    /**
     * Indexed columns per table; an inner array is one composite index.
     *
     * @var array<string, array<int, string|array<int, string>>>
     */
    private array $indexes = [
        'interactions_passive' => ['dataset_id', 'structure_id', 'publication_id'],
        'interactions_active' => ['dataset_id', 'structure_id', 'protein_id', 'publication_id', 'category_id'],
        'datasets' => ['membrane_id', 'method_id'],
        'structures' => ['parent_id', 'inchikey'],
        'identifiers' => ['structure_id', ['type', 'value']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes): void {
                foreach ($indexes as $columns) {
                    $blueprint->index($columns);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes): void {
                foreach ($indexes as $columns) {
                    $blueprint->dropIndex((array) $columns);
                }
            });
        }
    }
};
