<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Assigning methods to a category asks PostgreSQL for distinct methods, and
 * PostgreSQL cannot compare rows holding a json column ("could not identify
 * an equality operator for type json"). jsonb can be compared.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE methods ALTER COLUMN parameters TYPE jsonb USING parameters::jsonb');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE methods ALTER COLUMN parameters TYPE json USING parameters::json');
    }
};
