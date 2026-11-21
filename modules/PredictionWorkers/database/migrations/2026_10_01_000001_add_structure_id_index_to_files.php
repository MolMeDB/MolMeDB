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
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        DB::connection($this->connection)->statement(
            "CREATE INDEX IF NOT EXISTS files_structure_id_index ON files ((split_part(path, '/', 1)))"
        );
    }

    public function down(): void
    {
        if (DB::connection($this->connection)->getDriverName() === 'pgsql') {
            DB::connection($this->connection)->statement('DROP INDEX IF EXISTS files_structure_id_index');
        }
    }
};
