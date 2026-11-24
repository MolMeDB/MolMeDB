<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The column was added by a migration of the default path
 * (2026_06_28_000003_add_finished_notification_sent_at_to_prediction_datasets),
 * which does nothing when the predictions schema does not exist yet and is
 * recorded as run anyway. A predictions database created afterwards by an
 * older init migration, or restored from a dump, therefore lacks it, and the
 * prediction notifications fail. Adding it here, where the predictions
 * schema is migrated, repairs such databases.
 */
return new class extends Migration
{
    protected $connection = 'predictions';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('datasets')
            || Schema::connection($this->connection)->hasColumn('datasets', 'finished_notification_sent_at')) {
            return;
        }

        Schema::connection($this->connection)->table('datasets', function (Blueprint $table) {
            $table->timestamp('finished_notification_sent_at')->nullable();
        });
    }

    /**
     * The column belongs to the init migration; it stays.
     */
    public function down(): void {}
};
