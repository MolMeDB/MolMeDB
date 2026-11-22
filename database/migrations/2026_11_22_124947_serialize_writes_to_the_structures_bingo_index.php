<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Bingo index of structures does not survive two sessions writing to it
 * at the same time: the inserts fail with errors such as "bingo buffer:
 * internal error: corrupted block ... data len is -8", and the index stays
 * broken for every later insert until it is rebuilt.
 *
 * A trigger therefore takes a transaction-level advisory lock before any
 * statement that inserts or updates structures, whoever runs it (the upload
 * worker, the administration, a console command, plain SQL), so that only one
 * transaction writes to the index at a time. The index is rebuilt once, to
 * repair what earlier concurrent writes have left in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION structures_bingo_write_lock() RETURNS trigger AS $$
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtext('structures_canonical_smiles_bingo_index'));

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS structures_bingo_write_lock ON structures;

            CREATE TRIGGER structures_bingo_write_lock
                BEFORE INSERT OR UPDATE ON structures
                FOR EACH STATEMENT
                EXECUTE FUNCTION structures_bingo_write_lock();
            SQL);

        $indexExists = DB::scalar(<<<'SQL'
            SELECT EXISTS (
                SELECT 1
                FROM pg_indexes
                WHERE schemaname = current_schema()
                    AND indexname = 'structures_canonical_smiles_bingo_index'
            )
            SQL);

        if ($indexExists) {
            DB::statement('REINDEX INDEX structures_canonical_smiles_bingo_index');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS structures_bingo_write_lock ON structures;
            DROP FUNCTION IF EXISTS structures_bingo_write_lock();
            SQL);
    }
};
