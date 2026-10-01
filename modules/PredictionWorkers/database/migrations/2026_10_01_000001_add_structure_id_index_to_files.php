<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * COSMO conformer files (type 4) are stored as {structure_id}/{configuration}/{name}.ccf.
 * The weekly COSMO export loads them per batch of structures by the first path segment
 * (not a partial index: prepared statements may use a generic plan that cannot match it).
 */
return new class extends Migration
{
    protected $connection = 'predictions';

    public function up(): void
    {
        DB::connection($this->connection)->statement(
            "CREATE INDEX IF NOT EXISTS files_structure_id_index ON files ((split_part(path, '/', 1)))"
        );
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP INDEX IF EXISTS files_structure_id_index');
    }
};
